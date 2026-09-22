<?php
// The article arrives from the engine as one string of HTML, and an editor
// opens it in the block editor. The conversion between the two is held here,
// because when it went wrong the whole article disappeared from the draft and
// nothing offline noticed: the post was written, it was simply empty.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'blocks' );

// --- Nothing to convert --------------------------------------------------

msrwa_test_assert( '' === MSRWA_Blocks::from_html( '' ), 'An empty article stays empty.' );
msrwa_test_assert( '   ' === MSRWA_Blocks::from_html( '   ' ), 'Whitespace is not turned into a block.' );

// --- The tags the article contract promises ------------------------------

$article = '<h2>Pourquoi cette tarte tient</h2>' . "\n"
	. '<p>La <strong>pâte brisée</strong> doit rester froide : c’est le beurre qui feuillette.</p>' . "\n"
	. '<h3>Les pommes</h3>' . "\n"
	. '<ul><li>500 g de pommes</li><li>2 œufs</li></ul>' . "\n"
	. '<!--nextpage-->' . "\n"
	. '<ol><li>Foncer le moule.</li></ol>' . "\n"
	. '<p>Enfourner 45 minutes à 180 °C.</p>';
$blocks = MSRWA_Blocks::from_html( $article );

// Every top-level node of the fragment survives. A document can hold one root
// element, and the loader once kept the first and dropped everything after it:
// the draft came out holding the page break and nothing else.
foreach ( array(
	'<!-- wp:heading -->', '<!-- wp:heading {"level":3} -->', '<!-- wp:paragraph -->',
	'<!-- wp:list -->', '<!-- wp:list {"ordered":true} -->', '<!-- wp:list-item -->', '<!-- wp:nextpage -->',
) as $marker ) {
	msrwa_test_contains( $blocks, $marker, 'The article carries a ' . $marker . ' block.' );
}
// Opened and closed in pairs: an unclosed delimiter makes the editor treat
// everything after it as part of that block.
foreach ( array( 'heading', 'paragraph', 'list', 'list-item', 'nextpage' ) as $name ) {
	$opened = preg_match_all( '~<!-- wp:' . preg_quote( $name, '~' ) . '( \{[^\n]*\})? -->~', $blocks );
	$closed = substr_count( $blocks, '<!-- /wp:' . $name . ' -->' );
	msrwa_test_assert( $opened > 0, 'The ' . $name . ' block is emitted.' );
	msrwa_test_assert( $opened === $closed, 'Every ' . $name . ' block is closed.' );
}

// The classes core writes itself. Without them the editor calls the block
// invalid the moment the post is opened, and offers to convert it to HTML.
msrwa_test_contains( $blocks, '<h2 class="wp-block-heading">', 'A heading carries the class core writes.' );
msrwa_test_contains( $blocks, '<h3 class="wp-block-heading">', 'And so does a sub-heading.' );
msrwa_test_contains( $blocks, '<ul class="wp-block-list">', 'A list carries the class core writes.' );
msrwa_test_contains( $blocks, '<ol class="wp-block-list">', 'And so does an ordered list.' );

// libxml guesses Latin-1 unless told otherwise, which turns every French
// accent into mojibake — silently, in the editor's face.
msrwa_test_contains( $blocks, 'pâte brisée', 'Accents survive the conversion.' );
msrwa_test_contains( $blocks, '2 œufs', 'And so do ligatures.' );
msrwa_test_contains( $blocks, '180 °C', 'And so do degrees.' );
msrwa_test_contains( $blocks, 'c’est', 'And so do typographic apostrophes.' );

// Inline markup belongs to the paragraph, not to a block of its own.
msrwa_test_contains( $blocks, '<strong>pâte brisée</strong>', 'Inline emphasis stays inline.' );
msrwa_test_missing( $blocks, '<!-- wp:html -->', 'Nothing the contract promises falls back to an HTML block.' );

// --- Converting twice ----------------------------------------------------

msrwa_test_assert( $blocks === MSRWA_Blocks::from_html( $blocks ), 'Markup that already carries blocks is returned untouched.' );

// --- A tag the contract never promised -----------------------------------

$odd = MSRWA_Blocks::from_html( '<p>Avant.</p><table><tr><td>3 h</td></tr></table><p>Après.</p>' );
msrwa_test_contains( $odd, '<!-- wp:html -->', 'An unexpected tag is wrapped rather than dropped.' );
msrwa_test_contains( $odd, '3 h', 'And its content is kept.' );
msrwa_test_contains( $odd, 'Avant.', 'The paragraph before it survives.' );
msrwa_test_contains( $odd, 'Après.', 'And so does the one after it.' );

// --- Text the model left outside any tag ---------------------------------

$loose = MSRWA_Blocks::from_html( 'Une phrase nue.<p>Une autre.</p>' );
msrwa_test_contains( $loose, 'Une phrase nue.', 'Loose text is not lost.' );
msrwa_test_assert( 2 === substr_count( $loose, '<!-- wp:paragraph -->' ), 'It becomes a paragraph of its own.' );

// --- An empty list, and an empty paragraph -------------------------------

msrwa_test_missing( MSRWA_Blocks::from_html( '<p>Gardé.</p><ul></ul>' ), '<!-- wp:list', 'A list with no items produces no list block.' );
msrwa_test_assert( 1 === substr_count( MSRWA_Blocks::from_html( '<p>Gardé.</p><p>  </p>' ), '<!-- wp:paragraph -->' ), 'An empty paragraph produces no block.' );

// --- Nothing convertible at all ------------------------------------------

msrwa_test_contains( MSRWA_Blocks::from_html( '<!--not a page break-->' ), 'not a page break', 'An article that converts to nothing is returned as it came, rather than emptied.' );

msrwa_test_done( 'blocks' );
