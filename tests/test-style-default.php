<?php
// The collage every test was drawn from ships with the plugin: a site that has
// uploaded nothing still draws in the approved look, and its own collage, once
// uploaded, takes over.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'sources' );

$default = MSRWA_Sources::default_style();
msrwa_test_assert( '' !== $default && is_file( $default ), 'A default reference ships with the plugin.' );
$size = getimagesize( $default );
msrwa_test_assert( 'image/jpeg' === ( $size['mime'] ?? '' ), 'It is a JPEG.' );
msrwa_test_assert( max( (int) $size[0], (int) $size[1] ) <= 768, 'Already at the size the engine sends, so no pixel is paid for twice.' );
msrwa_test_assert( (int) $size[1] > (int) $size[0], 'A portrait collage, like the one it stands for.' );
msrwa_test_assert( filesize( $default ) < 200000, 'Light enough to ship.' );

foreach ( MSRWA_Sources::style_paths() as $path ) { @unlink( $path ); }
msrwa_test_assert( array( $default ) === MSRWA_Sources::style_in_use(), 'With nothing uploaded, the shipped reference is the one drawn from.' );

$upload = sys_get_temp_dir() . '/msrwa-style-upload.png';
$image = imagecreatetruecolor( 20, 30 );
imagepng( $image, $upload );
msrwa_test_assert( '' === MSRWA_Sources::add_style( array( 'tmp_name' => $upload ) ), 'The owner can add a collage.' );
$in_use = MSRWA_Sources::style_in_use();
msrwa_test_assert( 1 === count( $in_use ) && $default !== $in_use[0], 'Their collage replaces the shipped one.' );
MSRWA_Sources::remove_style( basename( $in_use[0] ) );
msrwa_test_assert( array( $default ) === MSRWA_Sources::style_in_use(), 'Removing it goes back to the shipped one.' );
@unlink( $upload );

msrwa_test_done( 'the shipped collage is the default reference' );
