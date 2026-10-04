<?php

class OSM_API {
    private static $access_token = null;

    /**
     * Authorize and retrieve an access token.
     */
    public static function authorize($force = false) {
        if ( self::$access_token && ! $force ) {
            return self::$access_token;
        }

        $cached_token = OSM_Cache::get( 'access_token_v2' );
        if ( $cached_token && ! $force ) {
            self::$access_token = $cached_token;
            return $cached_token;
        }

        $client_id = get_option( 'osm_client_id' );
        $client_secret = get_option( 'osm_client_secret' );

        if ( ! $client_id || ! $client_secret ) {
            throw new Exception( 'Client ID or Secret not set in OSM Settings.' );
        }

        $response = self::make_request(
            'https://www.onlinescoutmanager.co.uk/oauth/token',
            'POST',
            [
                'grant_type'    => 'client_credentials',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'scope'         => 'section:programme:read section:event:read section:member:write',
            ],
            false
        );

        if ( isset( $response['access_token'] ) ) {
            $access_token = $response['access_token'];
            $expires_in = $response['expires_in'] ?? 3600;

            OSM_Cache::set( 'access_token_v2', $access_token, $expires_in - 60 );
            self::$access_token = $access_token;

            return $access_token;
        }

        throw new Exception( 'Authorization failed. Please check your credentials.' );
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
     * Create a child on an OSM waiting-list section and attach parent contacts.
     *
     * Uses the documented OSM member lifecycle and contact-update endpoints:
     * - POST /ext/members/contact/actions/?action=newMember
     * - POST /ext/members/contact/?action=update (contact groups)
     *
     * Sources:
     * - https://github.com/newcastlescouts/osm-api-docs (OpenAPI: newMember + contact update)
     * - https://github.com/ollytheninja/OSM-API-Docs (customdata field names: line_1, line_3, postcode)
     *
     * Does not store the submission in WordPress when OSM accepts it.
     *
     * @param string $section_id OSM section ID (waiting-list section).
     * @param array  $payload    From OSM_Waiting_List::build_osm_payload().
     * @return array{scoutid: int}
     * @throws Exception On API or configuration failure (message safe for logs, not public UI).
     */
    public static function create_waiting_list_member( $section_id, array $payload ) {
        $section_id = (string) $section_id;
        if ( $section_id === '' || ! ctype_digit( $section_id ) ) {
            throw new Exception( 'Waiting list section ID is not configured.' );
        }

        $member = $payload['member'] ?? [];
        if ( empty( $member['firstname'] ) || empty( $member['lastname'] ) || empty( $member['dob'] ) ) {
            throw new Exception( 'Member payload is incomplete.' );
        }

        $token = self::authorize();

        $body = [
            'firstname'              => $member['firstname'],
            'lastname'               => $member['lastname'],
            'dob'                    => $member['dob'],
            'started'                => $member['started'] ?? date( 'Y-m-d' ),
            'startedsection'         => $member['startedsection'] ?? date( 'Y-m-d' ),
            'sectionid'              => $section_id,
            'originating_section_id' => $section_id,
        ];

        // Waiting-list sections often have useTerms=false; include term_id when available.
        try {
            $term_id = self::get_current_term( $section_id );
            if ( $term_id ) {
                $body['term_id'] = $term_id;
            }
        } catch ( Exception $e ) {
            // Proceed without term_id for sections that do not use terms.
        }

        // Source: newcastlescouts/osm-api-docs — POST /ext/members/contact/actions/?action=newMember
        $created = self::make_request(
            'https://www.onlinescoutmanager.co.uk/ext/members/contact/actions/?action=newMember',
            'POST',
            $body,
            $token
        );

        $scoutid = isset( $created['scoutid'] ) ? (int) $created['scoutid'] : 0;
        if ( ( ! isset( $created['result'] ) || $created['result'] !== 'ok' ) && $scoutid <= 0 ) {
            throw new Exception( 'OSM did not confirm member creation.' );
        }
        if ( $scoutid <= 0 ) {
            throw new Exception( 'OSM did not return a member ID.' );
        }

        // Member address / postcode (group_id 6 = Member's own details).
        // Source: newcastlescouts/osm-api-docs contact groups; field names from ollytheninja customdata columns.
        $member_details = $payload['member_details'] ?? [];
        if ( ! empty( $member_details ) ) {
            self::update_member_contact( $section_id, $scoutid, 6, $member_details, $token );
        }

        // Primary Contact 1 (group_id 1).
        $contact1 = $payload['contact1'] ?? [];
        if ( ! empty( $contact1 ) ) {
            self::update_member_contact( $section_id, $scoutid, 1, $contact1, $token );
        }

        // Primary Contact 2 (group_id 2), optional.
        $contact2 = $payload['contact2'] ?? null;
        if ( is_array( $contact2 ) && ! empty( $contact2 ) ) {
            self::update_member_contact( $section_id, $scoutid, 2, $contact2, $token );
        }

        return [ 'scoutid' => $scoutid ];
    }

    /**
     * Update a contact group on a member.
     *
     * Source: newcastlescouts/osm-api-docs —
     * POST /ext/members/contact/?action=update with associated_type=member,
     * associated_id, group_id, context=members, and data[field]=value pairs.
     *
     * Contact group IDs: 1 = Primary Contact 1, 2 = Primary Contact 2,
     * 6 = Member's own details.
     *
     * @param string $section_id Section ID.
     * @param int    $scoutid    Member scout ID.
     * @param int    $group_id   Contact group ID.
     * @param array  $fields     Field => value map.
     * @param string $token      Bearer token.
     * @return void
     */
    public static function update_member_contact( $section_id, $scoutid, $group_id, array $fields, $token = null ) {
        if ( $token === null ) {
            $token = self::authorize();
        }

        $body = [
            'associated_type' => 'member',
            'associated_id'   => (string) $scoutid,
            'group_id'        => (string) $group_id,
            'context'         => 'members',
            'sectionid'       => (string) $section_id,
        ];

        foreach ( $fields as $key => $value ) {
            if ( $value === null || $value === '' ) {
                continue;
            }
            $body[ 'data[' . $key . ']' ] = $value;
        }

        self::make_request(
            'https://www.onlinescoutmanager.co.uk/ext/members/contact/?action=update',
            'POST',
            $body,
            $token
        );
    }

    /**
     * Make an authenticated API request.
     */
    private static function make_request( $url, $method = 'GET', $body = [], $token = false ) {
        $args = [
            'method'      => $method,
            'timeout'     => 30,
            'headers'     => [],
            'body'        => $body ? http_build_query( $body ) : null,
        ];

        if ( $token ) {
            $args['headers']['Authorization'] = "Bearer $token";
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            throw new Exception( 'API request failed: ' . $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw    = wp_remote_retrieve_body( $response );
        $data   = json_decode( $raw, true );

        if ( $status >= 400 ) {
            // Avoid echoing raw API bodies (may contain sensitive detail).
            throw new Exception( 'API HTTP error ' . $status );
        }

        if ( ! is_array( $data ) ) {
            throw new Exception( 'API returned an unexpected response.' );
        }

        if ( isset( $data['error'] ) && $data['error'] ) {
            throw new Exception( 'API error: ' . ( is_string( $data['error'] ) ? $data['error'] : 'request failed' ) );
        }

        return $data;
    }
}