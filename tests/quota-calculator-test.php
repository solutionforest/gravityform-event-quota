<?php
/**
 * Run with: php tests/quota-calculator-test.php
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

require dirname( __DIR__ ) . '/includes/class-gfeq-calculator.php';

$failures = 0;

function check( $condition, $message ) {
	global $failures;
	if ( $condition ) {
		echo "ok  $message\n";
		return;
	}
	$failures++;
	echo "FAIL $message\n";
}

function rules_date_slot() {
	return array(
		array(
			'choices'      => array( '1' => '11/28', '2' => 'am' ),
			'limit'        => 30,
			'full_message' => '(Full)',
		),
		array(
			'choices'      => array( '1' => '11/28', '2' => 'pm' ),
			'limit'        => 20,
			'full_message' => '(Full)',
		),
		array(
			'choices'      => array( '1' => '11/29', '2' => 'am' ),
			'limit'        => null,
			'full_message' => '(Full)',
		),
	);
}

function field_meta() {
	return array(
		'1' => array(
			'choices' => array(
				array( 'value' => '11/28', 'text' => '11/28' ),
				array( 'value' => '11/29', 'text' => '11/29' ),
			),
		),
		'2' => array(
			'choices' => array(
				array( 'value' => 'am', 'text' => '上午' ),
				array( 'value' => 'pm', 'text' => '下午' ),
			),
		),
	);
}

check( GFEQ_Calculator::entry_counts( 'active', '' ), 'active entry without payment counts' );
check( GFEQ_Calculator::entry_counts( 'active', 'Pending' ), 'pending payment holds a seat' );
check( GFEQ_Calculator::entry_counts( 'active', 'Paid' ), 'paid entry counts' );
check( ! GFEQ_Calculator::entry_counts( 'active', 'Failed' ), 'failed payment releases the seat' );
check( ! GFEQ_Calculator::entry_counts( 'active', 'Refunded' ), 'refund releases the seat' );
check( ! GFEQ_Calculator::entry_counts( 'active', 'Voided' ), 'void releases the seat' );
check( ! GFEQ_Calculator::entry_counts( 'active', 'Cancelled' ), 'cancelled payment releases the seat' );
check( ! GFEQ_Calculator::entry_counts( 'trash', 'Paid' ), 'trash does not count' );
check( ! GFEQ_Calculator::entry_counts( 'spam', '' ), 'spam does not count' );

check( GFEQ_Calculator::normalize_quantity( '1,200' ) === 1200.0, 'quantity ignores thousands separators' );
check( GFEQ_Calculator::normalize_quantity( '' ) === 0.0, 'blank quantity is zero' );
check( GFEQ_Calculator::normalize_quantity( '-3' ) === 0.0, 'negative quantity is zero' );

$same_key = GFEQ_Calculator::rule_key( array( '2' => 'am', '1' => '11/28' ) ) === GFEQ_Calculator::rule_key( array( 1 => '11/28', 2 => 'am' ) );
check( $same_key, 'combination key ignores field order' );

$entity_usage = GFEQ_Calculator::usage(
	array(
		array( 'values' => array( '1' => array( '11/28' ), '2' => array( 'am' ) ) ),
		array( 'values' => array( '1' => array( '11/28' ), '2' => array( 'am' ) ) ),
		array( 'values' => array( '1' => array( '11/28' ), '2' => array( 'pm' ) ) ),
	),
	rules_date_slot(),
	'entity'
);
check( $entity_usage[ GFEQ_Calculator::rule_key( array( '1' => '11/28', '2' => 'am' ) ) ] === 2.0, 'two morning entries use two seats' );
check( $entity_usage[ GFEQ_Calculator::rule_key( array( '1' => '11/28', '2' => 'pm' ) ) ] === 1.0, 'one afternoon entry uses one seat' );

$checkbox_usage = GFEQ_Calculator::usage(
	array(
		array(
			'values'   => array( '1' => array( '11/28' ), '2' => array( 'am', 'pm' ) ),
			'quantity' => 3,
		),
	),
	rules_date_slot(),
	'number'
);
check( $checkbox_usage[ GFEQ_Calculator::rule_key( array( '1' => '11/28', '2' => 'am' ) ) ] === 3.0, 'a checked morning takes the quantity' );
check( $checkbox_usage[ GFEQ_Calculator::rule_key( array( '1' => '11/28', '2' => 'pm' ) ) ] === 3.0, 'a checked afternoon takes the quantity too' );

$full_morning = array(
	array( 'values' => array( '1' => array( '11/28' ), '2' => array( 'am' ) ), 'quantity' => 30 ),
);
$usage = GFEQ_Calculator::usage( $full_morning, rules_date_slot(), 'number' );
$states = GFEQ_Calculator::option_states(
	array( '1' => array( '11/28' ), '2' => array() ),
	1,
	'entity',
	rules_date_slot(),
	$usage,
	field_meta()
);
check( $states['2']['am']['full'] === true, 'morning is full for the selected date' );
check( $states['2']['am']['message'] === '(Full)', 'full morning uses its message' );
check( $states['2']['pm']['full'] === false, 'afternoon stays open' );

$unknown_date = GFEQ_Calculator::option_states(
	array( '1' => array(), '2' => array() ),
	1,
	'entity',
	rules_date_slot(),
	$usage,
	field_meta()
);
check( $unknown_date['2']['am']['full'] === false, 'morning stays open before a date is selected' );

$almost = GFEQ_Calculator::usage(
	array( array( 'values' => array( '1' => array( '11/28' ), '2' => array( 'am' ) ), 'quantity' => 28 ) ),
	rules_date_slot(),
	'number'
);
$too_many = GFEQ_Calculator::option_states(
	array( '1' => array( '11/28' ), '2' => array() ),
	3,
	'number',
	rules_date_slot(),
	$almost,
	field_meta()
);
check( $too_many['2']['am']['full'] === true, 'quantity larger than the remainder marks morning full' );

$blank_quantity = GFEQ_Calculator::option_states(
	array( '1' => array( '11/28' ), '2' => array() ),
	'',
	'number',
	rules_date_slot(),
	$almost,
	field_meta()
);
check( $blank_quantity['2']['am']['full'] === false, 'a blank quantity does not block a combination that still has seats' );

$zero_limit = rules_date_slot();
$zero_limit[1]['limit'] = 0;
$zero_states = GFEQ_Calculator::option_states(
	array( '1' => array( '11/28' ), '2' => array() ),
	1,
	'entity',
	$zero_limit,
	array(),
	field_meta()
);
check( $zero_states['2']['pm']['full'] === true, 'a zero limit is already full' );

$open_states = GFEQ_Calculator::option_states(
	array( '1' => array( '11/29' ), '2' => array() ),
	5,
	'number',
	rules_date_slot(),
	array(),
	field_meta()
);
check( $open_states['2']['am']['full'] === false, 'a blank limit is unlimited' );

$violations = GFEQ_Calculator::violations(
	array( '1' => array( '11/28' ), '2' => array( 'am' ) ),
	1,
	'entity',
	rules_date_slot(),
	$usage,
	field_meta()
);
$violation_keys = array();
foreach ( $violations as $violation ) {
	$violation_keys[] = $violation['field_id'] . ':' . $violation['value'];
}
check( in_array( '2:am', $violation_keys, true ), 'submitting the full morning choice is rejected' );

$meal_rules = array(
	array(
		'choices'      => array( '1' => '11/28', '2' => 'am', '3' => 'chicken' ),
		'limit'        => 0,
		'full_message' => '(Full)',
	),
	array(
		'choices'      => array( '1' => '11/28', '2' => 'am', '3' => 'fish' ),
		'limit'        => 10,
		'full_message' => '(Full)',
	),
);
$meal_meta = field_meta();
$meal_meta['3'] = array(
	'choices' => array(
		array( 'value' => 'chicken', 'text' => '雞' ),
		array( 'value' => 'fish', 'text' => '魚' ),
	),
);
$meal_states = GFEQ_Calculator::option_states(
	array(
		'1' => array( '11/28' ),
		'2' => array(),
		'3' => array( 'chicken', 'fish' ),
	),
	1,
	'entity',
	$meal_rules,
	array(),
	$meal_meta
);
check( $meal_states['2']['am']['full'] === true, 'morning is full when any selected meal combination is full' );

if ( $failures > 0 ) {
	echo "\n$failures failed\n";
	exit( 1 );
}

echo "\nall passed\n";
exit( 0 );
