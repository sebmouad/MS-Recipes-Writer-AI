<?php
// What the writer pasted, turned into recipes. Everything downstream counts on
// this: a block that does not become a recipe never gets an article.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-intake.php';

// The common case must need no ceremony: one recipe, no separator, one recipe.
$one = MSRWA_Intake::recipes( "Tarte aux pommes normande\nPâte brisée, pommes, crème." );
msrwa_test_assert( 1 === count( $one ), 'A single recipe with no separator is one recipe; got ' . count( $one ) );
msrwa_test_assert( 'Tarte aux pommes normande' === $one[0]['title'], 'The first line is the title; got ' . $one[0]['title'] );

$many = MSRWA_Intake::recipes( "# Tarte aux pommes\ntexte un\n\n---\n\n2. Poulet yassa\ntexte deux\n\n-----\n\nDaube\ntexte trois" );
msrwa_test_assert( 3 === count( $many ), 'A rule of three dashes or more separates recipes; got ' . count( $many ) );
msrwa_test_assert( 'Tarte aux pommes' === $many[0]['title'], 'A Markdown heading marker is not part of the title; got ' . $many[0]['title'] );
msrwa_test_assert( 'Poulet yassa' === $many[1]['title'], 'A numbered heading is not part of the title; got ' . $many[1]['title'] );
msrwa_test_assert( false !== strpos( $many[2]['text'], 'texte trois' ), 'Each recipe keeps its own body.' );

// The separator itself is never a recipe, and neither is empty space.
$blanks = MSRWA_Intake::recipes( "\n\n---\n\nUne seule\n\n---\n\n   \n" );
msrwa_test_assert( 1 === count( $blanks ), 'Empty blocks are not recipes; got ' . count( $blanks ) );
msrwa_test_assert( array() === MSRWA_Intake::recipes( '   ' ), 'Nothing submitted is no recipes, not one empty one.' );

// A title is bounded: it becomes a post title and a column in a table.
$long = MSRWA_Intake::recipes( str_repeat( 'a', 400 ) );
msrwa_test_assert( 180 >= mb_strlen( $long[0]['title'] ), 'A title is cut to something a column can hold.' );

// Decoration writers put round a heading is not part of the dish's name.
$decorated = MSRWA_Intake::recipes( "**Daube provençale**\nviande" );
msrwa_test_assert( 'Daube provençale' === $decorated[0]['title'], 'Emphasis marks are stripped; got ' . $decorated[0]['title'] );

msrwa_test_done( 'intake OK' );
