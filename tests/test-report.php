<?php
// The report a manager opens for one job. It used to show an article-only job
// with "image not found" and a refused approval that never ran, labelled the
// writer's own photograph as one found on the web with a refused scheme, left
// the pairing out of the story and its cost out of the total, and printed the
// engine's English keys. Each of those is held here.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
require_once dirname( __DIR__ ) . '/tools/report.php';

$name = str_repeat( 'a', 32 ) . '.webp';
$run = array(
	'ok' => true,
	'eyebrow' => 'Tâche #45 · done',
	'planned' => array( 'research', 'canonical_recipe', 'article', 'review', 'fact_check', 'corrections', 'proofread' ),
	'steps' => array( array( 'step' => 'research', 'model' => 'm', 'seconds' => 1, 'usage' => array(), 'cost_usd' => 0.004, 'passed' => 13, 'total' => 13, 'checks' => array( 'valid JSON' => array( 'pass' => true, 'detail' => '14 keys' ) ) ),
		array( 'step' => 'review', 'model' => 'm', 'seconds' => 1, 'usage' => array(), 'cost_usd' => 0.002, 'passed' => 3, 'total' => 3, 'checks' => array() ),
	),
	'totals' => array( 'cost_usd' => 0.03, 'buckets' => array( 'article' => 0.025, 'other' => 0.004 ) ),
	'events' => array(), 'errors' => array(),
	'photos' => array( $name => 'data:image/jpeg;base64,AAAA' ),
	'history' => array(
		array( 'stage' => 'provided', 'created_at' => '2026-09-24 07:59:43', 'content' => array( 'recipes' => array( array( 'title' => 'Tarte fine', 'text' => 'Pâte, pommes.' ) ), 'photos' => array( array( 'file' => $name, 'name' => 'p1.webp' ) ) ) ),
		array( 'stage' => 'matching', 'created_at' => '2026-09-24 07:59:49', 'content' => array( 'readings' => array( array( 'file' => $name, 'dish' => 'tarte aux pommes', 'description' => 'Une tarte dorée.' ) ), 'recipes' => array( array( 'title' => 'Tarte fine' ), array( 'title' => 'Autre' ) ), 'pairs' => array( array( 'image' => 0, 'recipe' => 0, 'confidence' => 'haute', 'why' => 'Même plat.' ) ), 'cost_usd' => 0.002 ) ),
		array( 'stage' => 'brief', 'created_at' => '2026-09-24 07:59:49', 'content' => array( 'text_brief' => array( 'text' => 'Pâte, pommes.' ), 'visual_brief' => array( 'photos' => array( array( 'file' => $name ) ), 'source' => 'writer' ) ) ),
	),
	'artifacts' => array(
		'brief' => array( 'title' => 'Tarte fine' ),
		'research' => array(
			'dish_identity' => array( 'name' => 'Tarte fine' ),
			'visual_references' => array( array( 'image_url' => 'http://127.0.0.1/wp-json/msrwa/v1/batches/50/photos/' . $name, 'source_url' => 'editor', 'tier' => 1 ) ),
			'visual_observations' => array( array( 'image_url' => 'http://127.0.0.1/wp-json/msrwa/v1/batches/50/photos/' . $name, 'colours' => 'Doré.' ) ),
		),
		'review' => array( 'pass' => false, 'findings' => array( array( 'severity' => 'major', 'section' => 'Conservation', 'reason' => 'Allergènes absents.', 'fix' => 'Les ajouter.' ) ) ),
		'proofread' => array( 'changes' => array( array( 'type' => 'grammar', 'before' => 'a', 'after' => 'b' ) ) ),
	),
);
$html = report_render( $run );

msrwa_test_contains( $html, 'Tâche #45 · done', 'The page names the job, not the lab.' );
msrwa_test_missing( $html, 'Visuels générés', 'An article-only job has no images section.' );
msrwa_test_missing( $html, 'Approbation finale', 'Nor an approval it never planned, shown as refused.' );
msrwa_test_missing( $html, 'Image à la une</div>', 'Nor an image cost line.' );
msrwa_test_contains( $html, 'Historique : de ce qui a été fourni au brief', 'The history opens the page.' );
msrwa_test_contains( $html, 'Pâte, pommes.', 'What the writer provided is shown.' );
msrwa_test_contains( $html, 'Même plat.', 'Why each photograph was paired is shown.' );
msrwa_test_contains( $html, 'la recherche s’en sert sans chercher sur le web', 'The visual brief says where it came from.' );
msrwa_test_contains( $html, 'fournie par le rédacteur', 'The writer’s photograph is labelled as theirs.' );
msrwa_test_missing( $html, 'schéma refusé', 'And never as a refused address.' );
msrwa_test_contains( $html, 'data:image/jpeg;base64,AAAA', 'It is shown, embedded.' );
msrwa_test_contains( $html, '$0.0010', 'The recipe’s share of the lot’s pairing is a cost line: half of $0.002 for two recipes.' );
msrwa_test_contains( $html, '$0.0310', 'And part of the total.' );
msrwa_test_contains( $html, 'Identité du plat', 'The engine’s keys are read in French.' );
msrwa_test_contains( $html, 'JSON valide', 'So are the check names.' );
msrwa_test_contains( $html, 'laissé à l’éditeur', 'A review finding says where it went.' );
msrwa_test_contains( $html, 'corrigé la langue de 1 passage', 'And what the proofread then changed.' );
msrwa_test_contains( $html, 'Étape prévue, non atteinte', 'A planned step that never ran says so.' );

// A lab run names no plan and keeps every section, as before.
$lab = report_render( array( 'artifacts' => array(), 'steps' => array(), 'events' => array(), 'totals' => array(), 'ok' => false ) );
foreach ( array( 'Visuels générés', 'Approbation finale', 'Brief du rédacteur', 'Test laboratoire' ) as $section ) { msrwa_test_contains( $lab, $section, 'The lab report keeps ' . $section ); }

msrwa_test_assert( false !== strpos( report_link( 'culinary_practice' ), 'pratique culinaire' ) && false === strpos( report_link( 'brief' ), 'refusé' ), 'The engine’s source words are named, not refused.' );
msrwa_test_contains( report_link( 'javascript:alert(1)' ), 'schéma refusé', 'Another scheme still is.' );
msrwa_test_missing( report_link( 'javascript:alert(1)' ), '<a ', 'And is never a link.' );

// The page opens on what needs a person, and every kind of event has its colour.
$busy = $run;
$busy['artifacts']['corrected'] = array(
	'corrections_applied' => array( array( 'before' => 'Il renforce le goût.', 'after' => '', 'reason' => 'Non documenté.' ), array( 'quoted' => 'La pâte cuit.', 'before' => 'la pâte cuit.', 'after' => 'la pâte cuit 20 min.' ) ),
	'corrections_for_the_editor' => array( array( 'before' => 'Une phrase absente.', 'after' => 'Autre.' ) ),
);
$busy['events'] = array(
	array( 'at' => 0, 'kind' => 'wave', 'step' => 'run', 'message' => 'Next: research' ),
	array( 'at' => 1, 'kind' => 'call', 'step' => 'research', 'message' => 'answered' ),
	array( 'at' => 2, 'kind' => 'warning', 'step' => 'research', 'message' => 'Attention.' ),
	array( 'at' => 3, 'kind' => 'error', 'step' => 'article', 'message' => 'Cassé.' ),
);
$page = report_render( $busy );
msrwa_test_contains( $page, 'À regarder avant de publier', 'What needs a person is at the top.' );
msrwa_test_contains( $page, '1 correction(s) factuelle(s) à appliquer à la main', 'Including the corrections left to the editor.' );
msrwa_test_contains( $page, 'href="#revue"', 'And each item leads to its section.' );
msrwa_test_contains( $page, 'class="toc"', 'The page has a way round it.' );
msrwa_test_contains( $page, 'Passage supprimé', 'A correction that removed its passage says so, not an empty replacement.' );
msrwa_test_contains( $page, 'La relecture citait : « La pâte cuit. »', 'A passage found from a loose quote shows what was quoted.' );
msrwa_test_contains( $page, 'ev ev-warn', 'A warning has its colour.' );
msrwa_test_contains( $page, 'ev ev-bad', 'So does a failure.' );
msrwa_test_contains( $page, 'ev ev-neutral ev-detail', 'The plumbing is one click away, not in the story.' );
msrwa_test_contains( $page, 'Avertissement · 1', 'The legend counts each kind.' );

msrwa_test_done( 'the report tells a job’s whole story, and only what it planned' );
