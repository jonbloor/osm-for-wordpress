<?php
/**
 * Confirmation email to parents after OSM Helper confirms a waiting-list write.
 *
 * Sent with wp_mail as plain text. Never sent when the OSM write failed, and the
 * submission is not stored in WordPress (values are only used to build the email).
 *
 * This plugin is not affiliated with Online Scout Manager.
 */
class OSM_Waiting_List_Email {
    const PLACEHOLDERS = [
        '{parent_first_name}' => 'First name of the parent receiving the email',
        '{child_first_name}'  => 'Child’s first name',
        '{child_last_name}'   => 'Child’s last name',
        '{group_name}'        => 'Group name (setting above, or the site title)',
        '{site_name}'         => 'WordPress site title',
        '{site_url}'          => 'Website address',
        '{submitted_date}'    => 'Date the form was sent (for example 5 October 2026)',
        '{parent_note}'       => 'The note the parent typed (blank if none)',
    ];

    /**
     * @return string
     */
    public static function default_subject() {
        return 'We have received your waiting list request for {child_first_name}';
    }

    /**
     * @return string
     */
    public static function default_body() {
        return "Dear {parent_first_name},\n\n"
            . "Thank you for adding {child_first_name} {child_last_name} to the waiting list for {group_name}. This email confirms that we received your details on {submitted_date}.\n\n"
            . "What happens next\n"
            . "- You do not need to apply again. {child_first_name} stays on our list until we contact you.\n"
            . "- Waiting times depend on your child’s age and how many places each section has.\n"
            . "- We will contact you by email or phone when a place becomes available.\n\n"
            . "If any of your details change, or you no longer need a place, please let us know.\n\n"
            . "Please do not send medical or other sensitive information by email.\n\n"
            . "If you did not fill in our waiting list form, please let us know.\n\n"
            . "Kind regards,\n"
            . "{group_name}\n"
            . "{site_url}\n";
    }

    /**
     * Enabled unless an admin turned it off (default on).
     *
     * @return bool
     */
    public static function is_enabled() {
        return self::option( 'osm_wl_email_enabled', '1' ) !== '0';
    }

    /**
     * Also email parent 2 when an address was given (default on).
     *
     * @return bool
     */
    public static function send_to_parent2() {
        return self::option( 'osm_wl_email_parent2', '1' ) !== '0';
    }

    /**
     * @return string
     */
    public static function group_name() {
        $name = trim( (string) self::option( 'osm_wl_group_name', '' ) );
        if ( $name === '' && function_exists( 'get_bloginfo' ) ) {
            $name = (string) get_bloginfo( 'name' );
        }
        return self::single_line( $name );
    }

    /**
     * From name; defaults to the group name.
     *
     * @return string
     */
    public static function from_name() {
        $name = trim( (string) self::option( 'osm_wl_email_from_name', '' ) );
        return $name !== '' ? self::single_line( $name ) : self::group_name();
    }

    /**
     * Valid reply-to address, or ''.
     *
     * @return string
     */
    public static function reply_to() {
        $email = trim( (string) self::option( 'osm_wl_email_reply_to', '' ) );
        return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
    }

    /**
     * @return string
     */
    public static function subject_template() {
        $subject = trim( (string) self::option( 'osm_wl_email_subject', '' ) );
        return $subject !== '' ? $subject : self::default_subject();
    }

    /**
     * @return string
     */
    public static function body_template() {
        $body = trim( (string) self::option( 'osm_wl_email_body', '' ) );
        return $body !== '' ? $body : self::default_body();
    }

    /**
     * Replace known {placeholders}. Unknown braces are left alone.
     *
     * @param string $template Template text.
     * @param array  $vars     placeholder name (without braces) => value.
     * @return string
     */
    public static function render( $template, array $vars ) {
        $map = [];
        foreach ( $vars as $key => $value ) {
            $map[ '{' . $key . '}' ] = (string) $value;
        }
        return strtr( (string) $template, $map );
    }

    /**
     * Placeholder values for one recipient.
     *
     * @param array  $values            Sanitised form values.
     * @param string $parent_first_name Recipient's first name.
     * @param int    $now               Timestamp.
     * @return array<string, string>
     */
    public static function vars( array $values, $parent_first_name, $now = null ) {
        $now = $now === null ? time() : (int) $now;
        return [
            'parent_first_name' => self::single_line( (string) $parent_first_name ),
            'child_first_name'  => self::single_line( (string) ( $values['child_first_name'] ?? '' ) ),
            'child_last_name'   => self::single_line( (string) ( $values['child_last_name'] ?? '' ) ),
            'group_name'        => self::group_name(),
            'site_name'         => function_exists( 'get_bloginfo' ) ? self::single_line( (string) get_bloginfo( 'name' ) ) : '',
            'site_url'          => function_exists( 'home_url' ) ? (string) home_url( '/' ) : '',
            'submitted_date'    => function_exists( 'wp_date' ) ? (string) wp_date( 'j F Y', $now ) : gmdate( 'j F Y', $now ),
            'parent_note'       => (string) ( $values['parent_note'] ?? '' ),
        ];
    }

    /**
     * Who gets the receipt: parent 1, plus parent 2 when enabled and a different valid email was given.
     *
     * @param array $values         Sanitised form values.
     * @param bool  $include_parent2 Whether to add parent 2.
     * @return array<int, array{email: string, first_name: string}>
     */
    public static function recipients( array $values, $include_parent2 = true ) {
        $out = [];
        $p1  = trim( (string) ( $values['parent1_email'] ?? '' ) );
        if ( filter_var( $p1, FILTER_VALIDATE_EMAIL ) ) {
            $out[] = [ 'email' => $p1, 'first_name' => (string) ( $values['parent1_first_name'] ?? '' ) ];
        }
        $p2 = trim( (string) ( $values['parent2_email'] ?? '' ) );
        if ( $include_parent2 && filter_var( $p2, FILTER_VALIDATE_EMAIL ) && strcasecmp( $p1, $p2 ) !== 0 ) {
            $out[] = [ 'email' => $p2, 'first_name' => (string) ( $values['parent2_first_name'] ?? '' ) ];
        }
        return $out;
    }

    /**
     * Mail headers (plain text, optional Reply-To).
     *
     * @return string[]
     */
    public static function headers() {
        $headers  = [ 'Content-Type: text/plain; charset=UTF-8' ];
        $reply_to = self::reply_to();
        if ( $reply_to !== '' ) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }
        return $headers;
    }

    /**
     * Send the receipt to each recipient separately (parents do not see each other's address).
     * Call only after OSM Helper confirmed success. Returns true when at least one email was accepted.
     *
     * @param array $values Sanitised form values.
     * @return bool
     */
    public static function send_confirmation( array $values ) {
        if ( ! self::is_enabled() || ! function_exists( 'wp_mail' ) ) {
            return false;
        }
        $recipients = self::recipients( $values, self::send_to_parent2() );
        if ( empty( $recipients ) ) {
            return false;
        }

        $from_name   = self::from_name();
        $from_filter = static function () use ( $from_name ) {
            return $from_name;
        };
        if ( $from_name !== '' ) {
            add_filter( 'wp_mail_from_name', $from_filter, 99 );
        }

        $any_sent = false;
        try {
            foreach ( $recipients as $recipient ) {
                $vars    = self::vars( $values, $recipient['first_name'] );
                $subject = self::single_line( self::render( self::subject_template(), $vars ) );
                $body    = self::render( self::body_template(), $vars );
                if ( wp_mail( $recipient['email'], $subject, $body, self::headers() ) ) {
                    $any_sent = true;
                } else {
                    error_log( 'OSM waiting list: wp_mail did not accept a confirmation email.' );
                }
            }
        } finally {
            if ( $from_name !== '' ) {
                remove_filter( 'wp_mail_from_name', $from_filter, 99 );
            }
        }
        return $any_sent;
    }

    /**
     * Strip line breaks (header-injection safe) and collapse spaces.
     *
     * @param string $value Text.
     * @return string
     */
    public static function single_line( $value ) {
        return trim( (string) preg_replace( '/[\r\n\t]+|\s{2,}/', ' ', (string) $value ) );
    }

    /**
     * @param string $name    Option name.
     * @param mixed  $default Default.
     * @return mixed
     */
    private static function option( $name, $default ) {
        return function_exists( 'get_option' ) ? get_option( $name, $default ) : $default;
    }
}
