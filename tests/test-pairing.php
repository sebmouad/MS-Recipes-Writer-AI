<?php
// The pairing saves itself on every change. A photograph the writer did not
// touch keeps what the model said about it; one they moved becomes theirs.
// Marking every row confirmed on each save erased the model's doubts about
// the photographs nobody had looked at yet.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'profile', 'batch' );
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
MSRWA_Batch::repair( 7, array( array( 'image' => 0, 'recipe' => 0 ), array( 'image' => 1, 'recipe' => null ), array( 'image' => 2, 'recipe' => '' ) ) );
$update = $GLOBALS['wpdb']->matching( 'UPDATE ' );
msrwa_test_assert( 1 === count( $update ), 'One save.' );
$saved = json_decode( json_decode( substr( $update[0], strpos( $update[0], '{' ) ), true )['matching_json'], true );
$by = array();
foreach ( $saved['pairs'] as $pair ) { $by[ $pair['image'] ] = $pair; }

msrwa_test_assert( 'Une tarte aux pommes.' === $by[0]['why'] && empty( $by[0]['by_writer'] ), 'A photograph left where the model put it keeps the model’s reason.' );
msrwa_test_assert( null === $by[1]['recipe'] && 'Un jarret d’agneau, pas un yassa.' === $by[1]['why'], 'One the model set aside and the writer left aside keeps its reason too.' );
msrwa_test_assert( null === $by[2]['recipe'] && ! empty( $by[2]['by_writer'] ), 'The photograph the writer moved is theirs.' );
msrwa_test_missing( wp_json_encode( $saved['pairs'] ), 'Confirmé par le rédacteur', 'No untranslated marker is written into the pairing.' );

// An index out of range is dropped, and one photograph is claimed once.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'WHERE id = 7', array( array( 'id' => 7, 'owner_id' => 1, 'status' => 'ready', 'matching_json' => wp_json_encode( $matching ) ) ) );
MSRWA_Batch::repair( 7, array( array( 'image' => 9, 'recipe' => 0 ), array( 'image' => 0, 'recipe' => 5 ), array( 'image' => 0, 'recipe' => 1 ) ) );
$update = $GLOBALS['wpdb']->matching( 'UPDATE ' );
$saved = json_decode( json_decode( substr( $update[0], strpos( $update[0], '{' ) ), true )['matching_json'], true );
msrwa_test_assert( 1 === count( $saved['pairs'] ) && null === $saved['pairs'][0]['recipe'], 'Out of range goes nowhere, and the first claim on a photograph stands.' );

msrwa_test_done( 'the pairing keeps the model’s word on what the writer did not change' );
