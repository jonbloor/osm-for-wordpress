<?php

/**
 * Public waiting-list form: validation, rate limiting, and OSM submission.
 *
 * This plugin is not affiliated with Online Scout Manager.
 */
class OSM_Waiting_List {
    const RATE_LIMIT_MAX = 5;
    const RATE_LIMIT_WINDOW = 3600; // 1 hour
    const HONEYPOT_FIELD = 'osm_wl_website';
    const PARENT_NOTE_MAX = 1000;
    const ADDRESS_LOOKUP_MODES = [ 'off', 'google', 'postcodes_io' ];
    const POSTCODES_IO_BASE = 'https://api.postcodes.io/postcodes/';
    const POSTCODES_IO_TIMEOUT = 3;

    /**
     * Validate submitted waiting-list fields.
     *
     * Pure PHP — no WordPress dependency. Returns an array of field => message.
     *
     * @param array $input Raw input values (already trimmed strings where applicable).
     * @return array<string, string> Field errors keyed by field name.
     */
    public static function validate( array $input ) {
        $errors = [];

        $required = [
            'child_first_name'  => 'Please enter the child\'s first name.',
            'child_last_name'   => 'Please enter the child\'s last name.',
            'child_dob'         => 'Please enter the child\'s date of birth.',
            'child_postcode'    => 'Please enter the child\'s postcode.',
            'parent1_first_name'=> 'Please enter parent 1\'s first name.',
            'parent1_last_name' => 'Please enter parent 1\'s last name.',
            'parent1_email'     => 'Please enter parent 1\'s email address.',
            'parent1_phone'     => 'Please enter parent 1\'s phone number.',
        ];

        foreach ( $required as $field => $message ) {
            if ( self::is_blank( $input[ $field ] ?? '' ) ) {
                $errors[ $field ] = $message;
            }
        }

        if ( empty( $errors['child_dob'] ) && ! self::is_valid_uk_date( $input['child_dob'] ?? '' ) ) {
            $errors['child_dob'] = 'Please enter the date of birth as day/month/year (for example 15/03/2018).';
        }

        if ( empty( $errors['child_postcode'] ) && ! self::is_valid_uk_postcode( $input['child_postcode'] ?? '' ) ) {
            $errors['child_postcode'] = 'Please enter a valid UK postcode.';
        }

        if ( empty( $errors['parent1_email'] ) && ! self::is_valid_email( $input['parent1_email'] ?? '' ) ) {
            $errors['parent1_email'] = 'Please enter a valid email address for parent 1.';
        }

        if ( empty( $errors['parent1_phone'] ) && ! self::is_valid_phone( $input['parent1_phone'] ?? '' ) ) {
            $errors['parent1_phone'] = 'Please enter a valid phone number for parent 1.';
        }

        $note = (string) ( $input['parent_note'] ?? '' );
        if ( self::text_length( $note ) > self::PARENT_NOTE_MAX ) {
            $errors['parent_note'] = 'Please keep the note to ' . self::PARENT_NOTE_MAX . ' characters or fewer.';
        }

        $consent = $input['consent'] ?? '';
        if ( $consent !== '1' && $consent !== 1 && $consent !== true ) {
            $errors['consent'] = 'Please confirm that you agree the group may store these details in Online Scout Manager to manage the waiting list.';
        }

        // Parent 2: if any field is filled, require first, last, and email.
        $p2_fields = [
            'parent2_first_name' => $input['parent2_first_name'] ?? '',
            'parent2_last_name'  => $input['parent2_last_name'] ?? '',
            'parent2_email'      => $input['parent2_email'] ?? '',
            'parent2_phone'      => $input['parent2_phone'] ?? '',
        ];
        $p2_any = false;
        foreach ( $p2_fields as $value ) {
            if ( ! self::is_blank( $value ) ) {
                $p2_any = true;
                break;
            }
        }

        if ( $p2_any ) {
            if ( self::is_blank( $p2_fields['parent2_first_name'] ) ) {
                $errors['parent2_first_name'] = 'Please enter parent 2\'s first name, or leave all parent 2 fields blank.';
            }
            if ( self::is_blank( $p2_fields['parent2_last_name'] ) ) {
                $errors['parent2_last_name'] = 'Please enter parent 2\'s last name, or leave all parent 2 fields blank.';
            }
            if ( self::is_blank( $p2_fields['parent2_email'] ) ) {
                $errors['parent2_email'] = 'Please enter parent 2\'s email address, or leave all parent 2 fields blank.';
            } elseif ( ! self::is_valid_email( $p2_fields['parent2_email'] ) ) {
                $errors['parent2_email'] = 'Please enter a valid email address for parent 2.';
            }
            if ( ! self::is_blank( $p2_fields['parent2_phone'] ) && ! self::is_valid_phone( $p2_fields['parent2_phone'] ) ) {
                $errors['parent2_phone'] = 'Please enter a valid phone number for parent 2.';
            }
        }

        return $errors;
    }

    /**
     * Convert a UK day/month/year date string to OSM's yyyy-mm-dd format.
     *
     * @param string $uk_date Date in d/m/Y or d-m-Y form.
     * @return string|null ISO date or null if invalid.
     */
    public static function uk_date_to_iso( $uk_date ) {
        $uk_date = trim( (string) $uk_date );
        if ( ! preg_match( '/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $uk_date, $m ) ) {
            return null;
        }

        $day   = (int) $m[1];
        $month = (int) $m[2];
        $year  = (int) $m[3];

        if ( ! checkdate( $month, $day, $year ) ) {
            return null;
        }

        return sprintf( '%04d-%02d-%02d', $year, $month, $day );
    }

    /**
     * Build the OSM member + contact payload from validated form input.
     *
     * @param array $input Sanitised form values.
     * @return array{member: array, contact1: array, contact2: ?array, member_details: array}
     */
    public static function build_osm_payload( array $input ) {
        $dob_iso = self::uk_date_to_iso( $input['child_dob'] );
        $today   = date( 'Y-m-d' );

        $member = [
            'firstname' => $input['child_first_name'],
            'lastname'  => $input['child_last_name'],
            'dob'       => $dob_iso,
            'started'   => $today,
            'startedsection' => $today,
        ];

        // Member's own details (group_id 6): address and postcode.
        // Field names from OSM customdata core columns (line_1, line_3, postcode).
        // Source: ollytheninja/OSM-API-Docs (Apiary) customdata getData response.
        $member_details = [
            'postcode' => strtoupper( preg_replace( '/\s+/', ' ', trim( $input['child_postcode'] ) ) ),
        ];
        if ( ! self::is_blank( $input['child_address'] ?? '' ) ) {
            $member_details['line_1'] = $input['child_address'];
        }
        if ( ! self::is_blank( $input['child_town'] ?? '' ) ) {
            $member_details['line_3'] = $input['child_town'];
        }

        // Primary Contact 1 (group_id 1).
        // Field names from newcastlescouts/osm-api-docs contact update example.
        $contact1 = [
            'firstname' => $input['parent1_first_name'],
            'lastname'  => $input['parent1_last_name'],
            'email1'    => $input['parent1_email'],
            'phone1'    => $input['parent1_phone'],
        ];

        $contact2 = null;
        $p2_any = ! self::is_blank( $input['parent2_first_name'] ?? '' )
            || ! self::is_blank( $input['parent2_last_name'] ?? '' )
            || ! self::is_blank( $input['parent2_email'] ?? '' )
            || ! self::is_blank( $input['parent2_phone'] ?? '' );

        if ( $p2_any ) {
            $contact2 = [
                'firstname' => $input['parent2_first_name'],
                'lastname'  => $input['parent2_last_name'],
                'email1'    => $input['parent2_email'],
            ];
            if ( ! self::is_blank( $input['parent2_phone'] ?? '' ) ) {
                $contact2['phone1'] = $input['parent2_phone'];
            }
        }

        $payload = [
            'member'         => $member,
            'member_details' => $member_details,
            'contact1'       => $contact1,
            'contact2'       => $contact2,
        ];

        // Optional free-text note. OSM Helper writes it to the waiting list's mapped Notes field;
        // if that fails the child is still added and Helper reports a partial success.
        $note = self::clean_note( $input['parent_note'] ?? '' );
        if ( $note !== '' ) {
            $payload['parent_note'] = $note;
        }

        return $payload;
    }

    /**
     * Plain-text parent note: tags and control characters removed, line breaks kept, trimmed,
     * capped at PARENT_NOTE_MAX characters.
     *
     * @param mixed $raw Raw or sanitised note.
     * @param bool  $cap Cut to PARENT_NOTE_MAX (false when validating, so the visitor is told instead).
     * @return string
     */
    public static function clean_note( $raw, $cap = true ) {
        if ( ! is_string( $raw ) ) {
            return '';
        }
        $note = str_replace( [ "\r\n", "\r" ], "\n", $raw );
        $note = strip_tags( $note );
        $note = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $note );
        $note = trim( $note );
        if ( $cap && self::text_length( $note ) > self::PARENT_NOTE_MAX ) {
            $note = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, self::PARENT_NOTE_MAX, 'UTF-8' ) : substr( $note, 0, self::PARENT_NOTE_MAX );
            $note = rtrim( $note );
        }
        return $note;
    }

    /**
     * Configured address lookup: off (default), google, or postcodes_io.
     *
     * @return string
     */
    public static function address_lookup_mode() {
        if ( ! function_exists( 'get_option' ) ) {
            return 'off';
        }
        $mode = get_option( 'osm_wl_address_lookup', 'off' );
        return in_array( $mode, self::ADDRESS_LOOKUP_MODES, true ) ? $mode : 'off';
    }

    /**
     * Upper-case a UK postcode and put a single space before the inward code.
     *
     * @param string $postcode Raw postcode.
     * @return string
     */
    public static function normalise_postcode( $postcode ) {
        $compact = strtoupper( (string) preg_replace( '/\s+/', '', (string) $postcode ) );
        if ( strlen( $compact ) > 3 ) {
            return substr( $compact, 0, -3 ) . ' ' . substr( $compact, -3 );
        }
        return $compact;
    }

    /**
     * postcodes.io validate URL for a postcode.
     *
     * @param string $postcode Postcode.
     * @return string
     */
    public static function postcodes_io_validate_url( $postcode ) {
        $compact = strtoupper( (string) preg_replace( '/\s+/', '', (string) $postcode ) );
        return self::POSTCODES_IO_BASE . rawurlencode( $compact ) . '/validate';
    }

    /**
     * Read a postcodes.io /validate reply. True = real postcode, false = postcodes.io says it does
     * not exist, null = unknown (error, timeout, odd reply). Callers fail open on null.
     *
     * @param int   $status  HTTP status (0 when the request failed).
     * @param mixed $decoded Decoded JSON body.
     * @return bool|null
     */
    public static function interpret_postcodes_io_validate( $status, $decoded ) {
        if ( (int) $status !== 200 || ! is_array( $decoded ) || ! array_key_exists( 'result', $decoded ) ) {
            return null;
        }
        if ( $decoded['result'] === true ) {
            return true;
        }
        if ( $decoded['result'] === false ) {
            return false;
        }
        return null;
    }

    /**
     * Whether the optional server-side postcodes.io check is on (only used in postcodes.io mode).
     *
     * @return bool
     */
    public static function postcode_server_check_enabled() {
        if ( ! function_exists( 'get_option' ) ) {
            return false;
        }
        return self::address_lookup_mode() === 'postcodes_io' && get_option( 'osm_wl_postcode_server_check', '0' ) === '1';
    }

    /**
     * Ask postcodes.io whether a postcode exists. Short timeout, fails open (null) on any problem.
     *
     * @param string $postcode Postcode already matching the local UK pattern.
     * @return bool|null
     */
    private static function postcode_exists_remote( $postcode ) {
        if ( ! function_exists( 'wp_remote_get' ) ) {
            return null;
        }
        $response = wp_remote_get(
            self::postcodes_io_validate_url( $postcode ),
            [
                'timeout'     => self::POSTCODES_IO_TIMEOUT,
                'redirection' => 0,
                'headers'     => [ 'Accept' => 'application/json' ],
            ]
        );
        if ( is_wp_error( $response ) ) {
            return null;
        }
        return self::interpret_postcodes_io_validate(
            (int) wp_remote_retrieve_response_code( $response ),
            json_decode( (string) wp_remote_retrieve_body( $response ), true )
        );
    }

    /**
     * Visitor message after OSM Helper confirmed the child was added.
     *
     * @param bool   $email_sent  Whether a confirmation email went out.
     * @param bool   $note_given  Whether the parent typed a note.
     * @param string $note_status Helper's note_status (none, written, skipped, failed).
     * @param bool   $has_reply_to Whether replies to the confirmation reach the group.
     * @return string
     */
    public static function success_message( $email_sent, $note_given, $note_status, $has_reply_to ) {
        $message = 'Thank you. Your child has been added to the waiting list.';
        if ( $email_sent ) {
            $message .= ' We have emailed you a confirmation.';
        }
        if ( $note_given && in_array( $note_status, [ 'skipped', 'failed' ], true ) ) {
            $message .= ( $email_sent && $has_reply_to )
                ? ' We could not save your note automatically, so please reply to the confirmation email with it.'
                : ' We could not save your note automatically, so please send it to the group directly.';
        }
        return $message;
    }

    /**
     * Handle a public form submission.
     *
     * On success the child is written to OSM and nothing is stored in WordPress.
     *
     * @param array $post $_POST data.
     * @return array{success: bool, message: string, errors?: array, values?: array}
     */
    public static function handle_submission( array $post ) {
        $values = self::sanitise_input( $post );

        // Honeypot: silently pretend success so bots are not informed.
        if ( ! self::is_blank( $post[ self::HONEYPOT_FIELD ] ?? '' ) ) {
            return [
                'success' => true,
                'message' => 'Thank you. Your child has been added to the waiting list.',
                'values'  => [],
            ];
        }

        if ( ! isset( $post['osm_waiting_list_nonce'] ) || ! wp_verify_nonce( $post['osm_waiting_list_nonce'], 'osm_waiting_list_submit' ) ) {
            return [
                'success' => false,
                'message' => 'Sorry, we could not verify this form submission. Please try again.',
                'errors'  => [],
                'values'  => $values,
            ];
        }

        if ( self::is_rate_limited() ) {
            return [
                'success' => false,
                'message' => 'Too many submissions from your connection. Please wait a while and try again.',
                'errors'  => [],
                'values'  => $values,
            ];
        }

        if ( ! OSM_Helper_Client::is_configured() ) {
            return [
                'success' => false,
                'message' => 'The waiting list is not configured yet. Please contact the group.',
                'errors'  => [],
                'values'  => $values,
            ];
        }

        $errors = self::validate( $values );

        // Optional postcodes.io existence check (fails open: only a definite "no" blocks).
        if ( empty( $errors['child_postcode'] ) && self::postcode_server_check_enabled() ) {
            if ( self::postcode_exists_remote( $values['child_postcode'] ) === false ) {
                $errors['child_postcode'] = 'We could not find that postcode. Please check it and try again.';
            }
        }

        if ( ! empty( $errors ) ) {
            return [
                'success' => false,
                'message' => 'Please correct the highlighted fields and try again.',
                'errors'  => $errors,
                'values'  => $values,
            ];
        }

        // Captcha is verified before any OSM call. Off keeps the honeypot and rate limit only.
        $captcha_error = self::enforce_captcha( $post );
        if ( $captcha_error !== null ) {
            return [
                'success' => false,
                'message' => 'Please correct the highlighted fields and try again.',
                'errors'  => [ 'captcha' => $captcha_error ],
                'values'  => $values,
            ];
        }

        try {
            $payload = self::build_osm_payload( $values );
            // Captcha already verified. Pass through to OSM Helper; do not store in WordPress.
            $helper = OSM_Helper_Client::submit_waiting_list( $payload );
            self::record_rate_limit_hit();
        } catch ( Exception $e ) {
            // Do not leak OSM credentials or raw API bodies to visitors.
            error_log( 'OSM waiting list submission failed: ' . $e->getMessage() );

            return [
                'success' => false,
                'message' => 'Sorry, we could not add your child to the waiting list right now. Please try again later or contact the group.',
                'errors'  => [],
                'values'  => $values,
            ];
        }

        // OSM Helper confirmed the member exists. Nothing below may turn this into a failure.
        $note_given  = isset( $payload['parent_note'] );
        $note_status = (string) ( $helper['note_status'] ?? 'none' );
        if ( ! empty( $helper['partial'] ) ) {
            // No child or parent details in the log.
            error_log(
                'OSM waiting list: child added (OSM scoutid ' . (int) ( $helper['scoutid'] ?? 0 ) . ') but the parent note was '
                . $note_status . '. ' . implode( ' ', array_map( 'strval', (array) ( $helper['warnings'] ?? [] ) ) )
            );
        }

        $email_sent = false;
        try {
            $email_sent = OSM_Waiting_List_Email::send_confirmation( $values );
        } catch ( Throwable $e ) {
            error_log( 'OSM waiting list: confirmation email failed after a successful OSM write.' );
        }

        return [
            'success' => true,
            'partial' => ! empty( $helper['partial'] ),
            'message' => self::success_message( $email_sent, $note_given, $note_status, OSM_Waiting_List_Email::reply_to() !== '' ),
            'values'  => [],
        ];
    }

    /**
     * Sanitise posted form fields for redisplay and validation.
     *
     * @param array $post Raw POST data.
     * @return array
     */
    public static function sanitise_input( array $post ) {
        $fields = [
            'child_first_name',
            'child_last_name',
            'child_dob',
            'child_postcode',
            'child_address',
            'child_town',
            'parent1_first_name',
            'parent1_last_name',
            'parent1_email',
            'parent1_phone',
            'parent2_first_name',
            'parent2_last_name',
            'parent2_email',
            'parent2_phone',
        ];

        $out = [];
        foreach ( $fields as $field ) {
            $raw = $post[ $field ] ?? '';
            if ( function_exists( 'sanitize_text_field' ) ) {
                $out[ $field ] = sanitize_text_field( wp_unslash( $raw ) );
            } else {
                $out[ $field ] = trim( strip_tags( (string) $raw ) );
            }
        }

        if ( function_exists( 'sanitize_email' ) ) {
            $out['parent1_email'] = sanitize_email( wp_unslash( $post['parent1_email'] ?? '' ) );
            $out['parent2_email'] = sanitize_email( wp_unslash( $post['parent2_email'] ?? '' ) );
        }

        $raw_note = $post['parent_note'] ?? '';
        $raw_note = is_string( $raw_note ) ? $raw_note : '';
        if ( function_exists( 'sanitize_textarea_field' ) ) {
            $out['parent_note'] = self::clean_note( sanitize_textarea_field( wp_unslash( $raw_note ) ), false );
        } else {
            $out['parent_note'] = self::clean_note( $raw_note, false );
        }

        $out['consent'] = isset( $post['consent'] ) ? '1' : '';

        return $out;
    }

    /**
     * Consent checkbox label (UK English).
     *
     * @return string
     */
    public static function consent_label() {
        return 'I agree that the group may store these details in Online Scout Manager to manage the waiting list.';
    }

    /**
     * Configured spam-protection mode: off, recaptcha, or turnstile.
     *
     * @return string
     */
    public static function captcha_mode() {
        if ( ! function_exists( 'get_option' ) ) {
            return 'off';
        }
        $mode = get_option( 'osm_waiting_list_captcha', 'off' );
        if ( ! in_array( $mode, [ 'off', 'recaptcha', 'turnstile' ], true ) ) {
            return 'off';
        }
        return $mode;
    }

    /**
     * Captcha off does not require a token. reCAPTCHA and Turnstile do.
     *
     * @param string $mode Captcha mode.
     * @return bool
     */
    public static function captcha_requires_token( $mode ) {
        return $mode === 'recaptcha' || $mode === 'turnstile';
    }

    /**
     * Token field posted by the widget. Empty when captcha is off.
     *
     * @param string $mode Captcha mode.
     * @param array  $post Posted fields.
     * @return string
     */
    public static function captcha_token_from_post( $mode, array $post ) {
        if ( $mode === 'recaptcha' ) {
            return trim( (string) ( $post['g-recaptcha-response'] ?? '' ) );
        }
        if ( $mode === 'turnstile' ) {
            return trim( (string) ( $post['cf-turnstile-response'] ?? '' ) );
        }
        return '';
    }

    /**
     * True when this mode would block a Helper/OSM write because no token was posted.
     * Does not call the captcha provider.
     *
     * @param string $mode Captcha mode.
     * @param array  $post Posted fields.
     * @return bool
     */
    public static function captcha_blocks_osm( $mode, array $post ) {
        if ( ! self::captcha_requires_token( $mode ) ) {
            return false;
        }
        return self::captcha_token_from_post( $mode, $post ) === '';
    }

    /**
     * Whether a siteverify JSON body says the token was accepted.
     *
     * @param mixed $decoded Decoded JSON.
     * @return bool
     */
    public static function captcha_verify_succeeded( $decoded ) {
        return is_array( $decoded ) && ! empty( $decoded['success'] );
    }

    /**
     * Verify captcha server-side. Returns an error string, or null when the
     * submission may continue to OSM. Does not log secrets.
     *
     * @param array $post Posted fields.
     * @return string|null
     */
    private static function enforce_captcha( array $post ) {
        $mode = self::captcha_mode();
        if ( ! self::captcha_requires_token( $mode ) ) {
            return null;
        }

        $token = self::captcha_token_from_post( $mode, $post );
        if ( $token === '' ) {
            return 'Please complete the spam check and try again.';
        }

        $secret_option = $mode === 'recaptcha' ? 'osm_recaptcha_secret_key' : 'osm_turnstile_secret_key';
        $secret = get_option( $secret_option, '' );
        if ( ! is_string( $secret ) || $secret === '' ) {
            return 'The spam check is not configured yet. Please contact the group.';
        }

        $url = $mode === 'recaptcha'
            ? 'https://www.google.com/recaptcha/api/siteverify'
            : 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

        $response = wp_remote_post(
            $url,
            [
                'timeout'     => 15,
                'redirection' => 0,
                'headers'     => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body'        => http_build_query(
                    [
                        'secret'   => $secret,
                        'response' => $token,
                        'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
                    ]
                ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'OSM waiting list captcha verification failed to reach the provider.' );
            return 'Sorry, the spam check could not be verified. Please try again.';
        }

        $decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! self::captcha_verify_succeeded( $decoded ) ) {
            return 'Please complete the spam check and try again.';
        }

        return null;
    }

    private static function text_length( $value ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
    }

    private static function is_blank( $value ) {
        return trim( (string) $value ) === '';
    }

    private static function is_valid_uk_date( $value ) {
        return self::uk_date_to_iso( $value ) !== null;
    }

    private static function is_valid_email( $value ) {
        return (bool) filter_var( trim( (string) $value ), FILTER_VALIDATE_EMAIL );
    }

    private static function is_valid_phone( $value ) {
        $digits = preg_replace( '/[^\d+]/', '', (string) $value );
        $digit_count = strlen( preg_replace( '/\D/', '', $digits ) );
        return $digit_count >= 10 && $digit_count <= 15;
    }

    private static function is_valid_uk_postcode( $value ) {
        $postcode = strtoupper( preg_replace( '/\s+/', '', (string) $value ) );
        // Permissive UK postcode pattern (outward + inward codes).
        return (bool) preg_match( '/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $postcode );
    }

    private static function rate_limit_key() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return 'osm_wl_rl_' . md5( $ip );
    }

    private static function is_rate_limited() {
        $hits = (int) get_transient( self::rate_limit_key() );
        return $hits >= self::RATE_LIMIT_MAX;
    }

    private static function record_rate_limit_hit() {
        $key  = self::rate_limit_key();
        $hits = (int) get_transient( $key );
        set_transient( $key, $hits + 1, self::RATE_LIMIT_WINDOW );
    }
}
