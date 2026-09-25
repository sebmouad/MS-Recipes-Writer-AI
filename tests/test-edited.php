<?php
// The run screen says a draft was edited only when its text changed: the
// draft stores the machine's HTML as blocks, and every recipe was reported as
// edited by thousands of characters that were block comments.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'ui' );
msrwa_test_load( 'screen-run' );

$machine = "<h2>Potée</h2>\n<p>Une potée l&rsquo;hiver.</p>";
$blocks = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Potée</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Une potée l’hiver.</p>\n<!-- /wp:paragraph -->";
msrwa_test_assert( MSRWA_Screen_Run::readable( $machine ) === MSRWA_Screen_Run::readable( $blocks ), 'The same text in blocks is not an edit.' );
msrwa_test_assert( MSRWA_Screen_Run::readable( $machine ) !== MSRWA_Screen_Run::readable( str_replace( 'hiver', 'été', $blocks ) ), 'A changed word is.' );

msrwa_test_done( 'a draft is edited only when its text changed' );
