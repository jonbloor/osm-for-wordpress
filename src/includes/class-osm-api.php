<?php

class OSM_API {
    private static $access_token = null;

    /**
     * Stop every later OSM call in this PHP request after X-Blocked or HTTP 429.
     *
     * @var bool
     */
    private static $halt_further = false;

    /**
     * Legacy programme/events reads may still refresh a stored token.
     * Waiting-list writes go through OSM Helper — this plugin no longer
     * registers its own OSM OAuth application.
     */
    const TOKEN_URL = 'https://www.onlinescoutmanager.co.uk/oauth/token';

    const OPTION_BLOCKED    = 'osm_api_blocked';
    const OPTION_DEPRECATED = 'osm_api_deprecated';
    const OPTION_REMOVED    = 'osm_api_removed_endpoints';
    const OPTION_RATE_LIMIT = 'osm_api_rate_limit';

    /**
     * @return bool
     */
    public static function requests_halted() {
        return self::$halt_further;
    }

    /**
     * Whether the stored osm_api_blocked option should refuse outbound calls.
     *
     * @param mixed $flag Option value.
     * @return bool
     */
    public static function blocked_flag_is_set( $flag ) {
        if ( $flag === null || $flag === false || $flag === '' || $flag === '0' || $flag === 0 ) {
            return false;
        }
        return true;
    }

    /**
     * Refuse to call OSM when this request already halted or an admin has not
     * cleared a previous X-Blocked.
     *
     * @param mixed $blocked_flag Value of the osm_api_blocked option.
     * @return void
     * @throws Exception
     */
    public static function assert_requests_allowed( $blocked_flag ) {
        if ( self::$halt_further ) {
            throw new Exception( 'Further OSM requests were stopped after a block or rate limit in this request.' );
        }
        if ( self::blocked_flag_is_set( $blocked_flag ) ) {
            throw new Exception( 'OSM API access is blocked. An administrator must clear the block in OSM Settings before any further requests.' );
        }
    }

    /**
     * @param string $path        URL path.
     * @param mixed  $removed_map Option map of path => info.
     * @return bool
     */
    public static function endpoint_is_removed( $path, $removed_map ) {
        return is_string( $path ) && $path !== '' && is_array( $removed_map ) && isset( $removed_map[ $path ] );
    }


    /**
     * Case-insensitive header lookup. Null when the header was not sent.
     *
     * @param array  $headers Response headers.
     * @param string $name    Header name.
     * @return string|null
     */
    public static function header_value( array $headers, $name ) {
        $name = strtolower( (string) $name );
        foreach ( $headers as $key => $value ) {
            if ( strtolower( (string) $key ) !== $name ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $first = reset( $value );
                return $first === false ? '' : (string) $first;
            }
            return (string) $value;
        }
        return null;
    }

    /**
     * Interpret an OSM HTTP response. Does not perform I/O and does not retry.
     *
     * @param int    $status  HTTP status.
     * @param array  $headers Response headers.
     * @param string $raw     Raw body.
     * @return array
     */
    public static function assess_response( $status, array $headers, $raw ) {
        $status         = (int) $status;
        $blocked_value  = self::header_value( $headers, 'x-blocked' );
        $deprecated     = self::header_value( $headers, 'x-deprecated' );
        $retry_after    = self::header_value( $headers, 'retry-after' );
        $blocked        = $blocked_value !== null;
        $rate           = [];

        foreach ( $headers as $key => $value ) {
            $lkey = strtolower( (string) $key );
            if ( strpos( $lkey, 'x-ratelimit-' ) !== 0 ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $first = reset( $value );
                $rate[ $lkey ] = $first === false ? '' : (string) $first;
            } else {
                $rate[ $lkey ] = (string) $value;
            }
        }

        $data = json_decode( (string) $raw, true );

        $deprecated_value = ( $deprecated !== null && $deprecated !== '' ) ? $deprecated : null;
        $deprecated_removed = false;
        if ( $deprecated_value !== null ) {
            $ts = strtotime( $deprecated_value );
            if ( $ts !== false && $ts <= time() ) {
                $deprecated_removed = true;
            }
        }

        $error_message = null;
        if ( $blocked ) {
            $detail = '';
            if ( $blocked_value !== '' && $blocked_value !== '1' && strtolower( $blocked_value ) !== 'true' ) {
                $detail = ': ' . $blocked_value;
            }
            $error_message = 'OSM blocked this application (X-Blocked' . $detail . '). All further OSM requests have been stopped. Clear the block in OSM Settings only after fixing the cause. Continuing after a block can become permanent.';
        } elseif ( $status === 429 ) {
            $wait = ( $retry_after !== null && $retry_after !== '' ) ? $retry_after : 'the indicated delay';
            $error_message = 'OSM rate limited this application (HTTP 429). Retry after ' . $wait . '. This plugin does not retry automatically.';
        } elseif ( $status >= 400 ) {
            $error_message = self::format_http_error( $status, is_array( $data ) ? $data : null );
        } elseif ( $status >= 300 ) {
            $error_message = 'API returned an unexpected redirect (HTTP ' . $status . '). Aborting.';
        } elseif ( $deprecated_removed ) {
            $error_message = 'OSM marked this endpoint removed (X-Deprecated: ' . $deprecated_value . '). It will not be called again.';
        } elseif ( ! is_array( $data ) ) {
            $error_message = 'API returned an unexpected response.';
        } elseif ( isset( $data['error'] ) && $data['error'] ) {
            $error_message = self::format_http_error( $status > 0 ? $status : 200, $data );
        }

        return [
            'ok'                 => $error_message === null,
            'data'               => is_array( $data ) ? $data : null,
            'blocked'            => $blocked,
            'blocked_value'      => $blocked_value,
            'deprecated'         => $deprecated_value,
            'deprecated_removed' => $deprecated_removed,
            'rate_limit'         => $rate,
            'retry_after'        => ( $retry_after !== null && $retry_after !== '' ) ? $retry_after : null,
            'error_message'      => $error_message,
        ];
    }

    /**
     * HTTP failure text including OAuth error and error_description when present.
     *
     * @param int        $status HTTP status.
     * @param array|null $data   Decoded JSON body.
     * @return string
     */
    public static function format_http_error( $status, $data ) {
        $message = 'API HTTP error ' . (int) $status;
        if ( ! is_array( $data ) ) {
            return $message;
        }

        $err  = ( isset( $data['error'] ) && is_string( $data['error'] ) ) ? $data['error'] : '';
        $desc = ( isset( $data['error_description'] ) && is_string( $data['error_description'] ) ) ? $data['error_description'] : '';
        $err  = trim( $err );
        $desc = trim( $desc );

        if ( $err === '' && $desc === '' ) {
            return $message;
        }

        $detail = $err;
        if ( $desc !== '' ) {
            $detail = $detail === '' ? $desc : $detail . ' — ' . $desc;
        }

        return $message . ': ' . $detail;
    }

    /**
     * Build wp_remote_request arguments. Form bodies always set Content-Type.
     *
     * @param string     $method HTTP method.
     * @param array|null $body   Form fields, or empty for none.
     * @param string|false $token Bearer token or false.
     * @return array
     */
    public static function build_http_args( $method, $body = [], $token = false ) {
        $args = [
            'method'      => $method,
            'timeout'     => 30,
            'redirection' => 0,
            'headers'     => [],
            'body'        => null,
        ];

        if ( $body ) {
            $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            $args['body'] = http_build_query( $body );
        }

        if ( $token ) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        return $args;
    }

    /**
     * Retrieve a stored OSM access token for legacy programme/events reads.
     * Waiting-list form submissions must use OSM_Helper_Client, not this method.
     * This plugin no longer offers Connect with OSM or client-credentials setup.
     *
     * @param bool $force Ignore a still-valid stored token.
     * @return string
     * @throws Exception
     */
    public static function authorize( $force = false ) {
        $blocked = function_exists( 'get_option' ) ? get_option( self::OPTION_BLOCKED ) : null;
        self::assert_requests_allowed( $blocked );

        if ( self::$access_token && ! $force ) {
            return self::$access_token;
        }

        $token   = get_option( 'osm_access_token', '' );
        $expires = (int) get_option( 'osm_token_expires_at', 0 );

        if ( is_string( $token ) && $token !== '' && ! $force && $expires > ( time() + 60 ) ) {
            self::$access_token = $token;
            return $token;
        }

        $refresh = get_option( 'osm_refresh_token', '' );
        if ( ! is_string( $refresh ) || $refresh === '' ) {
            throw new Exception( 'No OSM token is available for programme/events. Waiting-list forms use OSM Helper (Settings → Waiting List), not a plugin OSM app.' );
        }

        return self::refresh_access_token( $refresh );
    }

    /**
     * @param string $refresh Refresh token.
     * @return string
     * @throws Exception
     */
    private static function refresh_access_token( $refresh ) {
        $client_id     = get_option( 'osm_client_id' );
        $client_secret = get_option( 'osm_client_secret' );
        if ( ! $client_id || ! $client_secret ) {
            throw new Exception( 'Legacy OSM credentials are incomplete. Waiting-list forms use OSM Helper instead.' );
        }

        $response = self::make_request(
            self::TOKEN_URL,
            'POST',
            [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
            ],
            false
        );

        self::store_token_response( $response );
        return self::$access_token;
    }

    /**
     * Persist access and refresh tokens in options. Never echoed into admin HTML.
     *
     * @param array $response Token endpoint JSON.
     * @return void
     * @throws Exception
     */
    private static function store_token_response( array $response ) {
        if ( empty( $response['access_token'] ) || ! is_string( $response['access_token'] ) ) {
            $detail = '';
            if ( isset( $response['error'] ) && is_string( $response['error'] ) ) {
                $detail = $response['error'];
            }
            if ( isset( $response['error_description'] ) && is_string( $response['error_description'] ) && $response['error_description'] !== '' ) {
                $detail = $detail === '' ? $response['error_description'] : $detail . ' — ' . $response['error_description'];
            }
            throw new Exception( $detail !== '' ? ( 'Authorization failed: ' . $detail ) : 'Authorization failed. OSM did not return an access token.' );
        }

        $expires_in = isset( $response['expires_in'] ) ? (int) $response['expires_in'] : 3600;
        if ( $expires_in < 0 ) {
            $expires_in = 0;
        }

        update_option( 'osm_access_token', $response['access_token'], false );
        update_option( 'osm_token_expires_at', time() + $expires_in, false );

        if ( ! empty( $response['refresh_token'] ) && is_string( $response['refresh_token'] ) ) {
            update_option( 'osm_refresh_token', $response['refresh_token'], false );
        }

        self::$access_token = $response['access_token'];

        if ( class_exists( 'OSM_Cache' ) ) {
            OSM_Cache::delete( 'access_token_v2' );
        }
    }

    /**
     * Get a list of sections from the API.
     *
     * @return array
     */
    public static function get_sections() {
        $cache_key = 'osm_sections';
        $cached_sections = OSM_Cache::get( $cache_key );

        // Return cached sections if available
        if ( $cached_sections ) {
            return $cached_sections;
        }

        // Retrieve sections from the API
        $token = self::authorize();
        $sections = self::make_request(
            'https://www.onlinescoutmanager.co.uk/api.php?action=getUserRoles',
            'GET',
            [],
            $token
        );

        // Convert to associative array and reduce to only necessary fields
        $sections = array_reduce($sections, function ($carry, $section) {
            $carry[$section['sectionid']] = [
                'groupname' => $section['groupname'],
                'sectionname' => $section['sectionname'],
                'section' => $section['section'],
            ];
            return $carry;
        }, []);

        // Filter out campsite sections
        $sections = array_filter($sections, function ($section) {
            return $section['section'] !== 'campsite';
        });

        // Cache the filtered sections
        if ( $sections ) {
            OSM_Cache::set( $cache_key, $sections, 86400 ); // Cache for 24 hours
        }

        // Return the filtered sections
        return $sections;
    }

    /**
     * Retrieve the current term for a section.
     * 
     * @param int $sectionid
     * @return int
     */
    public static function get_current_term( $sectionid ) {
        $cache_key = "current_term_{$sectionid}";
        $cached_termid = OSM_Cache::get( $cache_key );

        if ( $cached_termid ) {
            return $cached_termid;
        }

        $token = self::authorize();
        $terms = self::make_request(
            'https://www.onlinescoutmanager.co.uk/api.php?action=getTerms',
            'GET',
            [],
            $token
        );

        if ( isset( $terms[ $sectionid ] ) ) {
            $today = date( 'Y-m-d' );
            foreach ( $terms[ $sectionid ] as $term ) {
                if ( $term['startdate'] <= $today && $term['enddate'] >= $today ) {
                    OSM_Cache::set( $cache_key, $term['termid'], 86400 ); // Cache for 24 hours
                    return $term['termid'];
                }
            }
        }

        throw new Exception( "No current term found for section ID: $sectionid" );
    }

    /**
     * Retrieve the programme for a section and term.
     */
    public static function get_programme( $sectionid, $termid ) {
        $cache_key = "programme_{$sectionid}_{$termid}";
        $cached_programme = OSM_Cache::get( $cache_key );

        if ( $cached_programme ) {
            return $cached_programme;
        }

        $token = self::authorize();
        $programme = self::make_request(
            "https://www.onlinescoutmanager.co.uk/programme.php?action=getProgramme&sectionid={$sectionid}&termid={$termid}",
            'GET',
            [],
            $token
        );

        if ( $programme ) {
            OSM_Cache::set( $cache_key, $programme, 86400 ); // Cache for 24 hours
        }

        return $programme;
    }

    /**
     * Retrieve events for a section and term.
     */
    public static function get_events( $sectionid, $termid ) {
        $cache_key = "events_{$sectionid}_{$termid}";
        $cached_events = OSM_Cache::get( $cache_key );

        if ( $cached_events ) {
            return $cached_events;
        }

        $token = self::authorize();
        $events = self::make_request(
            "https://www.onlinescoutmanager.co.uk/ext/events/summary/?action=get&sectionid={$sectionid}&termid={$termid}",
            'GET',
            [],
            $token
        );

        if ( $events ) {
            OSM_Cache::set( $cache_key, $events, 86400 ); // Cache for 24 hours
        }

        return $events;
    }

    /**
     * Make an authenticated API request. One attempt; no retry loop.
     *
     * @param string       $url    Endpoint URL.
     * @param string       $method HTTP method.
     * @param array        $body   Form fields.
     * @param string|false $token  Bearer token or false.
     * @return array
     * @throws Exception
     */
    private static function make_request( $url, $method = 'GET', $body = [], $token = false ) {
        $blocked = function_exists( 'get_option' ) ? get_option( self::OPTION_BLOCKED ) : null;
        self::assert_requests_allowed( $blocked );

        $path = parse_url( $url, PHP_URL_PATH );
        $path = is_string( $path ) ? $path : '';
        $removed = function_exists( 'get_option' ) ? get_option( self::OPTION_REMOVED, [] ) : [];
        if ( self::endpoint_is_removed( $path, $removed ) ) {
            $when = '';
            if ( is_array( $removed[ $path ] ) && ! empty( $removed[ $path ]['date'] ) ) {
                $when = (string) $removed[ $path ]['date'];
            }
            throw new Exception( 'OSM marked this endpoint removed (X-Deprecated' . ( $when !== '' ? ': ' . $when : '' ) . '). It will not be called again.' );
        }

        $response = wp_remote_request( $url, self::build_http_args( $method, $body, $token ) );

        if ( is_wp_error( $response ) ) {
            throw new Exception( 'API request failed: ' . $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw    = (string) wp_remote_retrieve_body( $response );
        $headers = self::normalise_headers( wp_remote_retrieve_headers( $response ) );
        $assessed = self::assess_response( $status, $headers, $raw );

        if ( ! empty( $assessed['rate_limit'] ) && function_exists( 'update_option' ) ) {
            update_option( self::OPTION_RATE_LIMIT, $assessed['rate_limit'], false );
        }

        if ( ! empty( $assessed['deprecated'] ) ) {
            if ( function_exists( 'update_option' ) ) {
                update_option(
                    self::OPTION_DEPRECATED,
                    [
                        'date' => $assessed['deprecated'],
                        'path' => $path,
                        'at'   => time(),
                    ],
                    false
                );
            }
            error_log( 'OSM API X-Deprecated: ' . $assessed['deprecated'] . ' endpoint: ' . $path );

            if ( ! empty( $assessed['deprecated_removed'] ) && $path !== '' && function_exists( 'get_option' ) ) {
                $removed = get_option( self::OPTION_REMOVED, [] );
                if ( ! is_array( $removed ) ) {
                    $removed = [];
                }
                $removed[ $path ] = [
                    'date' => $assessed['deprecated'],
                    'at'   => time(),
                ];
                update_option( self::OPTION_REMOVED, $removed, false );
            }
        }

        if ( ! empty( $assessed['blocked'] ) ) {
            self::$halt_further = true;
            if ( function_exists( 'update_option' ) ) {
                update_option(
                    self::OPTION_BLOCKED,
                    [
                        'header' => $assessed['blocked_value'],
                        'path'   => $path,
                        'at'     => time(),
                    ],
                    false
                );
            }
        } elseif ( $status === 429 ) {
            self::$halt_further = true;
        }

        if ( empty( $assessed['ok'] ) ) {
            throw new Exception( $assessed['error_message'] ? $assessed['error_message'] : 'API returned an unexpected response.' );
        }

        return $assessed['data'];
    }

    /**
     * @param mixed $headers wp_remote_retrieve_headers() result.
     * @return array
     */
    private static function normalise_headers( $headers ) {
        $out = [];
        if ( is_array( $headers ) || $headers instanceof Traversable ) {
            foreach ( $headers as $key => $value ) {
                $out[ $key ] = $value;
            }
        }
        return $out;
    }
}
