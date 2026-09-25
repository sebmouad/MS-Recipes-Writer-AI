<?php
// The review returns only the sentences whose language it changed, and the
// engine puts them into the article, with no second model call. Rewriting a
// whole article to fix a few sentences was 6 000 tokens and fifty seconds a
// recipe, and twice it lost the body.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

$changes = array(
	array( 'type' => 'accent', 'before' => 'Le four est prechauffe.', 'after' => 'Le four est préchauffé.' ),
	array( 'type' => 'grammar', 'before' => 'Cuire 45 minutes.', 'after' => 'Cuire 40 minutes.' ),
	array( 'type' => 'spelling', 'before' => 'Une phrase absente.', 'after' => 'Peu importe.' ),
	// Overlapping with the first: its correction is already in the text.
	array( 'type' => 'accent', 'before' => 'four est prechauffe', 'after' => 'four est préchauffé' ),
);
$called = 0;
MSRWA_Engine_Call::$transport = static function () use ( &$called ) { $called++; return array( 'status' => 500, 'raw' => '' ); };

$html = '<h2>Préparation</h2><p>Le four est prechauffe. Cuire 45 minutes.</p><!--nextpage--><h2>Service</h2><p>Servir tiède.</p>';
$result = MSRWA_Engine::run_step( 'proofread', array( 'title' => 'Tarte', 'artifacts' => array(
	'research' => array( 'facts' => array() ),
	'canonical' => array( 'title' => 'Tarte', 'ingredients' => array(), 'steps' => array() ),
	'article' => array( 'title' => 'Tarte', 'content_html' => '<p>Le brouillon avant les faits.</p>' ),
	'review' => array( 'pass' => true, 'findings' => array(), 'corrections' => array(), 'changes' => $changes ),
	'corrected' => array( 'content_html' => $html ),
) ), array( 'config' => array( 'settings' => array( 'keys' => array( 'openai' => 'k' ) ) ) ) );

$proofread = (array) ( $result->artifacts['proofread'] ?? array() );
$body = (string) ( $proofread['content_html'] ?? '' );
msrwa_test_assert( 0 === $called, 'The language changes came with the review: applying them calls no model.' );
msrwa_test_contains( $body, 'Le four est préchauffé.', 'A quoted change is substituted into the text the facts were fixed in.' );
msrwa_test_contains( $body, 'Cuire 45 minutes.', 'A change that would alter a figure is refused: the proofread never changes one.' );
msrwa_test_contains( $body, '<!--nextpage-->', 'The article keeps its structure, since only sentences are replaced.' );
msrwa_test_assert( 1 === count( (array) ( $proofread['changes'] ?? array() ) ), 'Only what was applied is recorded as applied.' );
msrwa_test_assert( 2 === count( (array) ( $proofread['changes_not_applied'] ?? array() ) ), 'What could not be applied is kept for the editor.' );
$step = end( $result->steps );
msrwa_test_assert( 0.0 === (float) $step['cost_usd'], 'It costs nothing.' );
msrwa_test_assert( (int) $step['passed'] === (int) $step['total'], 'The proofread passes its own checks: ' . json_encode( $step['checks'] ) );

// A review with nothing to change leaves the corrected text as it was.
$clean = MSRWA_Engine::run_step( 'proofread', array( 'title' => 'Tarte', 'artifacts' => array(
	'article' => array( 'content_html' => $html ), 'review' => array( 'pass' => true, 'changes' => array() ),
	'corrected' => array( 'content_html' => $html ),
) ) );
msrwa_test_assert( $html === (string) ( $clean->artifacts['proofread']['content_html'] ?? '' ), 'A clean review leaves the text untouched.' );
msrwa_test_assert( true === ( $clean->artifacts['proofread']['clean'] ?? null ), 'And says it was clean.' );

MSRWA_Engine_Call::$transport = null;
msrwa_test_done( 'the review returns its language changes and the engine applies them' );

// The article repeated a sentence; removing the copy drops its figures but
// changes none, and is applied.
$twice = MSRWA_Engine::run_step( 'proofread', array( 'title' => 'Gratin', 'artifacts' => array(
	'research' => array( 'facts' => array() ),
	'canonical' => array( 'title' => 'Gratin', 'ingredients' => array(), 'steps' => array() ),
	'article' => array( 'title' => 'Gratin', 'content_html' => '<p>x</p>' ),
	'review' => array( 'pass' => true, 'findings' => array(), 'corrections' => array(), 'changes' => array(
		array( 'type' => 'consistency', 'before' => 'Réchauffez à 74 °C à cœur. Réchauffez à 74 °C à cœur.', 'after' => 'Réchauffez à 74 °C à cœur.' ),
		array( 'type' => 'consistency', 'before' => 'Cuire 45 minutes. Cuire 40 minutes.', 'after' => 'Cuire 45 minutes.' ),
	) ),
	'corrected' => array( 'content_html' => '<p>Réchauffez à 74 °C à cœur. Réchauffez à 74 °C à cœur.</p><p>Cuire 45 minutes. Cuire 40 minutes.</p>' ),
) ), array( 'config' => array( 'settings' => array( 'keys' => array( 'openai' => 'k' ) ) ) ) );
$once = (string) ( $twice->artifacts['proofread']['content_html'] ?? '' );
msrwa_test_contains( $once, '<p>Réchauffez à 74 °C à cœur.</p>', 'A repeated sentence is removed.' );
msrwa_test_contains( $once, 'Cuire 40 minutes.', 'Removing a sentence that carries a figure of its own is still refused.' );
