<?php
/**
 * Pure-PHP validation tests for OSM_Waiting_List (no WordPress, no network).
 *
 * Run: php src/tests/waiting-list-validation-test.php
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/includes/class-osm-waiting-list.php';

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

require_once dirname( __DIR__ ) . '/includes/class-osm-api.php';

// Captcha off does not require a token and does not block an OSM write.
assert_true( OSM_Waiting_List::captcha_requires_token( 'off' ) === false, 'captcha off does not require a token' );
assert_true( OSM_Waiting_List::captcha_blocks_osm( 'off', [] ) === false, 'captcha off does not block OSM when no token is posted' );
assert_true( OSM_Waiting_List::captcha_token_from_post( 'off', [] ) === '', 'captcha off ignores posted tokens' );
assert_true( OSM_Waiting_List::captcha_requires_token( 'recaptcha' ) === true, 'recaptcha requires a token' );
assert_true( OSM_Waiting_List::captcha_blocks_osm( 'recaptcha', [] ) === true, 'recaptcha without a token blocks OSM' );
assert_true( OSM_Waiting_List::captcha_blocks_osm( 'turnstile', [ 'cf-turnstile-response' => 'token' ] ) === false, 'turnstile with a token is not blocked locally' );
assert_true( OSM_Waiting_List::captcha_verify_succeeded( [ 'success' => true ] ) === true, 'siteverify success is accepted' );
assert_true( OSM_Waiting_List::captcha_verify_succeeded( [ 'success' => false ] ) === false, 'siteverify failure is rejected' );

// OAuth redirect path is the logged-in admin-post callback and nothing else.
assert_true(
    OSM_API::OAUTH_CALLBACK_ADMIN_PATH === 'admin-post.php?action=osm_oauth_callback',
    'redirect path is admin-post.php?action=osm_oauth_callback'
);
assert_true( OSM_API::CODE_CHALLENGE_METHOD === 'S256', 'PKCE method is S256' );
assert_true( strpos( OSM_API::SCOPES, 'section:member:write' ) !== false, 'scopes include member write' );
foreach ( [ 'finance', 'administration', 'badge', 'attendance', 'quartermaster', 'flexirecord' ] as $forbidden ) {
    assert_true( strpos( OSM_API::SCOPES, $forbidden ) === false, 'scopes omit ' . $forbidden );
}

$verifier = OSM_API::generate_pkce_verifier();
assert_true( strlen( $verifier ) >= 43 && strlen( $verifier ) <= 128, 'PKCE verifier length is valid' );
assert_true( (bool) preg_match( '/^[A-Za-z0-9\-_]+$/', $verifier ), 'PKCE verifier is unreserved' );
$challenge = OSM_API::pkce_challenge( $verifier );
$expected_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
assert_true( $challenge === $expected_challenge, 'PKCE challenge is S256 base64url' );

$auth_args = OSM_API::build_http_args(
    'POST',
    [
        'grant_type'    => 'authorization_code',
        'code'          => 'code',
        'redirect_uri'  => 'https://example.test/wp-admin/admin-post.php?action=osm_oauth_callback',
        'client_id'     => 'client-id',
        'client_secret' => 'not-a-real-secret',
        'code_verifier' => 'verifier',
    ],
    false
);
assert_true( ( $auth_args['headers']['Content-Type'] ?? '' ) === 'application/x-www-form-urlencoded', 'auth code token body sets form content type' );
parse_str( (string) $auth_args['body'], $auth_body );
assert_true( ( $auth_body['grant_type'] ?? '' ) === 'authorization_code', 'auth code grant_type' );
assert_true( ( $auth_body['redirect_uri'] ?? '' ) === 'https://example.test/wp-admin/admin-post.php?action=osm_oauth_callback', 'redirect_uri is unchanged' );
assert_true( isset( $auth_body['code'], $auth_body['client_id'], $auth_body['client_secret'], $auth_body['code_verifier'] ), 'auth code body has code, client, and verifier' );

$cc_args = OSM_API::build_http_args(
    'POST',
    [
        'grant_type'    => 'client_credentials',
        'client_id'     => 'client-id',
        'client_secret' => 'not-a-real-secret',
        'scope'         => OSM_API::SCOPES,
    ],
    false
);
assert_true( ( $cc_args['headers']['Content-Type'] ?? '' ) === 'application/x-www-form-urlencoded', 'client credentials body sets form content type' );
parse_str( (string) $cc_args['body'], $cc_body );
assert_true( ( $cc_body['grant_type'] ?? '' ) === 'client_credentials', 'client credentials grant_type' );
assert_true( ( $cc_body['scope'] ?? '' ) === OSM_API::SCOPES, 'client credentials scope' );

$refresh_args = OSM_API::build_http_args( 'POST', [ 'grant_type' => 'refresh_token', 'refresh_token' => 'refresh' ], false );
parse_str( (string) $refresh_args['body'], $refresh_body );
assert_true( ( $refresh_body['grant_type'] ?? '' ) === 'refresh_token', 'refresh grant_type' );

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
