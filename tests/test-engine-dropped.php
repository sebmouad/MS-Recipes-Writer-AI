<?php
// A connection that drops before any answer bills nothing and says nothing
// about the prompt. A live review lost that way ended a recipe with its
// article unchecked: it is asked once more, and the reason is recorded.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

$answer = array( 'pass' => true, 'findings' => array(), 'corrections' => array(), 'unsupported' => array(), 'changes' => array(), 'uncertainties' => array() );
$calls = 0;
MSRWA_Engine_Call::$transport = static function () use ( &$calls, $answer ) {
	$calls++;
	if ( 1 === $calls ) { return array( 'status' => 0, 'raw' => '', 'error' => 'Connection reset by peer' ); }
	return array( 'status' => 200, 'raw' => json_encode( array(
		'status' => 'completed',
		'output' => array( array( 'type' => 'message', 'content' => array( array( 'text' => json_encode( $answer ) ) ) ) ),
		'usage' => array( 'input_tokens' => 10000, 'output_tokens' => 900 ),
	) ) );
};
$artifacts = array(
	'research' => array( 'facts' => array() ),
	'canonical' => array( 'title' => 'Tarte', 'ingredients' => array(), 'steps' => array() ),
	'article' => array( 'title' => 'Tarte', 'content_html' => '<h2>Tarte</h2><p>Texte.</p>' ),
);
$keys = array( 'config' => array( 'settings' => array( 'keys' => array( 'openai' => 'k' ) ) ) );
$result = MSRWA_Engine::run_step( 'review', array( 'title' => 'Tarte', 'artifacts' => $artifacts ), $keys );
msrwa_test_assert( 2 === $calls, 'A dropped connection is asked once more; calls made: ' . $calls );
msrwa_test_assert( ! empty( $result->artifacts['review'] ), 'And the answer that came back is kept.' );
msrwa_test_contains( implode( "\n", array_column( $result->events, 'message' ) ), 'Connection reset by peer', 'curl’s own words say why.' );

// Only once: a network that stays down fails the step instead of looping.
$calls = 0;
MSRWA_Engine_Call::$transport = static function () use ( &$calls ) { $calls++; return array( 'status' => 0, 'raw' => '', 'error' => 'Could not resolve host' ); };
$down = MSRWA_Engine::run_step( 'review', array( 'title' => 'Tarte', 'artifacts' => $artifacts ), $keys );
msrwa_test_assert( 2 === $calls, 'A network that stays down is asked twice, not forever; calls made: ' . $calls );
msrwa_test_assert( ! $down->ok, 'And the step fails.' );

MSRWA_Engine_Call::$transport = null;
msrwa_test_done( 'a dropped connection is asked once more' );
