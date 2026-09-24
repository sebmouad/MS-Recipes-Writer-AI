<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The engine, loadable with or without WordPress.
 *
 * It is the same code in both places by design: the lab measures exactly what
 * the plugin runs, so a prompt proven in one cannot drift from the other. The
 * engine touches no WordPress function — the plugin passes its settings in and
 * takes a result back.
 */
// Shared plugin logic the engine builds on. Each is guarded, so a caller that
// already loaded one — the plugin itself, or a test with its own double — keeps
// the one it has.
foreach ( array( 'json', 'recipe', 'quality', 'prompt', 'images', 'catalog' ) as $msrwa_engine_dependency ) {
	$msrwa_engine_class = 'MSRWA_' . ( 'json' === $msrwa_engine_dependency ? 'Json' : ucfirst( $msrwa_engine_dependency ) );
	if ( ! class_exists( $msrwa_engine_class, false ) ) {
		require_once dirname( __DIR__ ) . '/class-msrwa-' . $msrwa_engine_dependency . '.php';
	}
}
unset( $msrwa_engine_dependency, $msrwa_engine_class );

require_once __DIR__ . '/class-msrwa-engine-call.php';
require_once __DIR__ . '/class-msrwa-engine-config.php';
require_once __DIR__ . '/class-msrwa-engine-steps.php';
require_once __DIR__ . '/class-msrwa-engine-input.php';
require_once __DIR__ . '/class-msrwa-engine-score.php';
require_once __DIR__ . '/class-msrwa-result.php';
require_once __DIR__ . '/class-msrwa-engine.php';
