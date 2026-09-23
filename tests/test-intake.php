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


// --- Photographs sent from the writer's computer --------------------------
if ( ! function_exists( 'sanitize_file_name' ) ) { function sanitize_file_name( $name ) { return preg_replace( '/[^A-Za-z0-9._-]/', '', basename( (string) $name ) ); } }
// WordPress reads the type from the bytes; so does this stand-in.
function wp_check_filetype_and_ext( $file, $name, $mimes = null ) {
	$size = @getimagesize( $file );
	$type = $size && in_array( $size['mime'], (array) $mimes, true ) ? $size['mime'] : false;
	return array( 'ext' => $type ? 'x' : false, 'type' => $type, 'proper_filename' => false );
}
$GLOBALS['sideloaded'] = array();
$GLOBALS['deleted'] = array();
function media_handle_sideload( $file, $post_id = 0 ) {
	if ( 'refuse.png' === $file['name'] ) { return new WP_Error( 'upload', 'disque plein' ); }
	$GLOBALS['sideloaded'][] = $file['name'];
	return 100 + count( $GLOBALS['sideloaded'] );
}
function wp_delete_attachment( $id, $force = false ) { $GLOBALS['deleted'][] = $id; }

$dir = sys_get_temp_dir() . '/msrwa-intake-' . getmypid();
@mkdir( $dir );
file_put_contents( $dir . '/tarte.png', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
file_put_contents( $dir . '/script.php', '<?php echo 1;' );
copy( $dir . '/tarte.png', $dir . '/refuse.png' );
$photo = static function ( $name, $tmp, $error = UPLOAD_ERR_OK ) { return array( 'name' => $name, 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => $error, 'size' => (int) @filesize( $tmp ) ); };

// PHP spreads a multiple upload across parallel arrays; one file at a time is
// what can be checked. An empty slot the browser sends is no file.
$files = MSRWA_Intake::files( array(
	'name' => array( 'tarte.png', 'yassa.jpg', '' ), 'type' => array( 'image/png', 'image/jpeg', '' ),
	'tmp_name' => array( '/tmp/a', '/tmp/b', '' ), 'error' => array( 0, 0, UPLOAD_ERR_NO_FILE ), 'size' => array( 1, 2, 0 ),
) );
msrwa_test_assert( 2 === count( $files ) && 'yassa.jpg' === $files[1]['name'] && '/tmp/b' === $files[1]['tmp_name'], 'A multiple upload becomes one entry per file.' );
msrwa_test_assert( array() === MSRWA_Intake::files( null ), 'No photograph sent is no photograph.' );

msrwa_test_assert( '' === MSRWA_Intake::refuse( $photo( 'tarte.png', $dir . '/tarte.png' ) ), 'A real PNG is a photograph.' );
msrwa_test_contains( MSRWA_Intake::refuse( $photo( 'tarte.png', $dir . '/script.php' ) ), 'JPEG, PNG ou WebP', 'A script named like a photograph is refused from its bytes, whatever its name or claimed type.' );
msrwa_test_contains( MSRWA_Intake::refuse( $photo( 'tarte.png', $dir . '/tarte.png', UPLOAD_ERR_INI_SIZE ) ), 'en entier', 'A file the server cut is refused.' );
msrwa_test_contains( MSRWA_Intake::refuse( $photo( 'tarte.png', $dir . '/tarte.png' ), 10 ), 'dépasse', 'A photograph over the size limit is refused.' );

// All or nothing: one bad file refuses the lot before anything is added.
$refused = MSRWA_Intake::upload( array( $photo( 'tarte.png', $dir . '/tarte.png' ), $photo( 'x.png', $dir . '/script.php' ) ) );
msrwa_test_assert( '' !== $refused['error'] && ! $refused['ids'] && ! $GLOBALS['sideloaded'], 'One file that is not a photograph refuses the lot and adds nothing to the library.' );

$sent = MSRWA_Intake::upload( array( $photo( 'tarte.png', $dir . '/tarte.png' ) ) );
msrwa_test_assert( '' === $sent['error'] && array( 101 ) === $sent['ids'], 'A sound photograph enters the media library and comes back as an attachment.' );

// A library failure halfway removes what the submission already added.
$GLOBALS['sideloaded'] = array();
$half = MSRWA_Intake::upload( array( $photo( 'tarte.png', $dir . '/tarte.png' ), $photo( 'refuse.png', $dir . '/refuse.png' ) ) );
msrwa_test_assert( '' !== $half['error'] && array( 101 ) === $GLOBALS['deleted'], 'A failed submission leaves no orphan in the library.' );

$many = array_fill( 0, MSRWA_Intake::MAX_PHOTOS + 1, $photo( 'tarte.png', $dir . '/tarte.png' ) );
msrwa_test_contains( MSRWA_Intake::upload( $many )['error'], (string) MSRWA_Intake::MAX_PHOTOS, 'A lot carries a bounded number of photographs: each is a paid description.' );
msrwa_test_assert( ! empty( $GLOBALS['msrwa_test_meta'][101][ MSRWA_Intake::SENT ] ), 'A photograph the plugin added is marked as sent by a writer.' );

// A sent photograph goes to its recipe's draft; one no draft took leaves with
// its lot. Anything else in the library is never touched, whoever sent it.
$GLOBALS['updated'] = array();
function wp_update_post( $post ) { $GLOBALS['updated'][] = $post; $GLOBALS['msrwa_test_posts'][ $post['ID'] ]->post_parent = $post['post_parent']; return $post['ID']; }
$attachment = static function ( $id, $parent ) { return (object) array( 'ID' => $id, 'post_type' => 'attachment', 'post_parent' => $parent ); };
$GLOBALS['msrwa_test_posts'][201] = $attachment( 201, 0 );
$GLOBALS['msrwa_test_posts'][202] = $attachment( 202, 0 );
$GLOBALS['msrwa_test_posts'][203] = $attachment( 203, 0 );
$GLOBALS['msrwa_test_posts'][204] = $attachment( 204, 7 );
foreach ( array( 201, 202, 204 ) as $id ) { update_post_meta( $id, MSRWA_Intake::SENT, 1 ); }
MSRWA_Intake::adopt( 90, array( 201, 203, 204 ) );
msrwa_test_assert( 90 === $GLOBALS['msrwa_test_posts'][201]->post_parent, 'The draft takes its recipe\'s photograph.' );
msrwa_test_assert( 0 === $GLOBALS['msrwa_test_posts'][203]->post_parent, 'A photograph the plugin did not add is not moved.' );
msrwa_test_assert( 7 === $GLOBALS['msrwa_test_posts'][204]->post_parent, 'A photograph already attached elsewhere is not moved.' );
$GLOBALS['deleted'] = array();
MSRWA_Intake::forget( array( 201, 202, 203, 204 ) );
msrwa_test_assert( array( 202 ) === $GLOBALS['deleted'], 'A deleted lot removes only its own photographs that no draft took; removed: ' . implode( ',', $GLOBALS['deleted'] ) );

array_map( 'unlink', glob( $dir . '/*' ) );
@rmdir( $dir );

// The media library is no longer offered, and an attachment id posted to the
// endpoint is not read: only what the writer sent can be in their lot.
$compose = file_get_contents( dirname( __DIR__ ) . '/includes/class-msrwa-screen-compose.php' );
msrwa_test_contains( $compose, 'type="file"', 'The compose screen takes photographs from the computer.' );
msrwa_test_assert( false === strpos( $compose, 'médiathèque\', \'ms' ) && false === strpos( file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' ), 'wp.media' ), 'The media library picker is gone.' );
$rest = file_get_contents( dirname( __DIR__ ) . '/includes/class-msrwa-rest.php' );
$create = substr( $rest, strpos( $rest, 'public static function create(' ), 1500 );
msrwa_test_assert( false === strpos( $create, "get_param( 'images' )" ) && false !== strpos( $create, 'get_file_params' ), 'The lot endpoint reads uploaded files, never an attachment id from the request.' );

msrwa_test_done( 'intake OK' );
