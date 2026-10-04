<?php

class OSM_Admin {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_page' ] );
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
        $enabled_sections = get_option( 'osm_enabled_sections', [] );
        $advanced_options = [
            'osm_date_format' => OSM_Options::get_date_format() ?? '',
            'osm_time_format' => OSM_Options::get_time_format() ?? '',
        ];
        $helper_base_url = (string) get_option( 'osm_helper_base_url', OSM_Helper_Client::DEFAULT_BASE_URL );
        if ( $helper_base_url === '' ) {
            $helper_base_url = OSM_Helper_Client::DEFAULT_BASE_URL;
        }
        $has_helper_site_key = (bool) get_option( 'osm_helper_site_key' );
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

    public function clear_api_block() {
        $this->require_admin( 'osm_clear_api_block' );
        delete_option( OSM_API::OPTION_BLOCKED );
        set_transient( 'osm_admin_notice', [ 'type' => 'success', 'message' => 'Legacy OSM API block cleared. Waiting-list forms use OSM Helper; clear blocks there under Settings if intake is stopped.' ], 30 );
        wp_redirect( admin_url( 'admin.php?page=osm-for-wordpress&tab=waiting_list' ) );
        exit;
    }

    public function save_sections() {
        check_admin_referer( 'osm_sections_nonce' );

        if ( OSM_API::blocked_flag_is_set( get_option( OSM_API::OPTION_BLOCKED ) ) ) {
            set_transient( 'osm_admin_notice', [ 'type' => 'error', 'message' => 'OSM requests are stopped (X-Blocked). Clear the block before saving sections.' ], 30 );
            wp_redirect( admin_url( 'admin.php?page=osm-for-wordpress&tab=sections' ) );
            exit;
        }

        $enabled_sections = array_map( 'sanitize_text_field', $_POST['osm_enabled_sections'] ?? [] );

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
            'osm_helper_base_url',
            'osm_helper_site_key',
        ];
        foreach ( $options as $option ) {
            delete_option( $option );
        }

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'osm_current_term_%'" );

        set_transient( 'osm_admin_notice', [ 'type' => 'success', 'message' => 'Configuration reset successfully.' ], 10 );

        wp_redirect( admin_url( 'admin.php?page=osm-for-wordpress' ) );
        exit;
    }

    public function save_waiting_list() {
        check_admin_referer( 'osm_waiting_list_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html( 'You do not have permission to manage OSM settings.' ) );
        }

        $base = sanitize_text_field( wp_unslash( $_POST['osm_helper_base_url'] ?? '' ) );
        if ( $base === '' ) {
            $base = OSM_Helper_Client::DEFAULT_BASE_URL;
        }
        $base = untrailingslashit( $base );
        if ( ! preg_match( '#^https://#i', $base ) ) {
            set_transient( 'osm_admin_notice', [ 'type' => 'error', 'message' => 'OSM Helper base URL must be https.' ], 10 );
            wp_redirect( admin_url( 'admin.php?page=osm-for-wordpress&tab=waiting_list' ) );
            exit;
        }
        update_option( 'osm_helper_base_url', $base );

        $this->update_secret_option( 'osm_helper_site_key', wp_unslash( $_POST['osm_helper_site_key'] ?? '' ) );

        // Section ID now lives in OSM Helper; clear any leftover WordPress option.
        delete_option( 'osm_waiting_list_section_id' );

        $captcha = sanitize_text_field( wp_unslash( $_POST['osm_waiting_list_captcha'] ?? 'off' ) );
        if ( ! in_array( $captcha, [ 'off', 'recaptcha', 'turnstile' ], true ) ) {
            $captcha = 'off';
        }
        update_option( 'osm_waiting_list_captcha', $captcha );

        $this->update_visible_option( 'osm_recaptcha_site_key', wp_unslash( $_POST['osm_recaptcha_site_key'] ?? '' ) );
        $this->update_visible_option( 'osm_turnstile_site_key', wp_unslash( $_POST['osm_turnstile_site_key'] ?? '' ) );
        $this->update_secret_option( 'osm_recaptcha_secret_key', wp_unslash( $_POST['osm_recaptcha_secret_key'] ?? '' ) );
        $this->update_secret_option( 'osm_turnstile_secret_key', wp_unslash( $_POST['osm_turnstile_secret_key'] ?? '' ) );

        set_transient( 'osm_admin_notice', [ 'type' => 'success', 'message' => 'Waiting list / OSM Helper settings saved.' ], 10 );

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
            echo '<div class="notice notice-error"><p><strong>Legacy OSM API block (X-Blocked).</strong> Programme/events requests are stopped. Waiting-list forms use OSM Helper — clear intake blocks there under Settings.</p></div>';
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
