<?php
/**
 * Pass-through client for OSM Helper waiting-list intake.
 * This plugin is not affiliated with Online Scout Manager.
 */
class OSM_Helper_Client {
    const DEFAULT_BASE_URL = 'https://osmhelper.co.uk';
    const SUBMIT_PATH = '/api/waiting-list/submit';

    /**
     * @return string Normalised base URL without trailing slash.
     */
    public static function base_url() {
        $url = function_exists( 'get_option' ) ? get_option( 'osm_helper_base_url', self::DEFAULT_BASE_URL ) : self::DEFAULT_BASE_URL;
        if ( ! is_string( $url ) || trim( $url ) === '' ) {
            $url = self::DEFAULT_BASE_URL;
        }
        return rtrim( trim( $url ), '/' );
    }

    /**
     * @return string Site key from options (never echo this).
     */
    public static function site_key() {
        $key = function_exists( 'get_option' ) ? get_option( 'osm_helper_site_key', '' ) : '';
        return is_string( $key ) ? $key : '';
    }

    /**
     * @return bool
     */
    public static function is_configured() {
        return self::site_key() !== '' && self::base_url() !== '';
    }

    /**
     * Full submit URL for the configured base.
     *
     * @param string|null $base Optional base override (tests).
     * @return string
     */
    public static function submit_url( $base = null ) {
        $base = $base === null ? self::base_url() : rtrim( trim( (string) $base ), '/' );
        // Accept the full endpoint pasted as the base URL.
        $suffix = self::SUBMIT_PATH;
        while ( strlen( $base ) >= strlen( $suffix ) && substr( $base, -strlen( $suffix ) ) === $suffix ) {
            $base = rtrim( substr( $base, 0, -strlen( $suffix ) ), '/' );
        }
        return $base . self::SUBMIT_PATH;
    }

    /**
     * POST the validated waiting-list payload to OSM Helper.
     * Does not store the payload in WordPress.
     *
     * @param array $payload From OSM_Waiting_List::build_osm_payload().
     * @return array{scoutid: int}
     * @throws Exception
     */
    public static function submit_waiting_list( array $payload ) {
        $site_key = self::site_key();
        if ( $site_key === '' ) {
            throw new Exception( 'OSM Helper site key is not configured.' );
        }

        $url = self::submit_url();
        $args = [
            'method'      => 'POST',
            'timeout'     => 30,
            'redirection' => 0,
            'headers'     => [
                'Content-Type'           => 'application/json',
                'Accept'                 => 'application/json',
                'Authorization'          => 'Bearer ' . $site_key,
                'X-Osmhelper-Site-Key'   => $site_key,
            ],
            'body'        => wp_json_encode( $payload ),
        ];

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            throw new Exception( 'OSM Helper request failed: ' . $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw    = (string) wp_remote_retrieve_body( $response );
        $data   = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            $data = [];
        }

        if ( $status === 429 ) {
            throw new Exception( 'OSM Helper reported OSM rate limiting (HTTP 429). Try again later.' );
        }
        if ( $status >= 400 ) {
            $err = isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : '';
            // Do not include the site key or raw bodies in the exception message beyond OSM Helper's safe error string.
            if ( $err !== '' ) {
                throw new Exception( 'OSM Helper error: ' . $err );
            }
            throw new Exception( 'OSM Helper HTTP error ' . $status );
        }

        if ( empty( $data['ok'] ) ) {
            $err = isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : 'OSM Helper did not confirm the submission.';
            throw new Exception( $err );
        }

        $scoutid = isset( $data['scoutid'] ) ? (int) $data['scoutid'] : 0;
        return [ 'scoutid' => $scoutid ];
    }
}
