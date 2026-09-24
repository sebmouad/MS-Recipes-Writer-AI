<?php
// A writer is never shown money, on any route they can reach. GET /queue is
// theirs, and it answered with the site's daily and monthly ceilings and
// what had been spent against them, in dollars.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'queue', 'budget', 'rest' );
if ( true ) {
	if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $data ) { return $data; } }
}
msrwa_test_settings( array( 'daily_budget_usd' => 5, 'monthly_budget_usd' => 80 ) );

msrwa_test_as_editor( 7 );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$writer = MSRWA_Rest::queue();
$writer = is_object( $writer ) && method_exists( $writer, 'get_data' ) ? $writer->get_data() : (array) $writer;
$json = wp_json_encode( $writer );
msrwa_test_missing( $json, 'ceiling', 'A writer is not told the ceilings.' );
msrwa_test_missing( $json, 'spent', 'Nor what was spent.' );
msrwa_test_missing( $json, '"left"', 'Nor what is left.' );
msrwa_test_contains( $json, 'exceeded', 'Only whether the site can still spend.' );

msrwa_test_as_admin();
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$admin = MSRWA_Rest::queue();
$admin = is_object( $admin ) && method_exists( $admin, 'get_data' ) ? $admin->get_data() : (array) $admin;
msrwa_test_contains( wp_json_encode( $admin ), 'ceiling', 'An administrator sees the figures.' );

// The routes behind the management screens open on the same capability.
msrwa_test_assert( MSRWA_Rest::can_manage() === MSRWA_Rights::may_manage(), 'The management routes agree with the management screens.' );

msrwa_test_done( 'no money reaches a writer through the queue route' );
