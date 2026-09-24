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

// A photograph from disk becomes part of the lot, kept in the plugin's own
// folder: the media library holds only what articles show.
$sent = msrwa_real_upload( '/msrwa/v1/batches', array( 'recipes' => $recipe, 'profile' => 'article', 'language' => 'fr', 'budget' => '0.3' ), array( $photo ) );
msrwa_real_assert( 200 === $sent['status'], 'A lot with a photograph from the computer is accepted (got ' . $sent['status'] . ': ' . wp_json_encode_compat( $sent['body'] ) . ').' );
msrwa_real_assert( 1 === (int) ( $sent['body']['images'] ?? 0 ), 'The photograph sent is the one photograph of the lot.' );
msrwa_real_spend( 0.01 );
$media = msrwa_real_request( 'GET', '/wp/v2/media?per_page=1&orderby=id&order=desc&context=edit' );
msrwa_real_assert( $last === (int) ( $media['body'][0]['id'] ?? 0 ), 'Nothing was added to the media library.' );
$lot = (int) $sent['body']['id'];
$name = substr( hash_file( 'sha256', $photo ), 0, 32 ) . '.jpg';
$shown = msrwa_real_request( 'GET', '/msrwa/v1/batches/' . $lot . '/photos/' . $name );
msrwa_real_assert( 200 === $shown['status'] && 0 === strpos( $shown['raw'], "\xFF\xD8" ), 'Its writer sees it through the REST API (got ' . $shown['status'] . ').' );
$stranger = (string) shell_exec( 'curl -s -o /dev/null -w "%{http_code}" ' . escapeshellarg( msrwa_real_rest_url( '/msrwa/v1/batches/' . $lot . '/photos/' . $name ) ) );
msrwa_real_assert( in_array( (int) $stranger, array( 401, 403 ), true ), 'Nobody logged out does (got ' . $stranger . ').' );
msrwa_real_note( 'lot #' . $lot . ', photograph ' . $name );

// The same photograph again, in another lot: named after its bytes, it is
// never a file that already exists.
$again = msrwa_real_upload( '/msrwa/v1/batches', array( 'recipes' => $recipe, 'profile' => 'article', 'language' => 'fr' ), array( $photo, $photo ) );
msrwa_real_assert( 200 === $again['status'] && 1 === (int) ( $again['body']['images'] ?? 0 ), 'The same photograph sent twice more is accepted, and kept once (got ' . $again['status'] . ': ' . wp_json_encode_compat( $again['body'] ) . ').' );
msrwa_real_spend( 0.01 );

// A lot deleted before it was sent takes its photograph with it.
msrwa_real_request( 'DELETE', '/msrwa/v1/batches/' . $lot );
$gone = msrwa_real_request( 'GET', '/msrwa/v1/batches/' . (int) ( $again['body']['id'] ?? 0 ) . '/photos/' . $name );
msrwa_real_assert( 200 === $gone['status'], 'The other lot keeps its own copy.' );
msrwa_real_request( 'DELETE', '/msrwa/v1/batches/' . (int) ( $again['body']['id'] ?? 0 ) );
$gone = msrwa_real_request( 'GET', '/msrwa/v1/batches/' . (int) ( $again['body']['id'] ?? 0 ) . '/photos/' . $name );
msrwa_real_assert( 404 === $gone['status'], 'Deleting the lot removed its photograph (got ' . $gone['status'] . ').' );
// Photographs without any text: each dish they show becomes a recipe. Needs
// real photographs of food — a drawn disc names no dish — so it runs only
// when MSRWA_TEST_PHOTO (and, for two dishes, MSRWA_TEST_PHOTO_2) name some.
$real = array_values( array_filter( array( getenv( 'MSRWA_TEST_PHOTO' ), getenv( 'MSRWA_TEST_PHOTO_2' ) ), static function ( $file ) { return $file && is_readable( $file ); } ) );
if ( $real ) {
	$alone = msrwa_real_upload( '/msrwa/v1/batches', array( 'recipes' => '', 'profile' => 'article' ), $real );
	msrwa_real_assert( 200 === $alone['status'], 'Photographs alone make a lot (got ' . $alone['status'] . ': ' . wp_json_encode_compat( $alone['body'] ) . ').' );
	msrwa_real_spend( 0.01 * count( $real ) + 0.005 );
	msrwa_real_assert( count( $real ) === (int) ( $alone['body']['recipes'] ?? 0 ), 'Each dish photographed became one recipe: ' . count( $real ) . ' expected, ' . (int) ( $alone['body']['recipes'] ?? 0 ) . ' made.' );
	msrwa_real_note( 'photographs alone: lot #' . (int) ( $alone['body']['id'] ?? 0 ) . ', ' . (int) ( $alone['body']['recipes'] ?? 0 ) . ' recipe(s) from ' . count( $real ) . ' photograph(s)' );
	if ( ! empty( $alone['body']['id'] ) ) { msrwa_real_request( 'DELETE', '/msrwa/v1/batches/' . (int) $alone['body']['id'] ); }
} else {
	msrwa_real_note( 'MSRWA_TEST_PHOTO not set: photographs without text not tried' );
}

// Neither text nor photograph is refused before anything is spent.
$empty = msrwa_real_upload( '/msrwa/v1/batches', array( 'recipes' => '', 'profile' => 'article' ), array() );
msrwa_real_assert( 400 === $empty['status'], 'A lot with neither text nor photograph is refused (got ' . $empty['status'] . ').' );

array_map( 'unlink', glob( $dir . '/*' ) );
@rmdir( $dir );
msrwa_real_done( 'photographs are sent from the computer, checked, kept out of the media library, and enough on their own' );
