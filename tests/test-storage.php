<?php
// Nothing is stored twice.
//
// The article ends up in post_content, the recipe and the verdict in post meta,
// the images in the media library. Keeping a second copy in this plugin's
// tables bought nothing and cost a great deal — the article alone was held
// three times, as written, corrected and proofread, some ninety kilobytes a
// run. These hold the arrangement in place: the rows stay while the engine
// still needs them, go once WordPress has them, and every screen reads through
// to WordPress afterwards as though nothing had moved.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
foreach ( glob( MSRWA_DIR . 'includes/*.php' ) as $class ) { require_once $class; }
require_once MSRWA_DIR . 'includes/engine/load.php';
require __DIR__ . '/bootstrap.php';

msrwa_test_as_admin();

// A run with no draft keeps everything: the next cron tick reads it back.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SELECT * FROM', array( array( 'id' => 4, 'batch_id' => 1, 'owner_id' => 1, 'status' => 'running', 'draft_post_id' => 0 ) ) );
msrwa_test_assert( 0 === MSRWA_Run::release_stored( 4 ), 'Nothing is released while the run is still in flight.' );
msrwa_test_missing( $GLOBALS['wpdb']->log(), 'DELETE FROM', 'And nothing is deleted.' );

// Once the draft exists, the copies WordPress holds go — and only those.
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SELECT * FROM', array( array( 'id' => 4, 'batch_id' => 1, 'owner_id' => 1, 'status' => 'done', 'draft_post_id' => 77 ) ) );
MSRWA_Run::release_stored( 4 );
$log = $GLOBALS['wpdb']->log();

msrwa_test_contains( $log, 'DELETE FROM wp_msrwa_artifacts WHERE run_id = 4', 'The duplicated artifacts are removed.' );
foreach ( array( 'article', 'corrected', 'canonical', 'approval' ) as $key ) {
	msrwa_test_contains( $log, "'" . $key . "'", $key . ' is a copy WordPress keeps, so this one goes.' );
}
// Everything with no WordPress home survives — and so does the proofread
// article, which is the machine's own output. post_content is what an editor
// has since changed; measuring the engine against that would measure editors.
foreach ( array( 'research', 'review', 'fact_check', 'featured', 'facebook', 'proofread' ) as $key ) {
	msrwa_test_missing( $log, "'" . $key . "'", $key . ' must be kept.' );
}

// And reading them back reaches through to WordPress rather than returning a
// gap where the article used to be.
$rehydrate = new ReflectionMethod( MSRWA_Run::class, 'rehydrate' );
$rehydrate->setAccessible( true );

$GLOBALS['msrwa_test_posts'][77] = (object) array( 'ID' => 77, 'post_title' => 'Tarte', 'post_content' => '<p>Le texte relu.</p>' );
$GLOBALS['msrwa_test_meta'][77] = array(
	'_msrwa_recipe' => wp_json_encode( array( 'title' => 'Tarte', 'ingredients' => array() ) ),
	'_msrwa_judge_report' => wp_json_encode( array( 'approved' => true ) ),
	'_msrwa_featured_image_id' => 91,
);

$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->on( 'SELECT * FROM', array( array( 'id' => 4, 'batch_id' => 1, 'owner_id' => 1, 'status' => 'done', 'draft_post_id' => 77 ) ) );
$read = $rehydrate->invoke( null, 4, array( 'research' => array( 'kept' => true ), 'featured' => array( 'kind' => 'featured', 'path' => '/gone.webp' ) ) );

msrwa_test_contains( ( $read['published']['content_html'] ?? '' ), 'Le texte relu', 'What the reader gets is read back from the post.' );
msrwa_test_assert( isset( $read['proofread'] ), 'And the machine’s own version is still there beside it.' );
msrwa_test_assert( 'Tarte' === ( $read['canonical']['title'] ?? '' ), 'The recipe is read back from post meta.' );
msrwa_test_assert( true === ( $read['approval']['approved'] ?? null ), 'The verdict is read back from post meta.' );
msrwa_test_assert( 91 === ( $read['featured']['attachment_id'] ?? 0 ), 'An image points at the media library copy.' );
msrwa_test_assert( isset( $read['research']['kept'] ), 'What was never duplicated is untouched.' );

// A stored path outside the run's own directory is never deleted, whatever it
// claims to be.
$path = new ReflectionMethod( MSRWA_Run::class, 'artifact_path' );
$path->setAccessible( true );
$GLOBALS['wpdb'] = new MSRWA_Fake_Wpdb();
$GLOBALS['wpdb']->default_var( wp_json_encode( array( 'path' => __FILE__ ) ) );
msrwa_test_assert( '' === $path->invoke( null, 4, 'featured' ), 'A path outside the run’s directory is refused.' );

// --- A credential inside somebody else's prose --------------------------

// Masking by key name catches a payload this plugin built. It does not catch
// what a provider says back: OpenAI answers a bad key with "Incorrect API key
// provided: sk-proj-…", and that sentence used to be stored whole as a step's
// error and shown on two screens.
$said = 'HTTP 401: {"error":{"message":"Incorrect API key provided: sk-proj-AbCdEf123456789. You can find your API key at https://platform.openai.com/account/api-keys"}}';
$clean = MSRWA_DB::sanitize( $said );
msrwa_test_missing( $clean, 'sk-proj-AbCdEf123456789', 'An OpenAI key quoted back at us is not stored.' );
msrwa_test_contains( $clean, 'Incorrect API key provided', 'But the sentence that explains the failure is kept.' );
msrwa_test_contains( $clean, 'HTTP 401', 'And so is the status, which is what an operator acts on.' );

foreach ( array(
	'sk-ant-api03-ZZZZZZZZZZZZZZZZZZZZ' => 'an Anthropic key',
	'AIzaSyB1234567890abcdefghijk' => 'a Google key',
) as $secret => $what ) {
	msrwa_test_missing( MSRWA_DB::sanitize( 'error: ' . $secret . ' rejected' ), $secret, $what . ' is not stored either.' );
}
msrwa_test_missing( MSRWA_DB::sanitize( 'Authorization: Bearer abcdef0123456789xyz' ), 'abcdef0123456789xyz', 'Nor a bearer token, whoever minted it.' );

// It is applied everywhere sanitize() already was, so a nested string is
// covered too — an error inside a run's result JSON, for instance.
$nested = MSRWA_DB::sanitize( array( 'errors' => array( array( 'message' => 'bad key sk-proj-AbCdEf123456789' ) ) ) );
msrwa_test_missing( wp_json_encode( $nested ), 'sk-proj-AbCdEf1234', 'A key nested inside an array is masked as well.' );

// Ordinary prose must survive intact: over-redacting an error makes it useless.
$innocent = 'HTTP 429: You exceeded your current quota, please check your plan and billing details.';
msrwa_test_assert( $innocent === MSRWA_DB::sanitize( $innocent ), 'An error with no credential in it is stored word for word.' );

msrwa_test_done( 'nothing is stored twice' );
