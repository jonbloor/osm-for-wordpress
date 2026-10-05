<?php
/**
 * Pure-PHP validation tests for OSM_Waiting_List (no WordPress, no network).
 *
 * Run: php src/tests/waiting-list-validation-test.php
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/includes/class-osm-waiting-list.php';
require_once dirname( __DIR__ ) . '/includes/class-osm-helper-client.php';
require_once dirname( __DIR__ ) . '/includes/class-osm-api.php';
require_once dirname( __DIR__ ) . '/includes/class-osm-waiting-list-email.php';

$failures = 0;

function assert_true( $condition, string $message ): void {
    global $failures;
    if ( ! $condition ) {
        echo "FAIL: {$message}\n";
        $failures++;
    } else {
        echo "PASS: {$message}\n";
    }
}

function valid_base(): array {
    return [
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
        'parent2_first_name' => '',
        'parent2_last_name'  => '',
        'parent2_email'      => '',
        'parent2_phone'      => '',
        'consent'            => '1',
    ];
}

// Valid submission.
$errors = OSM_Waiting_List::validate( valid_base() );
assert_true( $errors === [], 'valid base input has no errors' );

// Missing required field.
$input = valid_base();
$input['child_first_name'] = '';
$errors = OSM_Waiting_List::validate( $input );
assert_true( isset( $errors['child_first_name'] ), 'missing child first name is rejected' );

// Invalid UK date.
$input = valid_base();
$input['child_dob'] = '2018-03-15';
$errors = OSM_Waiting_List::validate( $input );
assert_true( isset( $errors['child_dob'] ), 'ISO date is rejected (UK format required)' );

$input = valid_base();
$input['child_dob'] = '31/02/2018';
$errors = OSM_Waiting_List::validate( $input );
assert_true( isset( $errors['child_dob'] ), 'impossible calendar date is rejected' );

assert_true( OSM_Waiting_List::uk_date_to_iso( '15/03/2018' ) === '2018-03-15', 'UK date converts to ISO' );
assert_true( OSM_Waiting_List::uk_date_to_iso( '1-2-2020' ) === '2020-02-01', 'single-digit UK date converts' );

// Invalid postcode / email / phone.
$input = valid_base();
$input['child_postcode'] = 'not-a-postcode';
$errors = OSM_Waiting_List::validate( $input );
assert_true( isset( $errors['child_postcode'] ), 'invalid postcode is rejected' );

$input = valid_base();
$input['parent1_email'] = 'not-an-email';
$errors = OSM_Waiting_List::validate( $input );
assert_true( isset( $errors['parent1_email'] ), 'invalid email is rejected' );

$input = valid_base();
$input['parent1_phone'] = '123';
$errors = OSM_Waiting_List::validate( $input );
assert_true( isset( $errors['parent1_phone'] ), 'too-short phone is rejected' );

// Consent required.
$input = valid_base();
$input['consent'] = '';
$errors = OSM_Waiting_List::validate( $input );
assert_true( isset( $errors['consent'] ), 'missing consent is rejected' );

// Parent 2 partial fill requires first/last/email.
$input = valid_base();
$input['parent2_first_name'] = 'Sam';
$errors = OSM_Waiting_List::validate( $input );
assert_true( isset( $errors['parent2_last_name'] ) && isset( $errors['parent2_email'] ), 'partial parent 2 requires last name and email' );

$input = valid_base();
$input['parent2_first_name'] = 'Sam';
$input['parent2_last_name']  = 'River';
$input['parent2_email']      = 'sam@example.org';
$errors = OSM_Waiting_List::validate( $input );
assert_true( $errors === [], 'complete parent 2 is accepted' );

// Payload mapping.
$payload = OSM_Waiting_List::build_osm_payload( valid_base() );
assert_true( $payload['member']['firstname'] === 'Jamie', 'payload maps child firstname' );
assert_true( $payload['member']['dob'] === '2018-03-15', 'payload maps ISO dob' );
assert_true( $payload['member_details']['postcode'] === 'LE65 1AB', 'payload maps postcode' );
assert_true( $payload['member_details']['line_1'] === '1 High Street', 'payload maps address line_1' );
assert_true( $payload['member_details']['line_3'] === 'Ashby', 'payload maps town to line_3' );
assert_true( $payload['contact1']['email1'] === 'alex@example.org', 'payload maps parent1 email1' );
assert_true( $payload['contact2'] === null, 'payload omits empty parent2' );

$input = valid_base();
$input['parent2_first_name'] = 'Sam';
$input['parent2_last_name']  = 'River';
$input['parent2_email']      = 'sam@example.org';
$input['parent2_phone']      = '07700900456';
$payload = OSM_Waiting_List::build_osm_payload( $input );
assert_true( is_array( $payload['contact2'] ) && $payload['contact2']['phone1'] === '07700900456', 'payload maps parent2 phone1' );

assert_true(
    str_contains( OSM_Waiting_List::consent_label(), 'Online Scout Manager' ),
    'consent label mentions Online Scout Manager'
);

// Captcha off does not require a token and does not block a Helper write.
assert_true( OSM_Waiting_List::captcha_requires_token( 'off' ) === false, 'captcha off does not require a token' );
assert_true( OSM_Waiting_List::captcha_blocks_osm( 'off', [] ) === false, 'captcha off does not block when no token is posted' );
assert_true( OSM_Waiting_List::captcha_token_from_post( 'off', [] ) === '', 'captcha off ignores posted tokens' );
assert_true( OSM_Waiting_List::captcha_requires_token( 'recaptcha' ) === true, 'recaptcha requires a token' );
assert_true( OSM_Waiting_List::captcha_blocks_osm( 'recaptcha', [] ) === true, 'recaptcha without a token blocks' );
assert_true( OSM_Waiting_List::captcha_blocks_osm( 'turnstile', [ 'cf-turnstile-response' => 'token' ] ) === false, 'turnstile with a token is not blocked locally' );
assert_true( OSM_Waiting_List::captcha_verify_succeeded( [ 'success' => true ] ) === true, 'siteverify success is accepted' );
assert_true( OSM_Waiting_List::captcha_verify_succeeded( [ 'success' => false ] ) === false, 'siteverify failure is rejected' );

// OSM Helper client — no OSM OAuth required.
assert_true( OSM_Helper_Client::DEFAULT_BASE_URL === 'https://osmhelper.co.uk', 'default Helper base URL' );
assert_true( OSM_Helper_Client::SUBMIT_PATH === '/api/waiting-list/submit/', 'Helper submit path' );
assert_true(
    OSM_Helper_Client::submit_url( 'https://osmhelper.co.uk/api/waiting-list/submit' ) === 'https://osmhelper.co.uk/api/waiting-list/submit/',
    'Full endpoint pasted as base is not doubled'
);
assert_true(
    OSM_Helper_Client::submit_url( 'https://osmhelper.co.uk/api/waiting-list/submit/' ) === 'https://osmhelper.co.uk/api/waiting-list/submit/',
    'Full endpoint with slash pasted as base is not doubled'
);
assert_true(
    OSM_Helper_Client::submit_url( 'https://osmhelper.co.uk' ) === 'https://osmhelper.co.uk/api/waiting-list/submit/',
    'submit URL joins base and path'
);
assert_true(
    OSM_Helper_Client::submit_url( 'https://osmhelper.co.uk/' ) === 'https://osmhelper.co.uk/api/waiting-list/submit/',
    'submit URL trims trailing slash'
);

// Plugin must not expose OSM OAuth connect helpers.
assert_true( ! method_exists( 'OSM_API', 'build_authorize_url' ), 'OSM_API has no build_authorize_url' );
assert_true( ! method_exists( 'OSM_API', 'exchange_auth_code' ), 'OSM_API has no exchange_auth_code' );
assert_true( ! method_exists( 'OSM_API', 'create_waiting_list_member' ), 'OSM_API has no create_waiting_list_member' );
assert_true( ! defined( 'OSM_API::OAUTH_CALLBACK_ADMIN_PATH' ) && ! ( new ReflectionClass( 'OSM_API' ) )->hasConstant( 'OAUTH_CALLBACK_ADMIN_PATH' ), 'no OAuth callback constant' );

// A stored block refuses a request before any HTTP call.
$refused = false;
try {
    OSM_API::assert_requests_allowed( [ 'at' => 1, 'header' => '1' ] );
} catch ( Exception $e ) {
    $refused = strpos( $e->getMessage(), 'blocked' ) !== false;
}
assert_true( $refused, 'blocked flag refuses a request' );
$allowed = true;
try {
    OSM_API::assert_requests_allowed( false );
} catch ( Exception $e ) {
    $allowed = false;
}
assert_true( $allowed, 'missing block flag allows a request' );
assert_true( OSM_API::endpoint_is_removed( '/oauth/token', [ '/oauth/token' => [ 'date' => '2000-01-01' ] ] ) === true, 'removed endpoint is refused' );

$blocked_response = OSM_API::assess_response( 200, [ 'X-Blocked' => '1', 'X-RateLimit-Remaining' => '0' ], '{"ok":true}' );
assert_true( $blocked_response['blocked'] === true && $blocked_response['ok'] === false, 'X-Blocked fails the response and does not count as success' );
assert_true( isset( $blocked_response['rate_limit']['x-ratelimit-remaining'] ), 'X-RateLimit header is read' );

$limited = OSM_API::assess_response( 429, [ 'Retry-After' => '30' ], '{"error":"rate_limited"}' );
assert_true( $limited['ok'] === false && $limited['retry_after'] === '30', 'HTTP 429 keeps Retry-After and is not a success' );
assert_true( strpos( (string) $limited['error_message'], 'does not retry' ) !== false, 'HTTP 429 is not retried in a loop' );

$http_error = OSM_API::assess_response(
    401,
    [],
    '{"error":"invalid_client","error_description":"Client authentication failed"}'
);
assert_true( $http_error['ok'] === false, 'HTTP 401 is a failure' );
assert_true( strpos( (string) $http_error['error_message'], 'invalid_client' ) !== false, 'HTTP 401 surfaces error' );
assert_true( strpos( (string) $http_error['error_message'], 'Client authentication failed' ) !== false, 'HTTP 401 surfaces error_description' );

$future = OSM_API::assess_response( 200, [ 'X-Deprecated' => '2099-01-01' ], '{"items":[]}' );
assert_true( $future['ok'] === true && $future['deprecated'] === '2099-01-01' && $future['deprecated_removed'] === false, 'future X-Deprecated is surfaced but still usable' );
$past = OSM_API::assess_response( 200, [ 'X-Deprecated' => '2000-01-01' ], '{"items":[]}' );
assert_true( $past['deprecated_removed'] === true && $past['ok'] === false, 'past X-Deprecated refuses the endpoint' );

// --- Parent note ---
$input = valid_base();
$input['parent_note'] = str_repeat( 'a', 1000 );
assert_true( OSM_Waiting_List::validate( $input ) === [], 'note of 1000 characters is accepted' );
$input['parent_note'] = str_repeat( 'é', 1001 );
$errors = OSM_Waiting_List::validate( $input );
assert_true( isset( $errors['parent_note'] ), 'note over 1000 characters is rejected (multibyte counted as characters)' );
assert_true( OSM_Waiting_List::clean_note( "  <script>x</script>Brother in Cubs\x07\r\nThanks  " ) === "xBrother in Cubs\nThanks", 'note: tags and control characters stripped, line breaks kept' );
assert_true( OSM_Waiting_List::clean_note( [ 'x' ] ) === '', 'note: non-string becomes empty' );
assert_true( mb_strlen( OSM_Waiting_List::clean_note( str_repeat( 'b', 1200 ) ) ) === 1000, 'note: capped at 1000 for the payload' );
assert_true( mb_strlen( OSM_Waiting_List::clean_note( str_repeat( 'b', 1200 ), false ) ) === 1200, 'note: not capped when validating' );
$sanitised = OSM_Waiting_List::sanitise_input( valid_base() + [ 'parent_note' => "<b>Hi</b>\nthere" ] );
assert_true( $sanitised['parent_note'] === "Hi\nthere", 'sanitise_input cleans the note (no WordPress)' );
$payload = OSM_Waiting_List::build_osm_payload( valid_base() );
assert_true( ! array_key_exists( 'parent_note', $payload ), 'payload omits a blank note' );
$input = valid_base();
$input['parent_note'] = '  Sibling in Beavers ';
$payload = OSM_Waiting_List::build_osm_payload( $input );
assert_true( ( $payload['parent_note'] ?? null ) === 'Sibling in Beavers', 'payload carries the trimmed note' );

// --- Address lookup helpers ---
assert_true( OSM_Waiting_List::address_lookup_mode() === 'off', 'address lookup defaults to off' );
assert_true( OSM_Waiting_List::postcode_server_check_enabled() === false, 'server postcode check off by default' );
assert_true( OSM_Waiting_List::normalise_postcode( ' le651ab ' ) === 'LE65 1AB', 'postcode normalised with one space' );
assert_true( OSM_Waiting_List::normalise_postcode( 'sw1a  1aa' ) === 'SW1A 1AA', 'postcode spacing collapsed' );
assert_true( OSM_Waiting_List::postcodes_io_validate_url( 'LE65 1AB' ) === 'https://api.postcodes.io/postcodes/LE651AB/validate', 'postcodes.io validate URL' );
assert_true( OSM_Waiting_List::interpret_postcodes_io_validate( 200, [ 'status' => 200, 'result' => true ] ) === true, 'postcodes.io result true → valid' );
assert_true( OSM_Waiting_List::interpret_postcodes_io_validate( 200, [ 'status' => 200, 'result' => false ] ) === false, 'postcodes.io result false → invalid' );
assert_true( OSM_Waiting_List::interpret_postcodes_io_validate( 500, null ) === null, 'postcodes.io error → unknown (fail open)' );
assert_true( OSM_Waiting_List::interpret_postcodes_io_validate( 0, null ) === null, 'postcodes.io timeout → unknown (fail open)' );
assert_true( OSM_Waiting_List::interpret_postcodes_io_validate( 200, [ 'oops' => 1 ] ) === null, 'postcodes.io odd body → unknown' );

// --- Success messages ---
assert_true( OSM_Waiting_List::success_message( false, false, 'none', false ) === 'Thank you. Your child has been added to the waiting list.', 'plain success message' );
assert_true( str_contains( OSM_Waiting_List::success_message( true, false, 'none', false ), 'emailed you a confirmation' ), 'success message mentions email when sent' );
assert_true( str_contains( OSM_Waiting_List::success_message( true, true, 'failed', true ), 'reply to the confirmation email' ), 'failed note + reply-to → reply with note' );
assert_true( str_contains( OSM_Waiting_List::success_message( false, true, 'skipped', false ), 'send it to the group directly' ), 'skipped note without email → send directly' );
assert_true( ! str_contains( OSM_Waiting_List::success_message( true, true, 'written', true ), 'could not save' ), 'written note → no note warning' );

// --- Helper success parsing ---
$parsed = OSM_Helper_Client::parse_success( [ 'ok' => true, 'scoutid' => 12 ] );
assert_true( $parsed['scoutid'] === 12 && $parsed['partial'] === false && $parsed['note_status'] === 'none', 'old Helper reply (ok + scoutid) still parses' );
$parsed = OSM_Helper_Client::parse_success( [ 'ok' => true, 'scoutid' => '13', 'partial' => true, 'note_status' => 'failed', 'warnings' => [ 'w', 5, '' ] ] );
assert_true( $parsed['partial'] === true && $parsed['note_status'] === 'failed' && $parsed['warnings'] === [ 'w' ], 'partial Helper reply parses' );
$parsed = OSM_Helper_Client::parse_success( [ 'ok' => true, 'scoutid' => 1, 'note_status' => 'weird' ] );
assert_true( $parsed['note_status'] === 'none', 'unknown note_status → none' );

// --- Confirmation email (pure parts) ---
assert_true( OSM_Waiting_List_Email::is_enabled() === true, 'confirmation email on by default' );
assert_true( OSM_Waiting_List_Email::send_to_parent2() === true, 'parent 2 copy on by default' );
assert_true( OSM_Waiting_List_Email::reply_to() === '', 'no reply-to by default' );
assert_true(
    OSM_Waiting_List_Email::render( 'Hi {parent_first_name}, {child_first_name} joined {group_name}. {unknown}', [ 'parent_first_name' => 'Alex', 'child_first_name' => 'Jamie', 'group_name' => '4th Ashby' ] )
        === 'Hi Alex, Jamie joined 4th Ashby. {unknown}',
    'placeholders replaced, unknown left alone'
);
$base = valid_base();
$r = OSM_Waiting_List_Email::recipients( $base );
assert_true( count( $r ) === 1 && $r[0]['email'] === 'alex@example.org' && $r[0]['first_name'] === 'Alex', 'parent 1 is the only recipient without parent 2' );
$base['parent2_first_name'] = 'Sam';
$base['parent2_email'] = 'sam@example.org';
$r = OSM_Waiting_List_Email::recipients( $base );
assert_true( count( $r ) === 2 && $r[1]['email'] === 'sam@example.org' && $r[1]['first_name'] === 'Sam', 'parent 2 added when given' );
assert_true( count( OSM_Waiting_List_Email::recipients( $base, false ) ) === 1, 'parent 2 left out when that setting is off' );
$base['parent2_email'] = 'ALEX@example.org';
assert_true( count( OSM_Waiting_List_Email::recipients( $base ) ) === 1, 'same address for both parents is emailed once' );
$vars = OSM_Waiting_List_Email::vars( valid_base(), 'Alex', gmmktime( 12, 0, 0, 10, 5, 2026 ) );
assert_true( $vars['child_first_name'] === 'Jamie' && $vars['submitted_date'] === '5 October 2026', 'vars include child name and UK date' );
assert_true( OSM_Waiting_List_Email::single_line( "Jamie\r\nBcc: x@example.org" ) === 'Jamie Bcc: x@example.org', 'line breaks removed from header values' );
$subject = OSM_Waiting_List_Email::render( OSM_Waiting_List_Email::default_subject(), $vars );
assert_true( $subject === 'We have received your waiting list request for Jamie', 'default subject renders' );
$body = OSM_Waiting_List_Email::render( OSM_Waiting_List_Email::default_body(), $vars );
assert_true( str_contains( $body, 'Dear Alex,' ) && str_contains( $body, 'Jamie River' ) && ! str_contains( $body, '{' ), 'default body renders every placeholder' );
assert_true( OSM_Waiting_List_Email::headers() === [ 'Content-Type: text/plain; charset=UTF-8' ], 'plain-text header, no reply-to by default' );
assert_true( OSM_Waiting_List_Email::send_confirmation( valid_base() ) === false, 'no wp_mail available → nothing sent, no error' );

if ( $failures > 0 ) {
    echo "\n{$failures} failure(s)\n";
    exit( 1 );
}

echo "\nAll tests passed.\n";
exit( 0 );
