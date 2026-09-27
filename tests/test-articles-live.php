<?php
// The Articles screen splits running from finished and keeps the first list
// current through /runs/live. What that route answers is drawn by the same
// PHP as the page and read through the same scoped query, so a writer gets
// their own rows and never a figure in dollars. And nothing in the plugin
// reaches for jQuery: every script is plain JavaScript.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'i18n', 'ui', 'ledger', 'profile', 'rest' );
if ( true ) {
	if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $data ) { return $data; } }
	if ( ! class_exists( 'WP_REST_Request' ) ) {
		class WP_REST_Request {
			private $params;
			public function __construct( array $params = array() ) { $this->params = $params; }
			public function get_param( $key ) { return $this->params[ $key ] ?? null; }
		}
	}
}

// The two lists are two statuses of one query, and the ids a screen asks
// after stay inside the reader's scope.
msrwa_test_as_editor( 7 );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->posts = 'wp_posts';
MSRWA_Ledger::runs( array( 'status' => 'settled' ) );
msrwa_test_contains( $GLOBALS['wpdb']->log(), "r.status NOT IN ('queued','running')", 'Finished is everything the engine let go of.' );
MSRWA_Ledger::runs( array( 'status' => 'settled', 'ids' => array( 3, '5', 'x); DROP TABLE wp_posts; --', 3 ) ) );
$sql = $GLOBALS['wpdb']->log();
msrwa_test_contains( $sql, 'r.id IN (3,5)', 'Asked-after runs are named by number, once each.' );
msrwa_test_missing( $sql, 'DROP', 'Anything else in the list never reaches the query.' );
msrwa_test_contains( $sql, 'r.owner_id = 7', 'A writer asking after runs still gets only their own.' );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_Ledger::runs( array( 'ids' => array( 'none' ) ) );
msrwa_test_contains( $GLOBALS['wpdb']->log(), '1 = 0', 'A list with no valid number asks for nothing rather than for everything.' );

// The live route: the running rows, and the rows that settled since.
$rows = array(
	array( 'id' => 11, 'batch_id' => 4, 'owner_id' => 7, 'label' => 'Tarte Tatin', 'status' => 'running', 'step' => 'article', 'steps_done' => 3, 'steps_total' => 9, 'approved' => null, 'priority' => 0, 'draft_post_id' => 0, 'created_at' => '2026-09-27 10:00:00', 'profile' => 'full', 'language' => 'fr', 'cost_usd' => 0.042, 'seconds' => 61 ),
);
$settled = array(
	array( 'id' => 12, 'batch_id' => 4, 'owner_id' => 7, 'label' => 'Clafoutis', 'status' => 'done', 'step' => '', 'steps_done' => 9, 'steps_total' => 9, 'approved' => 1, 'priority' => 0, 'draft_post_id' => 0, 'created_at' => '2026-09-27 09:00:00', 'profile' => 'full', 'language' => 'fr', 'cost_usd' => 0.11, 'seconds' => 300 ),
);
$live = static function () use ( $rows, $settled ) {
	$GLOBALS['wpdb'] = ( new MSRWA_Fake_Wpdb() )
		->on( "r.status IN ('queued','running')", $rows )
		->on( "r.status NOT IN ('queued','running')", $settled );
	$GLOBALS['wpdb']->posts = 'wp_posts';
	return (array) MSRWA_Rest::live( new WP_REST_Request( array( 'shown' => '11,12,abc' ) ) );
};

$writer = $live();
msrwa_test_assert( 11 === ( $writer['moving'][0]['id'] ?? 0 ), 'A running recipe is in the running list.' );
msrwa_test_assert( 12 === ( $writer['settled'][0]['id'] ?? 0 ), 'One the page showed as running and that finished comes back to be moved down.' );
msrwa_test_contains( $writer['moving'][0]['html'], 'data-run="11"', 'Each row comes drawn, ready to swap in.' );
msrwa_test_contains( $writer['moving'][0]['html'], 'ms-pick-run', 'With its selection box, so the bulk bar still reaches it.' );
msrwa_test_assert( md5( $writer['moving'][0]['html'] ) === $writer['moving'][0]['sig'], 'The signature is the row, so an unchanged row is left alone.' );
$json = wp_json_encode( $writer );
msrwa_test_missing( $json, 'ms-run-cost', 'A writer’s live rows carry no cost column.' );
msrwa_test_missing( $json, '0.042', 'Nor the amount.' );
msrwa_test_assert( isset( $writer['totals']['moving'], $writer['totals']['settled'], $writer['post_counts'] ), 'The counts come with the rows.' );
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'r.id IN (12)', 'Only the rows that left the running list are asked after, by number.' );

msrwa_test_as_admin();
$admin = $live();
msrwa_test_contains( $admin['moving'][0]['html'], 'ms-run-cost', 'An administrator’s rows carry the cost, as on the page.' );

// Plain JavaScript only: no script of the plugin names jQuery or depends on it.
$root = dirname( __DIR__ ) . '/';
foreach ( glob( $root . 'assets/*.js' ) as $file ) {
	$code = (string) file_get_contents( $file );
	msrwa_test_assert( ! preg_match( '/\bjQuery\b|\$\(\s*[\'"#.]|\$\.(ajax|post|get|each|fn)\b|\$\(\s*(document|window|function)/', $code ), basename( $file ) . ' uses no jQuery.' );
}
foreach ( glob( $root . 'includes/*.php' ) as $file ) {
	$code = (string) file_get_contents( $file );
	msrwa_test_assert( ! preg_match( "/wp_(enqueue|register)_script\([^;]*'jquery[^']*'/s", $code ), basename( $file ) . ' enqueues no script that depends on jQuery.' );
	msrwa_test_missing( $code, 'jQuery', basename( $file ) . ' prints no jQuery code.' );
}

msrwa_test_done( 'the articles list splits running from finished and keeps it live, without jQuery' );
