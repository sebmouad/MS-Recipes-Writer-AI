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

// All or nothing: one bad file refuses the lot before anything is kept.
msrwa_test_contains( MSRWA_Intake::check( array( $photo( 'tarte.png', $dir . '/tarte.png' ), $photo( 'x.png', $dir . '/script.php' ) ) ), 'JPEG, PNG ou WebP', 'One file that is not a photograph refuses the lot.' );
msrwa_test_assert( '' === MSRWA_Intake::check( array( $photo( 'tarte.png', $dir . '/tarte.png' ) ) ), 'A sound photograph is accepted.' );
$many = array_fill( 0, MSRWA_Intake::MAX_PHOTOS + 1, $photo( 'tarte.png', $dir . '/tarte.png' ) );
msrwa_test_contains( MSRWA_Intake::check( $many ), (string) MSRWA_Intake::MAX_PHOTOS, 'A lot carries a bounded number of photographs: each is a paid description.' );

// Kept in the plugin's own folder, never in the media library, which holds
// only what articles show. Named after their bytes: the same photograph sent
// twice — in one lot or in two — is one file, never a name that already exists.
msrwa_test_load( 'db', 'sources' );
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); } }
$root = MSRWA_Sources::root();
// A previous run of this test may have left its folders behind if it failed.
foreach ( array( 7, 8 ) as $lot ) { MSRWA_Sources::forget_lot( $lot ); }
MSRWA_Sources::forget_run( 41 );
msrwa_test_assert( is_file( $root . '/.htaccess' ) && is_file( $root . '/index.php' ), 'The folder is closed to the web.' );
$kept = MSRWA_Sources::receive( 7, array( $photo( 'Tarte Normande.png', $dir . '/tarte.png' ), $photo( 'copie.png', $dir . '/refuse.png' ) ) );
msrwa_test_assert( 1 === count( $kept ), 'The same photograph twice in one lot is kept once.' );
msrwa_test_assert( (bool) preg_match( '/^[a-f0-9]{32}\.png$/', $kept[0]['id'] ) && false !== strpos( $kept[0]['title'], 'Normande' ) && false === strpos( $kept[0]['title'], '.png' ), 'It is named after its bytes and keeps the writer’s title: ' . json_encode( $kept ) );
msrwa_test_assert( 1 === count( MSRWA_Sources::receive( 8, array( $photo( 'tarte.png', $dir . '/tarte.png' ) ) ) ), 'The same photograph in another lot is accepted again.' );
msrwa_test_assert( ! $GLOBALS['sideloaded'], 'Nothing enters the media library.' );
msrwa_test_contains( $kept[0]['url'], 'msrwa/v1/batches/7/photos/', 'The pairing screen shows it through the REST API, behind its checks.' );
msrwa_test_assert( isset( MSRWA_Sources::read( MSRWA_Sources::lot_dir( 7 ), $kept[0]['id'] )['data'] ), 'It is read back for the vision calls.' );
msrwa_test_assert( '' === MSRWA_Sources::path( MSRWA_Sources::lot_dir( 7 ), '../8/' . $kept[0]['id'] ), 'A name is never a path.' );
msrwa_test_assert( '' === MSRWA_Sources::name( '../../wp-config.php' ), 'Anything but a stored name is refused.' );

// Sent: each recipe's photographs move into its run's folder, and its history
// records the brief it was handed, what the engine found, what it completed.
msrwa_test_load( 'history' );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
MSRWA_History::lot( 7, 'provided', array( 'recipes' => array( array( 'title' => 'Tarte', 'text' => 'Pommes.' ) ) ) );
MSRWA_History::inherit( 7, 41 );
MSRWA_Sources::hand_over( 7, 41, array( 'title' => 'Tarte', 'text' => 'Pommes.', 'images' => array( array( 'id' => $kept[0]['id'], 'title' => 'Tarte Normande', 'observation' => array( 'colours' => 'doré' ) ) ) ) );
$history = array_map( 'basename', (array) glob( MSRWA_History::dir( 41 ) . '/*.json' ) );
msrwa_test_assert( array( '01-provided.json', '02-brief.json' ) === $history, 'The job’s history opens with its lot’s pages, then its brief: ' . implode( ', ', $history ) );
$brief = json_decode( file_get_contents( MSRWA_History::dir( 41 ) . '/02-brief.json' ), true );
msrwa_test_assert( 'Pommes.' === $brief['text_brief']['text'] && $kept[0]['id'] === $brief['visual_brief']['photos'][0]['file'] && 'writer' === $brief['visual_brief']['source'], 'The brief holds the text reference and the visual one: ' . json_encode( $brief ) );
msrwa_test_assert( is_file( MSRWA_Sources::run_dir( 41 ) . '/' . $kept[0]['id'] ), 'The photograph is in the run’s folder.' );
msrwa_test_assert( ! is_file( MSRWA_Sources::lot_dir( 7 ) . '/' . $kept[0]['id'] ), 'It is moved, not copied: a photograph belongs to one recipe.' );
$reader = MSRWA_Sources::reader( 41 );
msrwa_test_assert( isset( $reader( array( 'id' => $kept[0]['id'] ), 1000000 )['data'] ), 'The engine reads it from there.' );
$keep = MSRWA_Sources::keeper( 41 );
$keep( 'https://example.org/tarte.jpg', MSRWA_Sources::read( MSRWA_Sources::run_dir( 41 ), $kept[0]['id'] ) );
msrwa_test_assert( 1 === count( (array) glob( MSRWA_Sources::run_dir( 41 ) . '/references/*.png' ) ) && is_file( MSRWA_History::dir( 41 ) . '/03-web_reference.json' ), 'A photograph the engine found is kept and recorded.' );
MSRWA_Sources::complete( 41, array( 'dish_identity' => array( 'name' => 'Tarte normande' ), 'ingredients' => array( array( 'name' => 'pommes' ) ), 'preparation' => array() ) );
$completed = json_decode( file_get_contents( MSRWA_History::dir( 41 ) . '/04-engine_brief.json' ), true );
msrwa_test_assert( 'Tarte normande' === $completed['text_brief']['dish']['name'], 'What the engine completed is recorded after it.' );
$rows = array_column( $GLOBALS['wpdb']->inserted, 1 );
msrwa_test_assert( array( 'provided', 'brief', 'web_reference', 'engine_brief' ) === array_column( $rows, 'stage' ), 'Each page is also a row: ' . implode( ', ', array_column( $rows, 'stage' ) ) );
msrwa_test_assert( 0 === $rows[0]['run_id'] && 41 === $rows[1]['run_id'], 'A lot’s page belongs to the lot until the lot is sent.' );
MSRWA_History::run( 41, 'step', array( 'step' => 'research', 'error' => 'refused: key sk-proj-abcdefghijklmnopqrstuvwxyz0123456789' ), 7 );
msrwa_test_missing( (string) file_get_contents( MSRWA_History::dir( 41 ) . '/05-step-research.json' ), 'sk-proj-abcdefghijklmnopqrstuvwxyz', 'No secret reaches the history.' );
MSRWA_Sources::forget_lot( 7 );
MSRWA_Sources::forget_lot( 8 );
MSRWA_Sources::forget_run( 41 );
MSRWA_History::forget_run( 41 );
msrwa_test_assert( ! is_dir( MSRWA_Sources::lot_dir( 7 ) ) && ! is_dir( dirname( MSRWA_Sources::run_dir( 41 ) ) ), 'A lot and a run leave nothing behind.' );

// Lots sent before 0.24.0 named library photographs; deleting one still
// removes only its own photographs that no draft took.
$attachment = static function ( $id, $parent ) { return (object) array( 'ID' => $id, 'post_type' => 'attachment', 'post_parent' => $parent ); };
$GLOBALS['msrwa_test_posts'][201] = $attachment( 201, 0 );
$GLOBALS['msrwa_test_posts'][202] = $attachment( 202, 0 );
$GLOBALS['msrwa_test_posts'][203] = $attachment( 203, 0 );
$GLOBALS['msrwa_test_posts'][204] = $attachment( 204, 7 );
foreach ( array( 201, 202, 204 ) as $id ) { update_post_meta( $id, MSRWA_Intake::SENT, 1 ); }
$GLOBALS['deleted'] = array();
MSRWA_Intake::forget( array( 201, 202, 203, 204 ) );
msrwa_test_assert( array( 201, 202 ) === $GLOBALS['deleted'], 'A deleted lot removes only its own photographs that no draft took; removed: ' . implode( ',', $GLOBALS['deleted'] ) );

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
