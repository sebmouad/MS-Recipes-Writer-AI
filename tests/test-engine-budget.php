<?php
// The per-recipe ceiling cannot be exceeded. It was checked between waves only,
// so a step asked again could cross a budget the run had nearly reached. The
// network is replaced here: the judge refuses, or answers malformed.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

$dir = sys_get_temp_dir() . '/msrwa-budget-' . getmypid();
@mkdir( $dir );
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
file_put_contents( $dir . '/featured.png', $png );
file_put_contents( $dir . '/facebook.png', $png );

$refusal = array(
	'approved' => false,
	'featured_image' => array( 'verdict' => 'good', 'realism' => 'good', 'summary' => 'Crédible.' ),
	'facebook_image' => array( 'verdict' => 'bad', 'realism' => 'good', 'panels_counted' => 6, 'summary' => 'Ordre faux.' ),
	'consistency' => array( 'verdict' => 'good', 'summary' => 'Même plat.' ),
	'findings' => array( array( 'target' => 'facebook_image', 'severity' => 'blocking', 'quote' => '', 'reason' => 'Le panneau 4 précède la cuisson.', 'fix' => 'Montrer la précuisson.' ) ),
	'uncertainties' => array( 'Rien d’autre.' ),
);
$calls = array();
MSRWA_Engine_Call::$transport = static function ( $url, $payload ) use ( &$calls, &$refusal ) {
	$calls[] = $url;
	return array( 'status' => 200, 'raw' => json_encode( array(
		'status' => 'completed',
		'output' => array( array( 'type' => 'message', 'content' => array( array( 'text' => json_encode( $refusal ) ) ) ) ),
		'usage' => array( 'input_tokens' => 18000, 'output_tokens' => 2300 ),
	) ) );
};

$input = array(
	'title' => 'Tarte aux pommes',
	'artifacts' => array(
		'research' => array( 'facts' => array() ),
		'canonical' => array( 'title' => 'Tarte aux pommes', 'ingredients' => array(), 'steps' => array() ),
		'proofread' => array( 'content_html' => '<h2>Tarte</h2><p>Texte.</p>' ),
		'featured' => array( 'kind' => 'featured', 'path' => $dir . '/featured.png', 'mime' => 'image/png', 'size' => '1024x1024' ),
		'facebook' => array( 'kind' => 'facebook', 'path' => $dir . '/facebook.png', 'mime' => 'image/png', 'size' => '1024x1536' ),
	),
);
$keys = array( 'settings' => array( 'keys' => array( 'openai' => 'k' ) ) );

// A refusal is the editor's: nothing is redrawn and the judge is not asked again.
MSRWA_Engine::run_step( 'final_approval', $input, array( 'workspace' => $dir, 'config' => $keys + array( 'limits' => array( 'budget_usd' => 5.0 ) ) ) );
$images = array_filter( $calls, static function ( $url ) { return false !== strpos( $url, 'images' ); } );
msrwa_test_assert( 1 === count( $calls ), 'One approval and nothing after it, whatever the budget. Calls made: ' . count( $calls ) );
msrwa_test_assert( ! $images, 'No image is redrawn by the engine.' );

// A malformed verdict is asked again — but not past the budget.
$refusal = array( 'approved' => false );
$calls = array();
$tight = MSRWA_Engine::run_step( 'final_approval', $input, array( 'config' => $keys + array( 'limits' => array( 'budget_usd' => 0.008 ) ) ) );
msrwa_test_assert( 1 === count( $calls ), 'Another attempt would cross the budget, so none is made. Calls made: ' . count( $calls ) );
$said = implode( "\n", array_column( $tight->events, 'message' ) );
msrwa_test_contains( $said, 'Not retried', 'The run says why it stopped retrying.' );
msrwa_test_assert( $tight->totals()['cost_usd'] <= 0.008, 'The ceiling holds; spent ' . $tight->totals()['cost_usd'] );

$calls = array();
MSRWA_Engine::run_step( 'final_approval', $input, array( 'config' => $keys + array( 'limits' => array( 'budget_usd' => 5.0 ) ) ) );
msrwa_test_assert( 2 === count( $calls ), 'Under a roomy budget a malformed verdict is asked once more.' );

MSRWA_Engine_Call::$transport = null;
array_map( 'unlink', glob( $dir . '/*' ) );
@rmdir( $dir );
msrwa_test_done( 'the ceiling holds through the approval' );
