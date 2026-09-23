<?php
// A final approval that refuses one sentence of the article quotes it and says
// how it should read. That is applied in code and judged again, where it used
// to end the run refused: a whole article thrown back for one sentence.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

$dir = sys_get_temp_dir() . '/msrwa-repair-' . getmypid();
@mkdir( $dir );
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
file_put_contents( $dir . '/featured.png', $png );
file_put_contents( $dir . '/facebook.png', $png );

$verdict = static function ( $approved, array $findings ) {
	return array(
		'approved' => $approved,
		'article' => array( 'verdict' => $approved ? 'good' : 'bad', 'summary' => 'Une phrase.' ),
		'featured_image' => array( 'verdict' => 'good', 'realism' => 'good', 'summary' => 'Crédible.' ),
		'facebook_image' => array( 'verdict' => 'good', 'realism' => 'good', 'panels_counted' => 6, 'summary' => 'Dans l’ordre.' ),
		'consistency' => array( 'verdict' => 'good', 'summary' => 'Même plat.' ),
		'findings' => $findings,
		'uncertainties' => array( 'Rien d’autre.' ),
	);
};
$blocking = array( 'target' => 'article', 'severity' => 'blocking', 'quote' => 'Le fond d’agneau renforce le goût.', 'reason' => 'Non documenté.', 'fix' => 'Supprimer la comparaison.' );
$answers = array();
$calls = array();
$seen = array();
MSRWA_Engine_Call::$transport = static function ( $url, $payload ) use ( &$calls, &$answers, &$seen ) {
	$calls[] = $url;
	$seen[] = is_string( $payload ) ? $payload : json_encode( $payload );
	return array( 'status' => 200, 'raw' => json_encode( array(
		'status' => 'completed',
		'output' => array( array( 'type' => 'message', 'content' => array( array( 'text' => json_encode( array_shift( $answers ) ) ) ) ) ),
		'usage' => array( 'input_tokens' => 18000, 'output_tokens' => 2300 ),
	) ) );
};

$input = array(
	'title' => 'Souris d’agneau',
	'artifacts' => array(
		'research' => array( 'facts' => array() ),
		'canonical' => array( 'title' => 'Souris d’agneau', 'ingredients' => array(), 'steps' => array() ),
		'proofread' => array( 'title' => 'Souris d’agneau', 'content_html' => '<p>Mouillez au bouillon. Le fond d’agneau renforce le goût.</p><p>Servez.</p>' ),
		'featured' => array( 'kind' => 'featured', 'path' => $dir . '/featured.png', 'mime' => 'image/png', 'size' => '1024x1024' ),
		'facebook' => array( 'kind' => 'facebook', 'path' => $dir . '/facebook.png', 'mime' => 'image/png', 'size' => '1024x1536' ),
	),
);
$config = array( 'config' => array( 'settings' => array( 'keys' => array( 'openai' => 'k' ) ), 'limits' => array( 'budget_usd' => 5.0 ) ), 'workspace' => $dir );

// Refused on one quoted sentence, with the sentence as it should read: removed.
$answers = array( $verdict( false, array( $blocking + array( 'replacement' => '' ) ) ), $verdict( true, array() ) );
$result = MSRWA_Engine::run_step( 'final_approval', $input, $config );
msrwa_test_assert( 2 === count( $calls ), 'One refusal, one repair in code, one second verdict; calls made: ' . count( $calls ) );
msrwa_test_assert( ! array_filter( $calls, static function ( $url ) { return false !== strpos( $url, 'images' ); } ), 'Nothing is redrawn when only the article was refused.' );
msrwa_test_assert( '<p>Mouillez au bouillon.</p><p>Servez.</p>' === $result->artifacts['proofread']['content_html'], 'The quoted sentence is removed from the article the draft is made from (got ' . $result->artifacts['proofread']['content_html'] . ').' );
msrwa_test_assert( 'Souris d’agneau' === $result->artifacts['proofread']['title'], 'Nothing else about the article changes.' );
msrwa_test_assert( 1 === count( $result->artifacts['proofread']['approval_repairs'] ), 'The repair is recorded for the editor.' );
msrwa_test_assert( false === strpos( $seen[1], 'renforce le go' ), 'The second verdict is asked about the repaired article.' );
msrwa_test_assert( ! empty( $result->artifacts['approval']['approved'] ), 'The repaired article is approved.' );

// A finding without a usable quote needs a person: nothing is patched and the
// judge is not asked again.
$calls = array();
$answers = array( $verdict( false, array( $blocking + array( 'replacement' => '' ), array( 'quote' => '' ) + $blocking ) ) );
$result = MSRWA_Engine::run_step( 'final_approval', $input, $config );
msrwa_test_assert( 1 === count( $calls ), 'An article finding with no quote is not retried; calls made: ' . count( $calls ) );
msrwa_test_contains( $result->artifacts['proofread']['content_html'], 'renforce le goût', 'A partial repair is not applied.' );

// A replacement that is really markup is stored as text.
$repairs = MSRWA_Engine_Score::article_repairs( $verdict( false, array( $blocking + array( 'replacement' => '<script>x</script>Le fond d’agneau convient.' ) ) ), $input['artifacts']['proofread']['content_html'] );
msrwa_test_assert( 'xLe fond d’agneau convient.' === $repairs[0]['after'], 'Model output is data, never markup.' );

MSRWA_Engine_Call::$transport = null;
array_map( 'unlink', glob( $dir . '/*' ) );
@rmdir( $dir );
msrwa_test_done( 'a refused sentence is repaired in code and judged again' );
