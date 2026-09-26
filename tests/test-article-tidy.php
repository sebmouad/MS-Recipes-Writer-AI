<?php
// Seen on real drafts: the title repeated as the first heading, text opening
// on « Introduction : » or « Deuxième partie », and a first page that ended
// without saying the recipe goes on. The draft is cleaned of all three.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'profile', 'article' );

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
msrwa_test_contains( MSRWA_Article::tidy( '<h2>Tarte Tatin aux pommes caramélisées</h2><p>x</p>', 'Tarte Tatin', 'fr' ), '<h2>Tarte Tatin aux pommes', 'A heading that only starts like the title is a real heading.' );

msrwa_test_done( 'the draft opens on the article, not on its title or a label, and page one points to page two' );
