<?php
/**
 * Applies the owner's retention policy to tools/runs, 2026-09-21.
 *
 * Keep the last article and the last canonical recipe per brief, the last
 * featured image, and every Facebook collage — the collage is the piece still
 * being tuned, so its whole series is evidence. Every cost ever measured is
 * appended to tools/cost-history.jsonl first, so pruning a run never loses what
 * it cost: the runs are regenerable, the measurements are not.
 *
 *   php tools/prune-runs.php [--dry-run]
 */
$dry = in_array( '--dry-run', array_slice( $_SERVER['argv'], 1 ), true );
$runs = __DIR__ . '/runs';
$ledger = __DIR__ . '/cost-history.jsonl';

/** Costs already recorded, so a second run does not duplicate them. */
$seen = array();
if ( file_exists( $ledger ) ) {
	foreach ( file( $ledger, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$row = json_decode( $line, true );
		if ( isset( $row['run'] ) ) { $seen[ $row['run'] ] = true; }
	}
}

$appended = 0;
$entries = array();
foreach ( glob( $runs . '/*.json' ) as $path ) {
	$name = basename( $path );
	$data = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $data ) ) { continue; }
	if ( ! isset( $seen[ $name ] ) ) {
		$row = array(
			'run' => $name,
			'step' => $data['step'] ?? '',
			'provider' => $data['provider'] ?? 'openai',
			'model' => $data['model'] ?? '',
			'tier' => $data['tier'] ?? '',
			'quality' => $data['quality'] ?? '',
			'seconds' => $data['seconds'] ?? null,
			'usage' => $data['usage'] ?? array(),
			'cost_usd' => $data['cost_usd'] ?? null,
			'total_cost_usd' => $data['total_cost_usd'] ?? null,
			'status' => $data['status'] ?? '',
			'passed' => $data['scores']['passed'] ?? null,
			'total' => $data['scores']['total'] ?? null,
			'recorded_at' => gmdate( 'c' ),
		);
		if ( ! $dry ) { file_put_contents( $ledger, json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND ); }
		$appended++;
	}
	$entries[] = $name;
}

/** brief-step-... : the brief is everything before the step name. */
$keep_last = array( 'article', 'canonical_recipe', 'featured' );
$groups = array();
foreach ( glob( $runs . '/*' ) as $path ) {
	$name = basename( $path );
	foreach ( $keep_last as $step ) {
		if ( false !== strpos( $name, '-' . $step . '-' ) ) {
			$brief = substr( $name, 0, strpos( $name, '-' . $step . '-' ) );
			$groups[ $brief . '|' . $step ][] = $path;
		}
	}
}

$removed = 0;
foreach ( $groups as $key => $paths ) {
	// Group a .webp with its .json so an image and its measurements go together.
	$stems = array();
	foreach ( $paths as $path ) { $stems[ preg_replace( '/\.[a-z0-9]+$/i', '', $path ) ][] = $path; }
	if ( count( $stems ) <= 1 ) { continue; }
	// Newest first by modification time. Sorting by name kept "-retry-" ahead of a
	// later plain generation, because "r" sorts after a digit.
	uksort( $stems, static function ( $a, $b ) use ( $stems ) {
		$mtime = static function ( $stem ) use ( $stems ) {
			$latest = 0;
			foreach ( $stems[ $stem ] as $file ) { $latest = max( $latest, (int) filemtime( $file ) ); }
			return $latest;
		};
		return $mtime( $b ) <=> $mtime( $a );
	} );
	array_shift( $stems );
	foreach ( $stems as $files ) {
		foreach ( $files as $file ) {
			echo ( $dry ? 'would remove ' : 'removed ' ) . basename( $file ) . "\n";
			if ( ! $dry ) { unlink( $file ); }
			$removed++;
		}
	}
}

printf( "\n%d cost rows %s, %d files %s, %d runs kept.\n", $appended, $dry ? 'would be recorded' : 'recorded', $removed, $dry ? 'would be removed' : 'removed', count( glob( $runs . '/*' ) ) );
