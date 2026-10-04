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

        return [
            'member'         => $member,
            'member_details' => $member_details,
            'contact1'       => $contact1,
            'contact2'       => $contact2,
        ];
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

        $section_id = get_option( 'osm_waiting_list_section_id', '' );
        if ( ! is_numeric( $section_id ) || (int) $section_id <= 0 ) {
            return [
                'success' => false,
                'message' => 'The waiting list is not configured yet. Please contact the group.',
                'errors'  => [],
                'values'  => $values,
            ];
        }

        $errors = self::validate( $values );
        if ( ! empty( $errors ) ) {
            return [
                'success' => false,
                'message' => 'Please correct the highlighted fields and try again.',
                'errors'  => $errors,
                'values'  => $values,
            ];
        }

        try {
            $payload = self::build_osm_payload( $values );
            OSM_API::create_waiting_list_member( (string) $section_id, $payload );
            self::record_rate_limit_hit();

            return [
                'success' => true,
                'message' => 'Thank you. Your child has been added to the waiting list.',
                'values'  => [],
            ];
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
