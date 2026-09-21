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
require_once __DIR__ . '/class-msrwa-engine-rates.php';
require_once __DIR__ . '/class-msrwa-engine-call.php';
require_once __DIR__ . '/class-msrwa-engine-steps.php';
require_once __DIR__ . '/class-msrwa-result.php';
