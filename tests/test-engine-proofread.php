<?php
// The proofreader returns only the sentences it changed, and the engine puts
// them into the article. Rewriting a whole article to fix a few sentences was
// 6 000 tokens and fifty seconds a recipe, and twice it lost the body.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

$answer = array( 'changes' => array(
	array( 'type' => 'accent', 'before' => 'Le four est prechauffe.', 'after' => 'Le four est préchauffé.' ),
	array( 'type' => 'grammar', 'before' => 'Cuire 45 minutes.', 'after' => 'Cuire 40 minutes.' ),
	array( 'type' => 'spelling', 'before' => 'Une phrase absente.', 'after' => 'Peu importe.' ),
	// Overlapping with the first: its correction is already in the text.
	array( 'type' => 'accent', 'before' => 'four est prechauffe', 'after' => 'four est préchauffé' ),
), 'clean' => false );
$sent = array();
MSRWA_Engine_Call::$transport = static function ( $url, $payload ) use ( &$sent, $answer ) {
	$sent[] = $payload;
	return array( 'status' => 200, 'raw' => json_encode( array(
		'status' => 'completed',
		'output' => array( array( 'type' => 'message', 'content' => array( array( 'text' => json_encode( $answer ) ) ) ) ),
		'usage' => array( 'input_tokens' => 10000, 'output_tokens' => 900 ),
	) ) );
};

$html = '<h2>Préparation</h2><p>Le four est prechauffe. Cuire 45 minutes.</p><!--nextpage--><h2>Service</h2><p>Servir tiède.</p>';
$result = MSRWA_Engine::run_step( 'proofread', array( 'title' => 'Tarte', 'artifacts' => array(
	'research' => array( 'facts' => array() ),
	'canonical' => array( 'title' => 'Tarte', 'ingredients' => array(), 'steps' => array() ),
	'article' => array( 'title' => 'Tarte', 'content_html' => $html ),
	'corrected' => array( 'content_html' => $html ),
) ), array( 'config' => array( 'settings' => array( 'keys' => array( 'openai' => 'k' ) ) ) ) );

$proofread = (array) ( $result->artifacts['proofread'] ?? array() );
$body = (string) ( $proofread['content_html'] ?? '' );
msrwa_test_contains( $body, 'Le four est préchauffé.', 'A quoted correction is substituted into the article.' );
msrwa_test_contains( $body, 'Cuire 45 minutes.', 'A change that would alter a figure is refused: the proofread never changes one.' );
msrwa_test_contains( $body, '<!--nextpage-->', 'The article keeps its structure, since only sentences are replaced.' );
msrwa_test_assert( 1 === count( (array) ( $proofread['changes'] ?? array() ) ), 'Only what was applied is recorded as applied.' );
msrwa_test_assert( 2 === count( (array) ( $proofread['changes_not_applied'] ?? array() ) ), 'What could not be applied is kept for the editor.' );
$step = end( $result->steps );
msrwa_test_assert( (int) $step['passed'] === (int) $step['total'], 'The proofread passes its own checks: ' . json_encode( $step['checks'] ) );

// An answer in the old shape, with the whole article, is still read as before.
MSRWA_Engine_Call::$transport = static function ( $url, $payload ) use ( $html ) {
	return array( 'status' => 200, 'raw' => json_encode( array(
		'status' => 'completed',
		'output' => array( array( 'type' => 'message', 'content' => array( array( 'text' => json_encode( array( 'content_html' => str_replace( 'prechauffe', 'préchauffé', $html ), 'changes' => array(), 'clean' => false ) ) ) ) ) ),
		'usage' => array( 'input_tokens' => 10000, 'output_tokens' => 6000 ),
	) ) );
};
$whole = MSRWA_Engine::run_step( 'proofread', array( 'title' => 'Tarte', 'artifacts' => array(
	'research' => array( 'facts' => array() ), 'canonical' => array( 'title' => 'Tarte' ),
	'article' => array( 'content_html' => $html ), 'corrected' => array( 'content_html' => $html ),
) ), array( 'config' => array( 'settings' => array( 'keys' => array( 'openai' => 'k' ) ) ) ) );
msrwa_test_contains( (string) ( $whole->artifacts['proofread']['content_html'] ?? '' ), 'préchauffé', 'A whole article returned the old way is still taken.' );

MSRWA_Engine_Call::$transport = null;
msrwa_test_done( 'the proofread returns its changes and the engine applies them' );
