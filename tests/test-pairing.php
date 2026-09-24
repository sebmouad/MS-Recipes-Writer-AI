<?php
// The pairing saves itself on every change. A photograph the writer did not
// touch keeps what the model said about it; one they moved becomes theirs.
// Marking every row confirmed on each save erased the model's doubts about
// the photographs nobody had looked at yet.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'profile', 'batch', 'db', 'sources', 'history', 'match' );
msrwa_test_as_admin();

$matching = array(
	'recipes' => array( array( 'title' => 'Tarte aux pommes' ), array( 'title' => 'Poulet yassa' ) ),
	'images' => array( array( 'file' => 'tarte.jpg' ), array( 'file' => 'agneau.jpg' ), array( 'file' => 'flou.jpg' ) ),
	'pairs' => array(
		array( 'image' => 0, 'recipe' => 0, 'confidence' => 'haute', 'why' => 'Une tarte aux pommes.' ),
		array( 'image' => 1, 'recipe' => null, 'confidence' => 'haute', 'why' => 'Un jarret d’agneau, pas un yassa.' ),
		array( 'image' => 2, 'recipe' => 1, 'confidence' => 'basse', 'why' => 'Rien de net.' ),
	),
);
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'WHERE id = 7', array( array( 'id' => 7, 'owner_id' => 1, 'status' => 'ready', 'matching_json' => wp_json_encode( $matching ) ) ) );

// The writer moves the blurred photograph off the yassa and changes nothing else.
MSRWA_Batch::repair( 7, array( array( 'image' => 0, 'recipe' => 0 ), array( 'image' => 1, 'recipe' => null ), array( 'image' => 2, 'recipe' => 'aside' ) ) );
$update = $GLOBALS['wpdb']->matching( 'UPDATE ' );
msrwa_test_assert( 1 === count( $update ), 'One save.' );
$kept = array_values( array_filter( $GLOBALS['wpdb']->inserted, static function ( $row ) { return false !== strpos( $row[0], 'history' ); } ) );
msrwa_test_assert( 1 === count( $kept ) && 'pairing' === $kept[0][1]['stage'] && 7 === $kept[0][1]['batch_id'], 'The writer’s correction is written into the lot’s history.' );
$saved = json_decode( json_decode( substr( $update[0], strpos( $update[0], '{' ) ), true )['matching_json'], true );
$by = array();
foreach ( $saved['pairs'] as $pair ) { $by[ $pair['image'] ] = $pair; }

msrwa_test_assert( 'Une tarte aux pommes.' === $by[0]['why'] && empty( $by[0]['by_writer'] ), 'A photograph left where the model put it keeps the model’s reason.' );
msrwa_test_assert( null === $by[1]['recipe'] && ! empty( $by[1]['pending'] ) && 'Un jarret d’agneau, pas un yassa.' === $by[1]['why'], 'One no recipe took and the writer has not decided keeps its reason, and waits.' );
msrwa_test_assert( null === $by[2]['recipe'] && ! empty( $by[2]['by_writer'] ) && ! empty( $by[2]['set_aside'] ), 'The photograph the writer set aside is theirs, and decided.' );
msrwa_test_missing( wp_json_encode( $saved['pairs'] ), 'Confirmé par le rédacteur', 'No untranslated marker is written into the pairing.' );

// An index out of range is dropped, and one photograph is claimed once.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'WHERE id = 7', array( array( 'id' => 7, 'owner_id' => 1, 'status' => 'ready', 'matching_json' => wp_json_encode( $matching ) ) ) );
MSRWA_Batch::repair( 7, array( array( 'image' => 9, 'recipe' => 0 ), array( 'image' => 0, 'recipe' => 5 ), array( 'image' => 0, 'recipe' => 1 ) ) );
$update = $GLOBALS['wpdb']->matching( 'UPDATE ' );
$saved = json_decode( json_decode( substr( $update[0], strpos( $update[0], '{' ) ), true )['matching_json'], true );
msrwa_test_assert( 1 === count( $saved['pairs'] ) && null === $saved['pairs'][0]['recipe'], 'Out of range goes nowhere, and the first claim on a photograph stands.' );

// A photograph the writer makes a recipe of, under the name they give: the
// lot gains a recipe, named, written from that photograph.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'WHERE id = 7', array( array( 'id' => 7, 'owner_id' => 1, 'status' => 'ready', 'matching_json' => wp_json_encode( $matching ) ) ) );
MSRWA_Batch::repair( 7, array( array( 'image' => 1, 'recipe' => 'new', 'title' => 'Souris d’agneau <b>confite</b>' ) ) );
$update = $GLOBALS['wpdb']->matching( 'UPDATE ' );
$row = json_decode( substr( $update[0], strpos( $update[0], '{' ) ), true );
$saved = json_decode( $row['matching_json'], true );
msrwa_test_assert( 3 === count( $saved['recipes'] ) && 'Souris d’agneau confite' === $saved['recipes'][2]['title'] && ! empty( $saved['recipes'][2]['from_photographs'] ), 'The writer’s new recipe is added, its name plain text.' );
msrwa_test_assert( 2 === $saved['pairs'][0]['recipe'] && 3 === (int) $row['recipes'], 'The photograph goes with it, and the lot counts three recipes.' );

// Undecided photographs hold the lot: it cannot be sent, nor given an hour.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$waiting = $matching;
$waiting['pairs'][1]['pending'] = true;
$GLOBALS['wpdb']->on( 'WHERE id = 7', array( array( 'id' => 7, 'owner_id' => 1, 'status' => 'ready', 'budget_usd' => 1, 'profile' => 'full', 'language' => 'fr', 'config_json' => '[]', 'matching_json' => wp_json_encode( $waiting ) ) ) );
msrwa_test_assert( 1 === MSRWA_Batch::undecided( 7 ), 'One photograph is counted as undecided.' );
$sent = MSRWA_Batch::dispatch( 7 );
msrwa_test_assert( is_wp_error( $sent ) && 'msrwa_undecided' === $sent->get_error_code(), 'A lot with an undecided photograph is not sent.' );

msrwa_test_done( 'the pairing keeps the model’s word on what the writer did not change' );
