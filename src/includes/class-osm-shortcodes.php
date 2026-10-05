<?php

class OSM_Shortcodes {
    /**
     * OSM_Shortcodes constructor
     * 
     * @return void
     */
    public function __construct() {
        add_shortcode( 'osm_programme', [ $this, 'render_programme' ] );
        add_shortcode( 'osm_events', [ $this, 'render_events' ] );
        add_shortcode( 'osm_waiting_list', [ $this, 'render_waiting_list' ] );
    }

    /**
     * Render the programme shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string Shortcode output
     */
    public function render_programme( $atts ) {
        try {
            // Get the attributes
            $atts = shortcode_atts( [ 'sectionid' => '', 'futureonly' => false ], $atts );
            $futureonly = filter_var( $atts['futureonly'], FILTER_VALIDATE_BOOLEAN );

            // Check if the section ID is numeric
            if ( ! is_numeric( $atts['sectionid'] ) ) {
                throw new Exception( 'The section ID provided is missing or invalid.' );
            }

            // Check the section exists
            $sections = OSM_API::get_sections();
            if ( ! isset( $sections[ $atts['sectionid'] ] ) ) {
                throw new Exception( 'The section ID provided does not exist.' );
            }

            // Check if the section is enabled
            $enabled_sections = get_option( 'osm_enabled_sections', [] );
            if ( ! isset( $enabled_sections[ $atts['sectionid'] ] ) ) {
                throw new Exception( 'Sorry, we\'re unable to display the programme for this section, please check back later.' );
            }

            // Get the current term
            $termid = OSM_API::get_current_term( $atts['sectionid'] );
            $programme = OSM_API::get_programme( $atts['sectionid'], $termid )['items'];

            // Filter the programme if future only is set
            if ( $futureonly ) {
                $programme = array_filter( $programme, function ( $item ) {
                    // Check the meeting date is a valid date that can be converted to a timestamp and if so filter
                    if ( isset( $item['meetingdate'] ) && strtotime( $item['meetingdate'] ) ) {
                        return strtotime( $item['meetingdate'] ) >= strtotime( date( 'Y-m-d' ) );
                    }
                } );
            }

            // Check if the programme is empty
            if ( empty( $programme ) ) {
                throw new Exception( $futureonly ? 'Sorry, we couldn\'t find upcoming meetings, please check back later.' : 'Sorry, no programme is currently available, please check back later.' );
            }

            // Load the date format
            $date_format = OSM_Options::get_date_format() ?? 'd M Y';

            // Render the programme
            ob_start();
            include OSM_TEMPLATES_DIR . '/shortcode/programme.php';
            return ob_get_clean();
        } catch (Exception $error) {
            // Set the error message
            $message = $error->getMessage();

            // Render the error message
            ob_start();
            include OSM_TEMPLATES_DIR . '/shortcode/error.php';
            return ob_get_clean();
        }
    }

    /**
     * Render the events shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string Shortcode output
     */
    public function render_events( $atts ) {
        try {
            // Get the attributes
            $atts = shortcode_atts( [ 'sectionid' => '', 'futureonly' => false ], $atts );
            $futureonly = filter_var( $atts['futureonly'], FILTER_VALIDATE_BOOLEAN );

            // Check if the section ID is numeric
            if ( ! is_numeric( $atts['sectionid'] ) ) {
                throw new Exception( 'The section ID provided is missing or invalid.' );
            }

            // Check the section exists
            $sections = OSM_API::get_sections();
            if ( ! isset( $sections[ $atts['sectionid'] ] ) ) {
                throw new Exception( 'The section ID provided does not exist.' );
            }

            // Check if the section is enabled
            $enabled_sections = get_option( 'osm_enabled_sections', [] );
            if ( ! isset( $enabled_sections[ $atts['sectionid'] ] ) ) {
                throw new Exception( 'Sorry, we\'re unable to display the events for this section, please check back later.' );
            }

            // Get the current term
            $termid = OSM_API::get_current_term( $atts['sectionid'] );
            $events = OSM_API::get_events( $atts['sectionid'], $termid )['items'];

            // Filter the events if future only is set
            if ( $futureonly ) {
                $events = array_filter( $events, function ( $item ) {
                    // Check the event date is a valid date that can be converted to a timestamp and if so filter
                    if ( isset( $item['eventdate'] ) && strtotime( $item['eventdate'] ) ) {
                        return strtotime( $item['eventdate'] ) >= strtotime( date( 'Y-m-d' ) );
                    } elseif ( isset( $item['startdate_g'] ) && strtotime( $item['startdate_g'] ) ) {
                        return strtotime( $item['startdate_g'] ) >= strtotime( date( 'Y-m-d' ) );
                    }
                } );
            }

            // Check if the events are empty
            if ( empty( $events ) ) {
                throw new Exception( $futureonly ? 'Sorry, we couldn\'t find upcoming events, please check back later.' : 'Sorry, no events are currently available, please check back later.' );
            }

            // Load the date and time formats
            $date_format = OSM_Options::get_date_format() ?? 'd M Y';
            $time_format = OSM_Options::get_time_format() ?? 'H:i';

            // Render the events
            ob_start();
            include OSM_TEMPLATES_DIR . '/shortcode/events.php';
            return ob_get_clean();
        } catch (Exception $error) {
            // Set the error message
            $message = $error->getMessage();

            // Render the error message
            ob_start();
            include OSM_TEMPLATES_DIR . '/shortcode/error.php';
            return ob_get_clean();
        }
    }

    /**
     * Render the public waiting-list form shortcode.
     *
     * Usage: [osm_waiting_list]
     *
     * OSM Helper base URL and site key are configured in OSM Settings
     * (Waiting List tab). Submissions are written straight into OSM and
     * are not stored in WordPress when OSM accepts them.
     *
     * @param array $atts Shortcode attributes (unused).
     * @return string Shortcode output
     */
    public function render_waiting_list( $atts = [] ) {
        $result = null;
        $values = [];
        $errors = [];

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['osm_waiting_list_submit'] ) ) {
            $result = OSM_Waiting_List::handle_submission( wp_unslash( $_POST ) );
            $values = $result['values'] ?? [];
            $errors = $result['errors'] ?? [];
        }

        $section_configured = OSM_Helper_Client::is_configured();
        $captcha_mode = OSM_Waiting_List::captcha_mode();
        $captcha_site_key = '';

        if ( $captcha_mode === 'recaptcha' ) {
            $captcha_site_key = (string) get_option( 'osm_recaptcha_site_key', '' );
            if ( $captcha_site_key !== '' ) {
                wp_enqueue_script(
                    'osm-recaptcha',
                    'https://www.google.com/recaptcha/api.js',
                    [],
                    null,
                    [
                        'in_footer' => true,
                        'strategy'  => 'defer',
                    ]
                );
            }
        } elseif ( $captcha_mode === 'turnstile' ) {
            $captcha_site_key = (string) get_option( 'osm_turnstile_site_key', '' );
            if ( $captcha_site_key !== '' ) {
                wp_enqueue_script(
                    'osm-turnstile',
                    'https://challenges.cloudflare.com/turnstile/v0/api.js',
                    [],
                    null,
                    [
                        'in_footer' => true,
                        'strategy'  => 'defer',
                    ]
                );
            }
        }

        $address_lookup = self::enqueue_address_lookup( $section_configured && empty( $result['success'] ) );

        ob_start();
        include OSM_TEMPLATES_DIR . '/shortcode/waiting-list.php';
        return ob_get_clean();
    }

    /**
     * Load the address lookup script only where the waiting-list shortcode renders a form.
     * Independent of captcha mode (off, reCAPTCHA, Turnstile).
     *
     * @param bool $form_shown Whether the form is on the page.
     * @return string Effective mode: off, google, or postcodes_io.
     */
    private static function enqueue_address_lookup( $form_shown ) {
        $mode = OSM_Waiting_List::address_lookup_mode();
        $google_key = trim( (string) get_option( 'osm_google_maps_api_key', '' ) );
        if ( $mode === 'google' && $google_key === '' ) {
            $mode = 'off'; // No key: plain manual entry.
        }
        if ( ! $form_shown || $mode === 'off' ) {
            return 'off';
        }

        wp_enqueue_script(
            'osm-waiting-list',
            OSM_ASSETS_URI . '/js/waiting-list.js',
            [],
            file_exists( OSM_PLUGIN_DIR . 'assets/js/waiting-list.js' ) ? '1.1.0-' . filemtime( OSM_PLUGIN_DIR . 'assets/js/waiting-list.js' ) : '1.1.0',
            [ 'in_footer' => true ]
        );
        wp_localize_script(
            'osm-waiting-list',
            'osmWaitingListConfig',
            [
                'mode'         => $mode,
                'postcodesApi' => OSM_Waiting_List::POSTCODES_IO_BASE,
                'i18n'         => [
                    'checking' => 'Checking postcode…',
                    'found'    => 'Postcode found.',
                    'notFound' => 'We could not find that postcode. Please check it.',
                    'invalid'  => 'That does not look like a UK postcode.',
                ],
            ]
        );

        if ( $mode === 'google' ) {
            // Printed after waiting-list.js so the callback exists when Google calls it.
            $src = add_query_arg(
                [
                    'key'       => rawurlencode( $google_key ),
                    'libraries' => 'places',
                    'loading'   => 'async',
                    'callback'  => 'osmWlGoogleReady',
                    'region'    => 'GB',
                    'language'  => 'en-GB',
                    'v'         => 'weekly',
                ],
                'https://maps.googleapis.com/maps/api/js'
            );
            wp_enqueue_script( 'osm-google-places', $src, [ 'osm-waiting-list' ], null, [ 'in_footer' => true ] );
        }
        return $mode;
    }
}
