<?php
/**
 * Offline end-to-end tests for the waiting-list submit flow with stubbed WordPress functions.
 * No network: OSM Helper, postcodes.io, captcha providers and wp_mail are all fakes.
 *
 * Run: php src/tests/waiting-list-flow-test.php
 */

declare(strict_types=1);

// ---------------------------------------------------------------- WordPress stubs
$GLOBALS['wp_options']    = [];
$GLOBALS['wp_transients'] = [];
$GLOBALS['wp_mail_log']   = [];
$GLOBALS['wp_http_log']   = [];
$GLOBALS['wp_filters']    = [];
$GLOBALS['wp_scripts']    = [];
$GLOBALS['wp_localized']  = [];
$GLOBALS['fake_helper']   = null; // [status, body array]
$GLOBALS['fake_postcode'] = null; // [status, body array] or 'error'
$GLOBALS['fake_captcha']  = [ 'success' => true ];

define( 'ABSPATH', __DIR__ );
define( 'OSM_ASSETS_URI', 'https://example.org/wp-content/plugins/osm-for-wordpress/assets' );
define( 'OSM_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['wp_options'] ) ? $GLOBALS['wp_options'][ $name ] : $default;
}
function get_transient( $key ) {
    return $GLOBALS['wp_transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl = 0 ) {
    $GLOBALS['wp_transients'][ $key ] = $value;
    return true;
}
function wp_verify_nonce( $nonce, $action ) {
    return $nonce === 'good-nonce' ? 1 : false;
}
function wp_unslash( $value ) {
    return $value;
}
function sanitize_text_field( $value ) {
    return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
}
function sanitize_textarea_field( $value ) {
    return trim( strip_tags( (string) $value ) );
}
function sanitize_email( $value ) {
    return trim( (string) $value );
}
function wp_json_encode( $data ) {
    return json_encode( $data );
}
function is_wp_error( $thing ) {
    return $thing === 'WP_ERROR';
}
function wp_remote_retrieve_response_code( $response ) {
    return $response['code'];
}
function wp_remote_retrieve_body( $response ) {
    return $response['body'];
}
function wp_remote_request( $url, $args ) {
    $GLOBALS['wp_http_log'][] = [ 'url' => $url, 'args' => $args ];
    [ $code, $body ] = $GLOBALS['fake_helper'];
    return [ 'code' => $code, 'body' => json_encode( $body ) ];
}
function wp_remote_get( $url, $args ) {
    $GLOBALS['wp_http_log'][] = [ 'url' => $url, 'args' => $args ];
    if ( $GLOBALS['fake_postcode'] === 'error' ) {
        return 'WP_ERROR';
    }
    [ $code, $body ] = $GLOBALS['fake_postcode'];
    return [ 'code' => $code, 'body' => json_encode( $body ) ];
}
function wp_remote_post( $url, $args ) {
    $GLOBALS['wp_http_log'][] = [ 'url' => $url, 'args' => $args ];
    return [ 'code' => 200, 'body' => json_encode( $GLOBALS['fake_captcha'] ) ];
}
function add_filter( $hook, $cb, $priority = 10 ) {
    $GLOBALS['wp_filters'][ $hook ][] = $cb;
    return true;
}
function remove_filter( $hook, $cb, $priority = 10 ) {
    $GLOBALS['wp_filters'][ $hook ] = array_values( array_filter( $GLOBALS['wp_filters'][ $hook ] ?? [], static fn( $f ) => $f !== $cb ) );
    return true;
}
function wp_mail( $to, $subject, $message, $headers = [] ) {
    $from_name = 'WordPress';
    foreach ( $GLOBALS['wp_filters']['wp_mail_from_name'] ?? [] as $cb ) {
        $from_name = $cb( $from_name );
    }
    $GLOBALS['wp_mail_log'][] = compact( 'to', 'subject', 'message', 'headers', 'from_name' );
    return true;
}
function get_bloginfo( $what ) {
    return '4th Ashby Scouts';
}
function home_url( $path = '' ) {
    return 'https://example.org' . $path;
}
function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $args = [] ) {
    $GLOBALS['wp_scripts'][ $handle ] = compact( 'src', 'deps', 'args' );
}
function wp_localize_script( $handle, $name, $data ) {
    $GLOBALS['wp_localized'][ $handle ] = [ 'name' => $name, 'data' => $data ];
}
function add_query_arg( array $args, $url ) {
    return $url . '?' . http_build_query( $args );
}
function add_shortcode( $tag, $cb ) {}

require_once dirname( __DIR__ ) . '/includes/class-osm-waiting-list.php';
require_once dirname( __DIR__ ) . '/includes/class-osm-waiting-list-email.php';
require_once dirname( __DIR__ ) . '/includes/class-osm-helper-client.php';
require_once dirname( __DIR__ ) . '/includes/class-osm-shortcodes.php';

// ---------------------------------------------------------------- helpers
$failures = 0;
function check( $condition, string $message ): void {
    global $failures;
    if ( $condition ) {
        echo "PASS: {$message}\n";
    } else {
        echo "FAIL: {$message}\n";
        $failures++;
    }
}
function reset_world( array $options = [] ): void {
    $GLOBALS['wp_options'] = array_merge(
        [
            'osm_helper_base_url' => 'https://osmhelper.co.uk',
            'osm_helper_site_key' => 'test-site-key',
        ],
        $options
    );
    $GLOBALS['wp_transients'] = [];
    $GLOBALS['wp_mail_log']   = [];
    $GLOBALS['wp_http_log']   = [];
    $GLOBALS['wp_filters']    = [];
    $GLOBALS['wp_scripts']    = [];
    $GLOBALS['wp_localized']  = [];
    $GLOBALS['fake_helper']   = [ 200, [ 'ok' => true, 'scoutid' => 4242, 'partial' => false, 'note_status' => 'none', 'warnings' => [] ] ];
    $GLOBALS['fake_postcode'] = [ 200, [ 'status' => 200, 'result' => true ] ];
    $GLOBALS['fake_captcha']  = [ 'success' => true ];
}
function post( array $extra = [] ): array {
    return array_merge(
        [
            'osm_waiting_list_nonce' => 'good-nonce',
            'osm_waiting_list_submit' => '1',
            'osm_wl_website'     => '',
            'child_first_name'   => 'Jamie',
            'child_last_name'    => 'River',
            'child_dob'          => '15/03/2018',
            'child_postcode'     => 'LE65 1AB',
            'child_address'      => '1 High Street',
            'child_town'         => 'Ashby',
            'parent1_first_name' => 'Alex',
            'parent1_last_name'  => 'River',
            'parent1_email'      => 'alex@example.org',
            'parent1_phone'      => '07700900123',
            'consent'            => '1',
        ],
        $extra
    );
}
function helper_calls(): array {
    return array_values( array_filter( $GLOBALS['wp_http_log'], static fn( $c ) => str_contains( $c['url'], 'osmhelper' ) ) );
}

// ---------------------------------------------------------------- 1. success + note written + email
reset_world( [ 'osm_wl_email_reply_to' => 'waiting@example.org', 'osm_wl_email_from_name' => '4th Ashby Waiting List' ] );
$GLOBALS['fake_helper'] = [ 200, [ 'ok' => true, 'scoutid' => 4242, 'partial' => false, 'note_status' => 'written', 'warnings' => [] ] ];
$res = OSM_Waiting_List::handle_submission( post( [ 'parent_note' => "Sibling in Cubs\nTuesday please", 'parent2_first_name' => 'Sam', 'parent2_last_name' => 'River', 'parent2_email' => 'sam@example.org' ] ) );
check( $res['success'] === true && empty( $res['partial'] ), 'success with note written' );
$calls = helper_calls();
check( count( $calls ) === 1, 'one OSM Helper call' );
$sent = json_decode( $calls[0]['args']['body'], true );
check( ( $sent['parent_note'] ?? '' ) === "Sibling in Cubs\nTuesday please", 'note passed through to OSM Helper' );
check( ! str_contains( $calls[0]['url'], 'test-site-key' ), 'site key is not in the URL' );
check( count( $GLOBALS['wp_mail_log'] ) === 2, 'confirmation sent to both parents separately' );
$m1 = $GLOBALS['wp_mail_log'][0];
check( $m1['to'] === 'alex@example.org' && str_contains( $m1['message'], 'Dear Alex,' ), 'parent 1 email personalised' );
check( $GLOBALS['wp_mail_log'][1]['to'] === 'sam@example.org' && str_contains( $GLOBALS['wp_mail_log'][1]['message'], 'Dear Sam,' ), 'parent 2 email personalised' );
check( $m1['subject'] === 'We have received your waiting list request for Jamie', 'subject placeholder rendered' );
check( in_array( 'Reply-To: waiting@example.org', $m1['headers'], true ), 'Reply-To header set' );
check( $m1['from_name'] === '4th Ashby Waiting List', 'From name applied while sending' );
check( empty( $GLOBALS['wp_filters']['wp_mail_from_name'] ), 'From name filter removed afterwards' );
check( str_contains( $res['message'], 'emailed you a confirmation' ) && ! str_contains( $res['message'], 'could not save' ), 'visitor told about the email, no note warning' );
check( (int) $GLOBALS['wp_transients'][ array_key_first( $GLOBALS['wp_transients'] ) ] === 1, 'rate limit hit recorded on success' );

// ---------------------------------------------------------------- 2. partial: note skipped
reset_world( [ 'osm_wl_email_reply_to' => 'waiting@example.org' ] );
$GLOBALS['fake_helper'] = [ 200, [ 'ok' => true, 'scoutid' => 4243, 'partial' => true, 'note_status' => 'skipped', 'warnings' => [ 'No Notes field.' ] ] ];
$res = OSM_Waiting_List::handle_submission( post( [ 'parent_note' => 'Hello' ] ) );
check( $res['success'] === true && $res['partial'] === true, 'note skipped → still success, marked partial' );
check( count( $GLOBALS['wp_mail_log'] ) === 1, 'email still sent on partial success' );
check( str_contains( $res['message'], 'reply to the confirmation email' ), 'visitor asked to reply with the note' );

// ---------------------------------------------------------------- 3. Helper failure → no email
reset_world();
$GLOBALS['fake_helper'] = [ 502, [ 'ok' => false, 'error' => 'Could not write the waiting-list member to OSM.' ] ];
$res = OSM_Waiting_List::handle_submission( post() );
check( $res['success'] === false, 'Helper HTTP 502 → failure' );
check( count( $GLOBALS['wp_mail_log'] ) === 0, 'no email when the OSM write failed (HTTP error)' );
check( ( $res['values']['child_first_name'] ?? '' ) === 'Jamie', 'values kept for redisplay on failure' );

reset_world();
$GLOBALS['fake_helper'] = [ 200, [ 'ok' => false, 'error' => 'nope' ] ];
$res = OSM_Waiting_List::handle_submission( post() );
check( $res['success'] === false && count( $GLOBALS['wp_mail_log'] ) === 0, 'no email when Helper does not confirm (ok:false)' );

reset_world();
$GLOBALS['fake_helper'] = [ 429, [ 'ok' => false, 'error' => 'rate limited' ] ];
$res = OSM_Waiting_List::handle_submission( post() );
check( $res['success'] === false && count( $GLOBALS['wp_mail_log'] ) === 0, 'no email on HTTP 429' );

// ---------------------------------------------------------------- 4. email disabled
reset_world( [ 'osm_wl_email_enabled' => '0' ] );
$res = OSM_Waiting_List::handle_submission( post() );
check( $res['success'] === true && count( $GLOBALS['wp_mail_log'] ) === 0, 'email off → none sent' );
check( ! str_contains( $res['message'], 'emailed' ), 'email off → message does not claim an email' );

reset_world( [ 'osm_wl_email_parent2' => '0' ] );
$res = OSM_Waiting_List::handle_submission( post( [ 'parent2_first_name' => 'Sam', 'parent2_last_name' => 'River', 'parent2_email' => 'sam@example.org' ] ) );
check( count( $GLOBALS['wp_mail_log'] ) === 1 && $GLOBALS['wp_mail_log'][0]['to'] === 'alex@example.org', 'parent 2 copy off → only parent 1' );

reset_world( [ 'osm_wl_email_subject' => 'Thanks {parent_first_name} – {child_first_name} is on the {group_name} list', 'osm_wl_group_name' => '4th Ashby' ] );
OSM_Waiting_List::handle_submission( post() );
check( $GLOBALS['wp_mail_log'][0]['subject'] === 'Thanks Alex – Jamie is on the 4th Ashby list', 'custom subject with placeholders' );

reset_world();
OSM_Waiting_List::handle_submission( post( [ 'child_first_name' => "Jamie\r\nBcc: evil@example.org" ] ) );
$subj = $GLOBALS['wp_mail_log'][0]['subject'] ?? '';
check( ! str_contains( $subj, "\n" ) && ! str_contains( $subj, "\r" ), 'no header injection through the subject' );

// ---------------------------------------------------------------- 5. honeypot, nonce, rate limit, validation
reset_world();
$res = OSM_Waiting_List::handle_submission( post( [ 'osm_wl_website' => 'spam' ] ) );
check( $res['success'] === true && count( helper_calls() ) === 0 && count( $GLOBALS['wp_mail_log'] ) === 0, 'honeypot: fake success, no Helper call, no email' );

reset_world();
$res = OSM_Waiting_List::handle_submission( post( [ 'osm_waiting_list_nonce' => 'bad' ] ) );
check( $res['success'] === false && count( helper_calls() ) === 0, 'bad nonce: no Helper call' );

reset_world();
$GLOBALS['wp_transients'][ 'osm_wl_rl_' . md5( 'unknown' ) ] = OSM_Waiting_List::RATE_LIMIT_MAX;
$res = OSM_Waiting_List::handle_submission( post() );
check( $res['success'] === false && count( helper_calls() ) === 0, 'rate limited: no Helper call' );

reset_world();
$res = OSM_Waiting_List::handle_submission( post( [ 'parent_note' => str_repeat( 'x', 1001 ) ] ) );
check( $res['success'] === false && isset( $res['errors']['parent_note'] ) && count( helper_calls() ) === 0, 'over-long note: form error, no Helper call' );

// ---------------------------------------------------------------- 6. postcodes.io server check
reset_world( [ 'osm_wl_address_lookup' => 'postcodes_io', 'osm_wl_postcode_server_check' => '1' ] );
$GLOBALS['fake_postcode'] = [ 200, [ 'status' => 200, 'result' => false ] ];
$res = OSM_Waiting_List::handle_submission( post( [ 'child_postcode' => 'ZZ99 9ZZ' ] ) );
check( $res['success'] === false && isset( $res['errors']['child_postcode'] ) && count( helper_calls() ) === 0, 'postcodes.io says no → postcode error, no Helper call' );
$pc_call = array_values( array_filter( $GLOBALS['wp_http_log'], static fn( $c ) => str_contains( $c['url'], 'postcodes.io' ) ) );
check( count( $pc_call ) === 1 && $pc_call[0]['url'] === 'https://api.postcodes.io/postcodes/ZZ999ZZ/validate' && $pc_call[0]['args']['timeout'] <= 5, 'validate endpoint called with a short timeout' );

reset_world( [ 'osm_wl_address_lookup' => 'postcodes_io', 'osm_wl_postcode_server_check' => '1' ] );
$GLOBALS['fake_postcode'] = 'error';
$res = OSM_Waiting_List::handle_submission( post() );
check( $res['success'] === true, 'postcodes.io unreachable → fails open' );

reset_world( [ 'osm_wl_address_lookup' => 'postcodes_io', 'osm_wl_postcode_server_check' => '1' ] );
$GLOBALS['fake_postcode'] = [ 500, [] ];
$res = OSM_Waiting_List::handle_submission( post() );
check( $res['success'] === true, 'postcodes.io HTTP 500 → fails open' );

reset_world( [ 'osm_wl_address_lookup' => 'postcodes_io' ] );
OSM_Waiting_List::handle_submission( post() );
check( count( array_filter( $GLOBALS['wp_http_log'], static fn( $c ) => str_contains( $c['url'], 'postcodes.io' ) ) ) === 0, 'server check off → no postcodes.io call' );

reset_world( [ 'osm_wl_address_lookup' => 'google', 'osm_wl_postcode_server_check' => '1' ] );
OSM_Waiting_List::handle_submission( post() );
check( count( array_filter( $GLOBALS['wp_http_log'], static fn( $c ) => str_contains( $c['url'], 'postcodes.io' ) ) ) === 0, 'server check ignored outside postcodes.io mode' );

// ---------------------------------------------------------------- 7. captcha still enforced
reset_world( [ 'osm_waiting_list_captcha' => 'turnstile', 'osm_turnstile_secret_key' => 'secret' ] );
$res = OSM_Waiting_List::handle_submission( post() );
check( $res['success'] === false && isset( $res['errors']['captcha'] ) && count( helper_calls() ) === 0, 'Turnstile without token: no Helper call' );

reset_world( [ 'osm_waiting_list_captcha' => 'turnstile', 'osm_turnstile_secret_key' => 'secret', 'osm_wl_address_lookup' => 'postcodes_io', 'osm_wl_postcode_server_check' => '1' ] );
$res = OSM_Waiting_List::handle_submission( post( [ 'cf-turnstile-response' => 'tok' ] ) );
check( $res['success'] === true && count( $GLOBALS['wp_mail_log'] ) === 1, 'Turnstile + postcodes.io check: success and email' );

reset_world( [ 'osm_waiting_list_captcha' => 'recaptcha', 'osm_recaptcha_secret_key' => 'secret' ] );
$GLOBALS['fake_captcha'] = [ 'success' => false ];
$res = OSM_Waiting_List::handle_submission( post( [ 'g-recaptcha-response' => 'tok' ] ) );
check( $res['success'] === false && count( helper_calls() ) === 0 && count( $GLOBALS['wp_mail_log'] ) === 0, 'reCAPTCHA failure: no Helper call, no email' );

// ---------------------------------------------------------------- 8. scripts only with the shortcode form
$enqueue = new ReflectionMethod( 'OSM_Shortcodes', 'enqueue_address_lookup' );

reset_world();
check( $enqueue->invoke( null, true ) === 'off' && $GLOBALS['wp_scripts'] === [], 'lookup off → no scripts' );

reset_world( [ 'osm_wl_address_lookup' => 'google' ] );
check( $enqueue->invoke( null, true ) === 'off' && $GLOBALS['wp_scripts'] === [], 'Google without key → manual entry, no scripts' );

reset_world( [ 'osm_wl_address_lookup' => 'google', 'osm_google_maps_api_key' => 'AIzaTEST' ] );
check( $enqueue->invoke( null, true ) === 'google', 'Google with key → google mode' );
$g = $GLOBALS['wp_scripts']['osm-google-places'] ?? null;
check( $g !== null && str_contains( $g['src'], 'libraries=places' ) && str_contains( $g['src'], 'callback=osmWlGoogleReady' ) && str_contains( $g['src'], 'key=AIzaTEST' ), 'Google Maps script with Places + callback' );
check( $g !== null && $g['deps'] === [ 'osm-waiting-list' ], 'Google script loads after the form script' );
check( ( $GLOBALS['wp_localized']['osm-waiting-list']['data']['mode'] ?? '' ) === 'google', 'config passes the mode' );

reset_world( [ 'osm_wl_address_lookup' => 'postcodes_io' ] );
check( $enqueue->invoke( null, true ) === 'postcodes_io' && isset( $GLOBALS['wp_scripts']['osm-waiting-list'] ) && ! isset( $GLOBALS['wp_scripts']['osm-google-places'] ), 'postcodes.io → form script only, no Google' );

reset_world( [ 'osm_wl_address_lookup' => 'postcodes_io' ] );
check( $enqueue->invoke( null, false ) === 'off' && $GLOBALS['wp_scripts'] === [], 'no form on the page (e.g. after success) → no scripts' );

// ---------------------------------------------------------------- 9. address + receive texts end to end
reset_world();
$res = OSM_Waiting_List::handle_submission( post( [ 'child_address2' => 'Packington', 'child_county' => 'Leicestershire', 'parent1_sms' => '1', 'parent2_first_name' => 'Sam', 'parent2_last_name' => 'River', 'parent2_email' => 'sam@example.org', 'parent2_phone' => '07700900456' ] ) );
$sent = json_decode( helper_calls()[0]['args']['body'] ?? '{}', true );
check( $res['success'] === true, 'submission with full address and texts succeeds' );
check( ( $sent['member_details']['line_2'] ?? '' ) === 'Packington' && ( $sent['member_details']['line_4'] ?? '' ) === 'Leicestershire' && ( $sent['member_details']['line_1'] ?? '' ) === '1 High Street', 'line 1, line 2 and county sent to Helper' );
check( ( $sent['contact1']['phone1_sms'] ?? '' ) === 'yes' && ! isset( $sent['contact2']['phone1_sms'] ), 'per-parent texts flag: parent 1 yes, parent 2 unset' );
check( str_contains( $GLOBALS['wp_mail_log'][0]['message'], 'Receive text messages from leaders: Yes' ) && str_contains( $GLOBALS['wp_mail_log'][1]['message'], 'Receive text messages from leaders: No' ), 'email shows each parent’s texts choice' );

reset_world();
$res = OSM_Waiting_List::handle_submission( post( [ 'child_address' => '' ] ) );
check( $res['success'] === false && isset( $res['errors']['child_address'] ) && count( helper_calls() ) === 0, 'missing address line 1: error, no Helper call' );

if ( $failures > 0 ) {
    echo "\n{$failures} failure(s)\n";
    exit( 1 );
}
echo "\nAll flow tests passed.\n";
exit( 0 );
