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
assert_true( OSM_Helper_Client::SUBMIT_PATH === '/api/waiting-list/submit', 'Helper submit path' );
assert_true(
    OSM_Helper_Client::submit_url( 'https://osmhelper.co.uk/api/waiting-list/submit' ) === 'https://osmhelper.co.uk/api/waiting-list/submit',
    'Full endpoint pasted as base is not doubled'
);
assert_true(
    OSM_Helper_Client::submit_url( 'https://osmhelper.co.uk' ) === 'https://osmhelper.co.uk/api/waiting-list/submit',
    'submit URL joins base and path'
);
assert_true(
    OSM_Helper_Client::submit_url( 'https://osmhelper.co.uk/' ) === 'https://osmhelper.co.uk/api/waiting-list/submit',
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

if ( $failures > 0 ) {
    echo "\n{$failures} failure(s)\n";
    exit( 1 );
}

echo "\nAll tests passed.\n";
exit( 0 );
