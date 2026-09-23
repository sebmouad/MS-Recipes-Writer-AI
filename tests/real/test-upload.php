<?php
// Photographs come from the writer's computer, as a real browser form sends
// them. Only a live site can answer this: the multipart parsing, the media
// library and the attachment ownership are WordPress's, not the plugin's.
// One photograph is described by a vision call, about a tenth of a cent.
require __DIR__ . '/lib.php';

if ( ! function_exists( 'imagecreatetruecolor' ) ) { msrwa_real_skip( 'PHP GD is needed here to draw the test photograph' ); }
msrwa_real_budget();
$dir = sys_get_temp_dir() . '/msrwa-real-upload-' . getmypid();
@mkdir( $dir );
$photo = $dir . '/tarte-aux-pommes.jpg';
$image = imagecreatetruecolor( 640, 480 );
imagefill( $image, 0, 0, imagecolorallocate( $image, 214, 170, 96 ) );
imagefilledellipse( $image, 320, 240, 420, 420, imagecolorallocate( $image, 190, 120, 40 ) );
imagejpeg( $image, $photo, 85 );
$fake = $dir . '/pas-une-photo.jpg';
file_put_contents( $fake, '<?php echo "not an image";' );

$recipe = "Tarte aux pommes normande\nPâte brisée, pommes, crème, œufs, calvados.";
$before = msrwa_real_request( 'GET', '/wp/v2/media?per_page=1&orderby=id&order=desc&context=edit' );
$last = (int) ( $before['body'][0]['id'] ?? 0 );

// A file that is not a photograph refuses the lot, and nothing enters the library.
$refused = msrwa_real_upload( '/msrwa/v1/batches', array( 'recipes' => $recipe, 'profile' => 'article', 'language' => 'fr' ), array( $photo, $fake ) );
msrwa_real_assert( 400 === $refused['status'], 'A file that is not a photograph refuses the lot (got ' . $refused['status'] . ').' );
msrwa_real_note( 'refused: ' . ( $refused['body']['message'] ?? '' ) );
$after = msrwa_real_request( 'GET', '/wp/v2/media?per_page=1&orderby=id&order=desc&context=edit' );
msrwa_real_assert( $last === (int) ( $after['body'][0]['id'] ?? 0 ), 'A refused lot leaves nothing in the media library, not even the good photograph.' );

// A photograph from disk becomes the writer's attachment and part of the lot.
$sent = msrwa_real_upload( '/msrwa/v1/batches', array( 'recipes' => $recipe, 'profile' => 'article', 'language' => 'fr', 'budget' => '0.3' ), array( $photo ) );
msrwa_real_assert( 200 === $sent['status'], 'A lot with a photograph from the computer is accepted (got ' . $sent['status'] . ': ' . wp_json_encode_compat( $sent['body'] ) . ').' );
msrwa_real_assert( 1 === (int) ( $sent['body']['images'] ?? 0 ), 'The photograph sent is the one photograph of the lot.' );
msrwa_real_spend( 0.01 );
$media = msrwa_real_request( 'GET', '/wp/v2/media?per_page=1&orderby=id&order=desc&context=edit' );
$added = (array) ( $media['body'][0] ?? array() );
msrwa_real_assert( (int) ( $added['id'] ?? 0 ) > $last, 'The photograph was added to the media library.' );
msrwa_real_assert( 'image/jpeg' === ( $added['mime_type'] ?? '' ), 'It is stored as the photograph it is.' );
$me = msrwa_real_request( 'GET', '/wp/v2/users/me' );
msrwa_real_assert( (int) ( $added['author'] ?? 0 ) === (int) ( $me['body']['id'] ?? -1 ), 'It belongs to the writer who sent it.' );
msrwa_real_note( 'lot #' . (int) $sent['body']['id'] . ', attachment #' . (int) $added['id'] );

// Nothing was dispatched; leave the site as it was.
msrwa_real_request( 'DELETE', '/msrwa/v1/batches/' . (int) $sent['body']['id'] );
msrwa_real_request( 'DELETE', '/wp/v2/media/' . (int) $added['id'] . '?force=true' );
array_map( 'unlink', glob( $dir . '/*' ) );
@rmdir( $dir );
msrwa_real_done( 'photographs are sent from the computer, checked, and owned by their writer' );
