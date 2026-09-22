<?php
// The stylesheet's own header states three constraints. Nothing enforced them,
// so each was one careless rule away from being untrue — and all three fail
// silently: an Arabic reader sees a mirrored layout, a dark-scheme reader sees
// a screen the owner never asked for, and a webfont phones home from somebody
// else's admin without anybody noticing.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require __DIR__ . '/bootstrap.php';

$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/admin.css' );
msrwa_test_assert( '' !== $css, 'The stylesheet must be readable.' );

// --- Light only, by the owner's decision ---------------------------------

msrwa_test_missing( $css, 'prefers-color-scheme', 'The interface follows no system colour preference: it is light.' );

// --- Nothing is left or right --------------------------------------------

// The interface ships in Arabic. A physical property does not mirror, so one
// `margin-left` is enough to break the rail in one language and no other.
$physical = array();
if ( preg_match_all( '~(?:^|[ \t;{])((?:margin|padding|border)-(?:left|right)|left|right|text-align\s*:\s*(?:left|right)|float\s*:\s*(?:left|right))\s*:~mi', $css, $found ) ) {
	$physical = array_unique( $found[1] );
}
msrwa_test_assert( array() === $physical, 'Logical properties only; found: ' . implode( ', ', $physical ) . '.' );

// --- No webfont, and nothing fetched from anywhere ------------------------

msrwa_test_missing( $css, '@import', 'The stylesheet imports nothing.' );
msrwa_test_assert( 0 === preg_match( '~url\(\s*[\'"]?https?:~i', $css ), 'And fetches nothing from another host: a plugin must not call out from somebody else’s admin.' );

// --- Every colour is a token ---------------------------------------------

// The header promises this, and it is what would make a scheme reinstatable in
// one block. A literal colour outside the token block is a colour no future
// scheme can reach.
$tokens = '';
if ( preg_match( '~\.msrwa\s*\{(.*?)\n\}~s', $css, $block ) ) { $tokens = $block[1]; }
msrwa_test_contains( $tokens, '--ms-ink', 'The token block must be the one that defines the palette.' );
$outside = str_replace( $tokens, '', $css );
$literals = array();
if ( preg_match_all( '~#[0-9a-f]{3,8}\b~i', $outside, $found ) ) { $literals = array_unique( $found[0] ); }
msrwa_test_assert( array() === $literals, 'Colours live in the token block; found loose: ' . implode( ', ', $literals ) . '.' );

msrwa_test_done( 'stylesheet constraints' );
