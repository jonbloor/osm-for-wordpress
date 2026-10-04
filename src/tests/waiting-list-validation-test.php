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

if ( $failures > 0 ) {
    echo "\n{$failures} failure(s)\n";
    exit( 1 );
}

echo "\nAll tests passed.\n";
exit( 0 );
