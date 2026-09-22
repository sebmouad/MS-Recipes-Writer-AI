<?php
require __DIR__ . '/bootstrap.php';
require_once MSRWA_DIR . 'includes/class-msrwa-operations.php';
require_once MSRWA_DIR . 'includes/class-msrwa-run.php';
function wp_upload_dir() { return array( 'basedir' => MSRWA_DIR . 'tests' ); }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['ops_options'][ $key ] = $value; }

// Analytics moved to its own screen; authorisation is still checked before a
// single row is read, which is the property that matters.
require_once MSRWA_DIR . 'includes/class-msrwa-rights.php';
require_once MSRWA_DIR . 'includes/class-msrwa-i18n.php';
require_once MSRWA_DIR . 'includes/class-msrwa-profile.php';
require_once MSRWA_DIR . 'includes/class-msrwa-ui.php';
require_once MSRWA_DIR . 'includes/class-msrwa-ledger.php';
require_once MSRWA_DIR . 'includes/class-msrwa-screen-analysis.php';

try { MSRWA_Screen_Analysis::render(); msrwa_test_assert( false, 'A writer must not reach the whole ledger.' ); }
catch ( RuntimeException $error ) { msrwa_test_assert( '' !== $error->getMessage(), 'Analysis checks authorisation before querying.' ); }
msrwa_test_assert( ! $GLOBALS['wpdb']->queries, 'A refused reader never reaches the database.' );
$image = MSRWA_Operations::safe_image( array( 'path' => __FILE__, 'mime' => 'image/png' ), 1 );
msrwa_test_assert( '' === $image['path'], 'Report refuses paths outside the job image directory.' );
$image = MSRWA_Operations::safe_image( array( 'path' => '/missing/image.webp' ), 1 );
msrwa_test_assert( '' === $image['path'], 'Missing images are safe placeholders.' );
$GLOBALS['wpdb']->on( "status = 'running'", array( 17 ) );
MSRWA_Run::recover_expired();
msrwa_test_contains( $GLOBALS['wpdb']->log(), "WHERE id = 17 AND status = 'running' AND lock_until IS NOT NULL AND lock_until < UTC_TIMESTAMP()", 'Recovery rechecks lease and status atomically.' );
// Priority first, then age: a recipe pushed to the front of the queue should
// not wait behind everything that merely arrived earlier.
msrwa_test_contains( $GLOBALS['wpdb']->log(), "WHERE status = 'queued' ORDER BY priority DESC, updated_at ASC LIMIT 100", 'The watchdog re-arms waiting jobs in priority order.' );
msrwa_test_assert( isset( $GLOBALS['ops_options']['msrwa_watchdog_at'] ), 'Watchdog records heartbeat.' );
require_once MSRWA_DIR . 'tools/report.php';
$html = report_render( array( 'artifacts' => array(), 'steps' => array(), 'events' => array(), 'totals' => array(), 'ok' => false ) );
foreach ( array( 'Recette canonique', 'SEO, publication', 'Visuels générés', 'Appels aux fournisseurs', 'Configuration de ce passage' ) as $section ) { msrwa_test_contains( $html, $section, 'Shared lab report retains ' . $section ); }

// Previewing an unsaved configuration must resolve every step that actually
// calls a model, and none that does not: 'corrections' has no prompt file at
// all, and asking for one read the prompts directory as if it were a file.
require_once MSRWA_DIR . 'includes/engine/load.php';
require_once MSRWA_DIR . 'includes/class-msrwa-engine-settings.php';
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $data ) { return $data; } }
class MSRWA_Test_Preview_Request {
	private $params;
	public function __construct( array $params ) { $this->params = $params; }
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
}
msrwa_test_as_admin();
$preview = MSRWA_Operations::preview( new MSRWA_Test_Preview_Request( array( 'config' => array() ) ) );
msrwa_test_assert( ! ( $preview instanceof WP_Error ), 'Previewing the defaults does not error.' );
msrwa_test_assert( ! isset( $preview['routes']['corrections'] ), 'A step with no capability has no route to preview.' );
msrwa_test_assert( isset( $preview['routes']['article'] ), 'A step that does call a model is still previewed.' );
msrwa_test_assert( 'string' === gettype( $preview['routes']['article']['prompt_source'] ), 'A previewed step still resolves its prompt.' );

msrwa_test_done( 'operations and report contracts' );
