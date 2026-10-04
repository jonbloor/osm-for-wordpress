<?php

class OSM_Admin {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_page' ] );
        add_action( 'admin_post_osm_save_auth', [ $this, 'save_auth' ] );
        add_action( 'admin_post_osm_oauth_callback', [ $this, 'oauth_callback' ] );
        add_action( 'admin_post_osm_clear_api_block', [ $this, 'clear_api_block' ] );
        add_action( 'admin_post_osm_save_sections', [ $this, 'save_sections' ] );
        add_action( 'admin_post_osm_purge_cache', [ $this, 'purge_cache' ] );
        add_action( 'admin_post_osm_reset_configuration', [ $this, 'reset_configuration' ] );
        add_action( 'admin_post_osm_save_advanced_options', [ $this, 'save_advanced_options' ] );
        add_action( 'admin_post_osm_save_waiting_list', [ $this, 'save_waiting_list' ] );
        add_action( 'admin_notices', [ $this, 'display_admin_notices' ] );
    }

    public function add_admin_page() {
        add_menu_page( 'OSM Settings', 'OSM Settings', 'manage_options', 'osm-for-wordpress', [ $this, 'render_admin_page' ], 'dashicons-admin-tools' );
    }

    public function render_admin_page() {
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
        $client_id = get_option( 'osm_client_id', '' );
        $has_client_id = is_string( $client_id ) && $client_id !== '';
        $has_client_secret = (bool) get_option( 'osm_client_secret' );
        $enabled_sections = get_option( 'osm_enabled_sections', [] );
        $advanced_options = [
            'osm_date_format' => OSM_Options::get_date_format() ?? '',
            'osm_time_format' => OSM_Options::get_time_format() ?? '',
        ];
        $waiting_list_section_id = get_option( 'osm_waiting_list_section_id', '' );
        $auth_mode = get_option( 'osm_auth_mode', 'authorization_code' );
        if ( ! in_array( $auth_mode, [ 'authorization_code', 'client_credentials' ], true ) ) {
            $auth_mode = 'authorization_code';
        }
        $oauth_redirect_url = OSM_API::redirect_uri();
        $token_expires_at = (int) get_option( 'osm_token_expires_at', 0 );
        $has_refresh_token = (bool) get_option( 'osm_refresh_token' );
        $has_access_token = (bool) get_option( 'osm_access_token' );
        $osm_connected = $auth_mode === 'client_credentials'
            ? ( $has_access_token && $token_expires_at > time() )
            : ( $has_refresh_token || ( $has_access_token && $token_expires_at > time() ) );
        $api_blocked = get_option( OSM_API::OPTION_BLOCKED );
        $api_deprecated = get_option( OSM_API::OPTION_DEPRECATED );
        $api_removed = get_option( OSM_API::OPTION_REMOVED, [] );
        $captcha_mode = get_option( 'osm_waiting_list_captcha', 'off' );
        if ( ! in_array( $captcha_mode, [ 'off', 'recaptcha', 'turnstile' ], true ) ) {
            $captcha_mode = 'off';
        }
        $recaptcha_site_key = (string) get_option( 'osm_recaptcha_site_key', '' );
        $turnstile_site_key = (string) get_option( 'osm_turnstile_site_key', '' );
        $has_recaptcha_secret = (bool) get_option( 'osm_recaptcha_secret_key' );
        $has_turnstile_secret = (bool) get_option( 'osm_turnstile_secret_key' );

        include OSM_TEMPLATES_DIR . '/admin/settings.php';
    }

    public function save_auth() {
        $this->require_admin( 'osm_auth_nonce' );

        $mode = isset( $_POST['osm_auth_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['osm_auth_mode'] ) ) : 'authorization_code';
        if ( ! in_array( $mode, [ 'authorization_code', 'client_credentials' ], true ) ) {
            $mode = 'authorization_code';
        }

        $previous = get_option( 'osm_auth_mode', 'authorization_code' );
        update_option( 'osm_auth_mode', $mode );
        if ( $previous !== $mode ) {
            $this->delete_stored_tokens();
        }

        $client_id = isset( $_POST['osm_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['osm_client_id'] ) ) : '';
        $client_secret = isset( $_POST['osm_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['osm_client_secret'] ) ) : '';

        if ( $client_id !== '' ) {
            update_option( 'osm_client_id', $client_id );
        }
        // A blank password field must not wipe a stored client secret.
        if ( $client_secret !== '' ) {
            update_option( 'osm_client_secret', $client_secret, false );
        }

        $action = isset( $_POST['osm_auth_action'] ) ? sanitize_text_field( wp_unslash( $_POST['osm_auth_action'] ) ) : 'save';

        if ( $action === 'connect' ) {
            if ( $mode !== 'authorization_code' ) {
                set_transient( 'osm_admin_notice', [ 'type' => 'error', 'message' => 'Connect with OSM uses the authorization code flow. Select that mode, or use Save & Authenticate for client credentials.' ], 30 );
                $this->redirect_auth();
            }
            try {
                $url = OSM_API::build_authorize_url();
                wp_redirect( $url );
                exit;
            } catch ( Exception $e ) {
                set_transient( 'osm_admin_notice', [ 'type' => 'error', 'message' => 'Could not start OSM authorisation: ' . $e->getMessage() ], 30 );
                $this->redirect_auth();
            }
        }

        if ( $action === 'client_credentials' ) {
            if ( $mode !== 'client_credentials' ) {
                set_transient( 'osm_admin_notice', [ 'type' => 'error', 'message' => 'Select Client credentials grant before using Save & Authenticate.' ], 30 );
                $this->redirect_auth();
            }
            try {
                OSM_API::authorize( true );
                delete_option( 'osm_enabled_sections' );
                set_transient( 'osm_admin_notice', [ 'type' => 'success', 'message' => 'Client credentials saved and verified.' ], 30 );
            } catch ( Exception $e ) {
                // Keep the stored client ID and secret so the admin can correct OSM and retry.
                set_transient( 'osm_admin_notice', [ 'type' => 'error', 'message' => 'Failed to authenticate: ' . $e->getMessage() ], 30 );
            }
            $this->redirect_auth();
        }

        set_transient( 'osm_admin_notice', [ 'type' => 'success', 'message' => 'Authentication settings saved.' ], 30 );
        $this->redirect_auth();
    }

    /**
     * OSM redirects the browser here. Logged-in administrators only.
     */
    public function oauth_callback() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html( 'You must be logged in as an administrator to finish connecting OSM.' ) );
        }

        $error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
        $error_description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';
        if ( $error !== '' ) {
            $message = 'OSM authorisation failed: ' . $error;
            if ( $error_description !== '' ) {
                $message .= ' — ' . $error_description;
            }
            set_transient( 'osm_admin_notice', [ 'type' => 'error', 'message' => $message ], 30 );
            $this->redirect_auth();
        }

        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

        try {
            OSM_API::exchange_auth_code( $code, $state );
            delete_option( 'osm_enabled_sections' );
            set_transient( 'osm_admin_notice', [ 'type' => 'success', 'message' => 'Connected to OSM with authorization code.' ], 30 );
        } catch ( Exception $e ) {
            set_transient( 'osm_admin_notice', [ 'type' => 'error', 'message' => 'Failed to authenticate: ' . $e->getMessage() ], 30 );
        }

        $this->redirect_auth();
    }

    public function clear_api_block() {
        $this->require_admin( 'osm_clear_api_block' );
        delete_option( OSM_API::OPTION_BLOCKED );
        set_transient( 'osm_admin_notice', [ 'type' => 'success', 'message' => 'OSM API block cleared. Requests are allowed again. Fix invalid data before submitting, or OSM may block the application permanently.' ], 30 );
        $this->redirect_auth();
    }

    public function save_sections() {
        check_admin_referer( 'osm_sections_nonce' );

        if ( OSM_API::blocked_flag_is_set( get_option( OSM_API::OPTION_BLOCKED ) ) ) {
            set_transient( 'osm_admin_notice', [ 'type' => 'error', 'message' => 'OSM requests are stopped (X-Blocked). Clear the block before saving sections.' ], 30 );
            wp_redirect( admin_url( 'admin.php?page=osm-for-wordpress&tab=sections' ) );
            exit;
        }

        $enabled_sections = array_map( 'sanitize_text_field', $_POST['osm_enabled_sections'] ?? [] );

        // Cache current term for each section
        foreach ( $enabled_sections as $sectionid => $value ) {
            OSM_API::get_current_term( $sectionid );
        }

        update_option( 'osm_enabled_sections', $enabled_sections );

        set_transient( 'osm_admin_notice', [ 'type' => 'success', 'message' => 'Sections updated successfully.' ], 10 );

        wp_redirect( admin_url( 'admin.php?page=osm-for-wordpress' ) );
        exit;
    }

    public function save_advanced_options() {
        check_admin_referer( 'osm_advanced_options_nonce' );

        $date_format = sanitize_text_field( wp_unslash( $_POST['osm_date_format'] ?? '' ) );
        $time_format = sanitize_text_field( wp_unslash( $_POST['osm_time_format'] ?? '' ) );

        try {
            OSM_Options::set_date_format( $date_format );
            OSM_Options::set_time_format( $time_format );

            set_transient( 'osm_admin_notice', [ 'type' => 'success', 'message' => 'Advanced options saved successfully.' ], 10 );
    
            wp_redirect( admin_url( 'admin.php?page=osm-for-wordpress&tab=advanced_options' ) );
            exit;
        } catch ( Exception $e ) {
            set_transient( 'osm_admin_notice', [ 'type' => 'error', 'message' => 'Failed to save advanced options: ' . $e->getMessage() ], 10 );
            wp_redirect( admin_url( 'admin.php?page=osm-for-wordpress&tab=advanced_options' ) );
            exit;
        }
    }

    public function purge_cache() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'osm_cached_%'" );

        set_transient( 'osm_admin_notice', [ 'type' => 'success', 'message' => 'Cache purged successfully.' ], 10 );

        wp_redirect( admin_url( 'admin.php?page=osm-for-wordpress' ) );
        exit;
    }

    public function reset_configuration() {
        check_admin_referer( 'osm_reset_nonce' );

        $options = [
            'osm_client_id',
            'osm_client_secret',
            'osm_enabled_sections',
            'osm_waiting_list_section_id',
            'osm_auth_mode',
            'osm_access_token',
            'osm_refresh_token',
            'osm_token_expires_at',
            'osm_api_blocked',
            'osm_api_deprecated',
            'osm_api_removed_endpoints',
            'osm_api_rate_limit',
            'osm_waiting_list_captcha',
            'osm_recaptcha_site_key',
            'osm_recaptcha_secret_key',
            'osm_turnstile_site_key',
            'osm_turnstile_secret_key',
        ];
        foreach ( $options as $option ) {
            delete_option( $option );
        }

        // Delete cached current term options
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'osm_current_term_%'" );

        set_transient( 'osm_admin_notice', [ 'type' => 'success', 'message' => 'Configuration reset successfully.' ], 10 );

        wp_redirect( admin_url( 'admin.php?page=osm-for-wordpress' ) );
        exit;
    }


    public function save_waiting_list() {
        check_admin_referer( 'osm_waiting_list_nonce' );

        $section_id = sanitize_text_field( wp_unslash( $_POST['osm_waiting_list_section_id'] ?? '' ) );
        $section_message = '';
        $section_ok = true;

        if ( $section_id === '' ) {
            delete_option( 'osm_waiting_list_section_id' );
            $section_message = 'Waiting list section cleared.';
        } elseif ( ! ctype_digit( $section_id ) ) {
            $section_ok = false;
            $section_message = 'Waiting list section ID must be a number.';
        } else {
            update_option( 'osm_waiting_list_section_id', $section_id );
            $section_message = 'Waiting list section saved.';
        }

        $captcha = sanitize_text_field( wp_unslash( $_POST['osm_waiting_list_captcha'] ?? 'off' ) );
        if ( ! in_array( $captcha, [ 'off', 'recaptcha', 'turnstile' ], true ) ) {
            $captcha = 'off';
        }
        update_option( 'osm_waiting_list_captcha', $captcha );

        $this->update_visible_option( 'osm_recaptcha_site_key', wp_unslash( $_POST['osm_recaptcha_site_key'] ?? '' ) );
        $this->update_visible_option( 'osm_turnstile_site_key', wp_unslash( $_POST['osm_turnstile_site_key'] ?? '' ) );
        $this->update_secret_option( 'osm_recaptcha_secret_key', wp_unslash( $_POST['osm_recaptcha_secret_key'] ?? '' ) );
        $this->update_secret_option( 'osm_turnstile_secret_key', wp_unslash( $_POST['osm_turnstile_secret_key'] ?? '' ) );

        $message = $section_message . ' Spam protection settings saved.';
        set_transient( 'osm_admin_notice', [ 'type' => $section_ok ? 'success' : 'error', 'message' => $message ], 10 );

        wp_redirect( admin_url( 'admin.php?page=osm-for-wordpress&tab=waiting_list' ) );
        exit;
    }

    public function display_admin_notices() {
        if ( $notice = get_transient( 'osm_admin_notice' ) ) {
            $class = $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
            printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $notice['message'] ) );
            delete_transient( 'osm_admin_notice' );
        }

        if ( OSM_API::blocked_flag_is_set( get_option( OSM_API::OPTION_BLOCKED ) ) ) {
            echo '<div class="notice notice-error"><p><strong>OSM has blocked this application (X-Blocked).</strong> All OSM requests are stopped. Clear the block under OSM Settings → Authentication only after you have fixed the cause. Continuing to send invalid data after a block can become permanent.</p></div>';
        }

        $deprecated = get_option( OSM_API::OPTION_DEPRECATED );
        if ( is_array( $deprecated ) && ! empty( $deprecated['date'] ) ) {
            $path = isset( $deprecated['path'] ) ? (string) $deprecated['path'] : '';
            echo '<div class="notice notice-warning"><p><strong>OSM X-Deprecated:</strong> ' . esc_html( (string) $deprecated['date'] );
            if ( $path !== '' ) {
                echo ' for <code>' . esc_html( $path ) . '</code>';
            }
            echo '. Do not keep calling an endpoint after its removal date.</p></div>';
        }
    }

    /**
     * @param string $nonce_action Nonce action.
     * @return void
     */
    private function require_admin( $nonce_action ) {
        check_admin_referer( $nonce_action );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html( 'You do not have permission to manage OSM settings.' ) );
        }
    }

    /**
     * @return void
     */
    private function redirect_auth() {
        wp_redirect( admin_url( 'admin.php?page=osm-for-wordpress&tab=authentication' ) );
        exit;
    }

    /**
     * @return void
     */
    private function delete_stored_tokens() {
        delete_option( 'osm_access_token' );
        delete_option( 'osm_refresh_token' );
        delete_option( 'osm_token_expires_at' );
    }

    /**
     * Blank text clears a non-secret setting (site keys are shown in the form).
     *
     * @param string $option Option name.
     * @param string $value  Posted value.
     * @return void
     */
    private function update_visible_option( $option, $value ) {
        $value = sanitize_text_field( (string) $value );
        if ( $value === '' ) {
            delete_option( $option );
            return;
        }
        update_option( $option, $value );
    }

    /**
     * Blank password keeps the stored secret.
     *
     * @param string $option Option name.
     * @param string $value  Posted value.
     * @return void
     */
    private function update_secret_option( $option, $value ) {
        $value = sanitize_text_field( (string) $value );
        if ( $value === '' ) {
            return;
        }
        update_option( $option, $value, false );
    }
}
