<?php
// Seen on real drafts: the title repeated as the first heading, text opening
// on « Introduction : » or « Deuxième partie », and a first page that ended
// without saying the recipe goes on. The draft is cleaned of all three.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'profile', 'intake', 'article' );

$html = "<h2>Tarte Tatin</h2>\n<p>Introduction : la tarte Tatin se cuit à l’envers.</p>\n<h2>Deuxième partie</h2>\n<h2>Conclusion</h2>\n<p><strong>Second article :</strong> elle se sert tiède.</p>\n<!--nextpage-->\n<h2>Préparation de la recette étape par étape</h2>\n<p>Beurrez le moule.</p>";
$tidy = MSRWA_Article::tidy( $html, 'Tarte Tatin', 'fr' );
msrwa_test_missing( $tidy, '<h2>Tarte Tatin</h2>', 'A first heading repeating the title goes.' );
msrwa_test_contains( $tidy, '<p>La tarte Tatin se cuit', 'A label opening a paragraph goes, and the sentence keeps its capital.' );
msrwa_test_missing( $tidy, 'Deuxième partie', 'A heading that only names a part goes.' );
msrwa_test_contains( $tidy, '<h2>Conclusion</h2>', 'A « Conclusion » heading may be a planned section and stays.' );
msrwa_test_contains( $tidy, '<p>Elle se sert tiède.</p>', 'A bold label goes too.' );
msrwa_test_contains( $tidy, '<p>La suite de la recette, « Préparation de la recette étape par étape », vous attend à la page 2.</p>' . "\n<!--nextpage-->", 'Page one ends by sending the reader to page two, naming it.' );
msrwa_test_assert( 1 === substr_count( MSRWA_Article::tidy( $tidy, 'Tarte Tatin', 'fr' ), 'page 2' ), 'Tidying twice adds the line once.' );
msrwa_test_contains( MSRWA_Article::tidy( "<p>Fin.</p>\n<!--nextpage-->\n<h2>Step-by-step preparation</h2>", 'Tatin', 'en' ), 'continues on page 2', 'In the article’s language.' );
msrwa_test_assert( '<p>Un seul texte.</p>' === MSRWA_Article::tidy( '<p>Un seul texte.</p>', 'Tatin', 'fr' ), 'An article on one page is left alone.' );
// The owner's rule, 2026-09-28: the first heading never repeats the title —
// not word for word, not as its opening. What it goes on to ask stays.
$first = static function ( $html ) { return preg_match( '#<h2[^>]*>(.*?)</h2>#su', $html, $m ) ? $m[1] : ''; };
msrwa_test_assert( 'Aux pommes caramélisées' === $first( MSRWA_Article::tidy( '<h2>Tarte Tatin aux pommes caramélisées</h2><p>x</p>', 'Tarte Tatin', 'fr' ) ), 'A heading opening on the title keeps only what follows it.' );
msrwa_test_assert( 'Quelle texture et quand la servir ?' === $first( MSRWA_Article::tidy( '<h2>La tarte aux pommes normande : quelle texture et quand la servir ?</h2><p>x</p>', 'Tarte aux pommes normande, fondante et dorée', 'fr', 'Tarte aux pommes normande' ) ), 'The dish’s name and its article go, the question stays: ' . $first( MSRWA_Article::tidy( '<h2>La tarte aux pommes normande : quelle texture et quand la servir ?</h2><p>x</p>', 'X', 'fr', 'Tarte aux pommes normande' ) ) );
msrwa_test_assert( 'Une tarte dorée et crémeuse' === $first( MSRWA_Article::tidy( '<h2>Quiche lorraine facile : une tarte dorée et crémeuse</h2><p>x</p>', 'X', 'fr', 'Quiche lorraine' ) ), 'The name’s own qualifier goes with it.' );
msrwa_test_assert( '' === $first( MSRWA_Article::tidy( '<h2>Quiche lorraine facile</h2><p>x</p>', 'X', 'fr', 'Quiche lorraine' ) ), 'A heading with nothing left to say goes.' );
msrwa_test_assert( 'Pourquoi cette recette fonctionne-t-elle ?' === $first( MSRWA_Article::tidy( '<h2>Pourquoi cette recette fonctionne-t-elle ?</h2><p>x</p>', 'X', 'fr', 'Quiche lorraine' ) ), 'A heading that does not open on the name is left alone.' );
msrwa_test_missing( MSRWA_Article::tidy( '<h1>Quiche</h1><h2>Pourquoi ?</h2><p>x</p>', 'X', 'fr', 'Quiche lorraine' ), '<h1', 'The site prints the title: no h1 in the text.' );
msrwa_test_contains( MSRWA_Article::tidy( '<h2>Pourquoi ?</h2><h2>Quiche lorraine : les ingrédients</h2>', 'X', 'fr', 'Quiche lorraine' ), 'Quiche lorraine : les ingrédients', 'Only the first heading is concerned.' );

// The post's title is the article's headline when it is one for this dish.
msrwa_test_assert( 'Quiche lorraine maison, fondante et bien dorée' === MSRWA_Article::headline( 'Quiche lorraine maison, fondante et bien dorée !', 'Quiche lorraine' ), 'A headline naming the dish is the post’s title.' );
msrwa_test_assert( 'Osso buco à la milanaise, fondant et parfumé au citron' === MSRWA_Article::headline( 'Osso buco à la milanaise, fondant et parfumé au citron', 'Osso buco de veau classique' ), 'Naming the dish in its own words is enough.' );
msrwa_test_assert( 'Quiche lorraine' === MSRWA_Article::headline( 'Une tarte salée fondante et bien dorée pour le dîner', 'Quiche lorraine' ), 'A headline that does not name the dish is not used.' );
msrwa_test_assert( 'Quiche lorraine' === MSRWA_Article::headline( 'Quiche', 'Quiche lorraine' ), 'Nor one too short to be a headline.' );
msrwa_test_assert( 'Quiche lorraine' === MSRWA_Article::headline( '', 'Quiche lorraine' ), 'Without a headline, the dish’s name.' );
msrwa_test_assert( 'Quiche lorraine maison, fondante et dorée 😋' === MSRWA_Article::headline( '<b>Quiche lorraine maison, fondante et dorée</b> 😋', 'Quiche lorraine' ), 'Model output is data: tags go, an emoji may stay.' );

msrwa_test_done( 'the draft opens on the article, not on its title or a label, and page one points to page two' );
