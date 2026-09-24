<?php
/**
 * The lab: one way in, for everything measured against a real provider.
 *
 *   php tools/lab.php run    --brief=souris-agneau-four [--report=out.html]
 *   php tools/lab.php step   research --brief=tarte-pommes
 *   php tools/lab.php step   featured_image --brief=tarte-pommes --canonical=<run>.json
 *   php tools/lab.php judge  --brief=… --draws=5 --article=<run>.json --featured=… --facebook=…
 *   php tools/lab.php report --run=tools/runs/<run>.json [--output=out.html]
 *   php tools/lab.php prune  [--dry-run]
 *
 * Common flags: --provider --tier --model --budget --only --attempts --quality --thinking --style-reference
 *               --searches --search-context
 * Saved artifacts: --research= --canonical= --article= --featured= --facebook=
 *
 * There is almost nothing here. The engine runs the recipe, scores it and
 * prices it; this file reads a fixture, turns flags into engine configuration,
 * prints what happens as it happens, and saves the result. When the plugin
 * calls the engine it will do those same three things differently, and the run
 * in between will be identical — which is the point of the arrangement.
 */
define( 'MSRWA_LAB', true );
require __DIR__ . '/lib/steps.php';

function lab_die( $message ) { fwrite( STDERR, rtrim( $message ) . "\n" ); exit( 2 ); }

/** --image-model=gpt-image-2 is OpenAI's; --image-model=gemini:gemini-3-pro-image names the provider. */
function lab_image_route( $model ) { return false === strpos( (string) $model, ':' ) ? 'openai:' . $model : (string) $model; }

/** Flags every command understands, translated into engine configuration. */
function lab_config( array $options, $step = '' ) {
	$config = array( 'settings' => lab_settings() );

	$route = '';
	if ( ! empty( $options['model'] ) ) { $route = ( $options['provider'] ?? 'openai' ) . ':' . $options['model']; }
	elseif ( ! empty( $options['provider'] ) || ! empty( $options['tier'] ) ) { $route = ( $options['provider'] ?? 'openai' ) . ':' . ( $options['tier'] ?? 'medium' ); }
	if ( '' !== $route ) {
		// One flag, every step: the lab compares a provider against another
		// provider, not one step against the rest of the pipeline.
		$routing = array( 'vision' => $route );
		foreach ( MSRWA_Engine_Steps::names() as $name ) { $routing[ $name ] = $route; }
		if ( ! empty( $options['image-model'] ) ) { $routing['image'] = lab_image_route( $options['image-model'] ); }
		$config['routing'] = $routing;
	} elseif ( ! empty( $options['image-model'] ) ) {
		$config['routing'] = array( 'image' => lab_image_route( $options['image-model'] ) );
	}

	if ( isset( $options['max-output'] ) && '' !== $step ) { $config['max_output'] = array( $step => (int) $options['max-output'] ); }
	if ( isset( $options['thinking'] ) ) { $config['thinking'] = array( 'default' => (string) $options['thinking'] ); }
	if ( isset( $options['searches'] ) ) { $config['limits']['web_searches'] = (int) $options['searches']; }
	if ( isset( $options['search-context'] ) ) { $config['providers']['openai']['web_search_tool'] = array( 'type' => 'web_search', 'search_context_size' => (string) $options['search-context'] ); }
	if ( isset( $options['budget'] ) ) { $config['limits']['budget_usd'] = (float) $options['budget']; }
	if ( isset( $options['attempts'] ) ) { $config['attempts'] = array( 'default' => (int) $options['attempts'], 'final_approval' => (int) $options['attempts'] ); }
	if ( isset( $options['quality'] ) ) { $config['images'] = array( 'featured_quality' => $options['quality'], 'facebook_quality' => $options['quality'] ); }
	// A collage whose look the composed Facebook image keeps: --style-reference=path.
	if ( ! empty( $options['style-reference'] ) ) { $config['images'] = array_merge( (array) ( $config['images'] ?? array() ), array( 'style_references' => array( realpath( (string) $options['style-reference'] ) ) ) ); }
	return $config;
}

/** Progress, printed the moment it happens rather than at the end. */
function lab_observer() {
	return static function ( $event ) {
		$marks = array( 'error' => '!!', 'retry' => '->', 'warning' => ' !', 'wave' => '==', 'observe' => '..' );
		printf( "%6.1fs %-2s %-16s %s\n", $event['at'], $marks[ $event['kind'] ] ?? '  ', $event['step'], $event['message'] );
	};
}

/** Where runs and generated images are written. */
function lab_workspace() {
	$path = __DIR__ . '/runs';
	if ( ! is_dir( $path ) && ! mkdir( $path, 0775, true ) ) { lab_die( "Could not create {$path}." ); }
	return $path;
}

function lab_save( MSRWA_Result $result, $name ) {
	$path = lab_workspace() . '/' . trim( preg_replace( '/[^a-z0-9]+/i', '-', $name ), '-' ) . '-' . gmdate( 'Ymd-His' ) . '.json';
	file_put_contents( $path, json_encode( $result->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	return $path;
}

/** The scorecards and the bill, once a run is over. */
function lab_summary( MSRWA_Result $result ) {
	$totals = $result->totals();
	echo "\n";
	foreach ( $result->steps as $step ) {
		$score = null === $step['passed'] ? '     ' : sprintf( '%2d/%-2d', $step['passed'], $step['total'] );
		printf( "%-18s %s %7ss %10s  %s\n", $step['step'], $score, $step['seconds'], sprintf( '$%.4f', $step['cost_usd'] ), '' !== $step['error'] ? 'FAILED: ' . $step['error'] : $step['model'] );
		foreach ( (array) $step['checks'] as $label => $check ) {
			if ( empty( $check['pass'] ) ) { printf( "                     x %-30s %s\n", $label, $check['detail'] ); }
		}
	}
	printf( "\n%-18s %ss\n", 'time', $totals['seconds'] );
	printf( "%-18s in %s / out %s\n", 'tokens', number_format( $totals['input_tokens'] ), number_format( $totals['output_tokens'] ) );
	printf( "%-18s %s\n", 'cost', sprintf( '$%.4f', $totals['cost_usd'] ) );
	foreach ( $totals['buckets'] as $bucket => $spent ) { printf( "  %-16s %s\n", $bucket, sprintf( '$%.4f', $spent ) ); }
	printf( "\n%s\n", $result->ok ? 'COMPLETE' : 'FINISHED WITH ERRORS' );
}

function lab_write_report( array $run, $output ) {
	require_once __DIR__ . '/report.php';
	$directory = dirname( $output );
	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0775, true ) ) { lab_die( "Could not create {$directory}." ); }
	file_put_contents( $output, report_render( $run ) );
	printf( "report %s (%s MB)\n", $output, number_format( filesize( $output ) / 1048576, 2 ) );
}

/** Saved runs named on the command line, as the artifacts the engine expects. */
function lab_artifacts( $step, $brief, array $options ) {
	$resolved = lab_working_brief( $step, $brief, $options );
	$artifacts = array();
	foreach ( array( 'research', 'canonical', 'article' ) as $key ) {
		if ( ! empty( $resolved[ $key ] ) ) { $artifacts[ $key ] = $resolved[ $key ]; }
	}
	foreach ( array( 'review', 'corrected', 'proofread' ) as $key ) {
		if ( ! empty( $options[ str_replace( '_', '-', $key ) ] ) ) { $artifacts[ $key ] = lab_json_file( $options[ str_replace( '_', '-', $key ) ], $key, $key ); }
	}
	foreach ( array( 'featured', 'facebook' ) as $kind ) {
		if ( empty( $options[ $kind ] ) ) { continue; }
		$path = (string) $options[ $kind ];
		if ( ! file_exists( $path ) ) { lab_die( "No such image: {$path}" ); }
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$types = array( 'webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg' );
		if ( ! isset( $types[ $extension ] ) ) { lab_die( "Unsupported image type: {$extension}" ); }
		$settings = lab_settings();
		$artifacts[ $kind ] = array(
			'kind' => $kind, 'path' => $path, 'bytes' => filesize( $path ), 'mime' => $types[ $extension ],
			'size' => MSRWA_Images::native_size( 'featured' === $kind ? $settings['featured_ratio'] : $settings['facebook_ratio'], 'featured' === $kind ? '1024x1024' : '1024x1536' ),
		);
	}
	return $artifacts;
}

$arguments = array_slice( $_SERVER['argv'], 1 );
$command = '';
$subject = '';
$options = array();
foreach ( $arguments as $argument ) {
	if ( preg_match( '/^--([a-z-]+)=(.*)$/', $argument, $match ) ) { $options[ $match[1] ] = $match[2]; continue; }
	if ( preg_match( '/^--([a-z-]+)$/', $argument, $match ) ) { $options[ $match[1] ] = '1'; continue; }
	if ( '' === $command ) { $command = $argument; continue; }
	if ( '' === $subject ) { $subject = $argument; }
}

lab_settings();

if ( 'run' === $command ) {
	$brief = lab_brief( $options['brief'] ?? 'tarte-pommes' );
	$only = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $options['only'] ?? '' ) ) ) ) );
	$result = MSRWA_Engine::run( $brief, array( 'config' => lab_config( $options ), 'only' => $only, 'workspace' => lab_workspace() ), lab_observer() );
	lab_summary( $result );
	$path = lab_save( $result, (string) ( $options['brief'] ?? 'run' ) );
	echo 'saved ' . $path . "\n";
	if ( ! empty( $options['report'] ) ) { lab_write_report( $result->to_array(), $options['report'] ); }
	exit( $result->ok ? 0 : 1 );
}

if ( 'step' === $command ) {
	if ( '' === $subject ) { lab_die( 'Which step? ' . implode( ', ', MSRWA_Engine_Steps::names() ) ); }
	if ( ! MSRWA_Engine_Steps::get( $subject ) ) { lab_die( 'No such step: ' . $subject ); }
	$brief = lab_brief( $options['brief'] ?? 'tarte-pommes' );
	$result = MSRWA_Engine::run_step(
		$subject,
		array_merge( $brief, array( 'artifacts' => lab_artifacts( $subject, $brief, $options ) ) ),
		array( 'config' => lab_config( $options, $subject ), 'workspace' => lab_workspace() ),
		lab_observer()
	);
	lab_summary( $result );
	echo 'saved ' . lab_save( $result, ( $options['brief'] ?? 'brief' ) . '-' . $subject ) . "\n";
	$scored = $result->steps ? $result->steps[0] : array( 'passed' => null, 'total' => null );
	exit( $result->ok && $scored['passed'] === $scored['total'] ? 0 : 1 );
}

/**
 * The same approval, several times over the same artifacts.
 *
 * A judge that approves four times out of five and refuses once is not judging,
 * it is guessing, and the question comes back every time a prompt changes. This
 * is how it is answered with a number rather than an impression.
 */
if ( 'judge' === $command ) {
	$brief = lab_brief( $options['brief'] ?? 'tarte-pommes' );
	$artifacts = lab_artifacts( 'final_approval', $brief, $options );
	$draws = max( 1, min( 10, (int) ( $options['draws'] ?? 3 ) ) );
	// One attempt per draw: a retry would change the images between draws, which
	// is exactly what makes the answer unreadable.
	$options['attempts'] = 1;
	$verdicts = array();
	$spent = 0.0;
	for ( $draw = 1; $draw <= $draws; $draw++ ) {
		printf( "\n--- draw %d of %d ---\n", $draw, $draws );
		$result = MSRWA_Engine::run_step( 'final_approval', array_merge( $brief, array( 'artifacts' => $artifacts ) ), array( 'config' => lab_config( $options, 'final_approval' ), 'workspace' => lab_workspace() ), lab_observer() );
		$step = $result->steps ? $result->steps[0] : array();
		$verdict = (array) ( $result->artifacts['approval'] ?? array() );
		$spent += (float) ( $step['cost_usd'] ?? 0 );
		$verdicts[] = ! empty( $verdict['approved'] );
		printf( "  %-10s %d/%d contracts  %s\n", ! empty( $verdict['approved'] ) ? 'APPROVED' : 'REFUSED', (int) ( $step['passed'] ?? 0 ), (int) ( $step['total'] ?? 0 ), sprintf( '$%.4f', (float) ( $step['cost_usd'] ?? 0 ) ) );
		foreach ( (array) ( $verdict['findings'] ?? array() ) as $finding ) {
			printf( "     [%s] %s — %s\n", strtoupper( (string) ( $finding['severity'] ?? '?' ) ), (string) ( $finding['target'] ?? '?' ), (string) ( $finding['reason'] ?? '' ) );
		}
	}
	$approved = count( array_filter( $verdicts ) );
	printf( "\n%d approved, %d refused out of %d draws, for %s\n", $approved, $draws - $approved, $draws, sprintf( '$%.4f', $spent ) );
	printf( "%s\n", 0 === $approved || $draws === $approved ? 'STABLE: the judge agrees with itself.' : 'UNSTABLE: the same artifacts got both answers.' );
	exit( 0 === $approved || $draws === $approved ? 0 : 1 );
}

if ( 'report' === $command ) {
	$file = (string) ( $options['run'] ?? '' );
	if ( '' === $file || ! file_exists( $file ) ) { lab_die( 'Pass --run=<a saved run>.json' ); }
	$run = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $run ) || ! isset( $run['artifacts'] ) ) { lab_die( "That is not an engine run: {$file}" ); }
	lab_write_report( $run, (string) ( $options['output'] ?? preg_replace( '/\.json$/', '.html', $file ) ) );
	exit( 0 );
}

/**
 * The owner's retention policy over tools/runs.
 *
 * Keep the last article and the last canonical recipe per brief, the last
 * featured image, and every Facebook collage — the collage is the piece still
 * being tuned, so its whole series is evidence. Every cost ever measured is
 * appended to tools/cost-history.jsonl first: the runs are regenerable, the
 * measurements are not.
 */
if ( 'prune' === $command ) {
	$dry = ! empty( $options['dry-run'] );
	$runs = __DIR__ . '/runs';
	$ledger = __DIR__ . '/cost-history.jsonl';

	$seen = array();
	if ( file_exists( $ledger ) ) {
		foreach ( file( $ledger, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$row = json_decode( $line, true );
			if ( isset( $row['run'] ) ) { $seen[ $row['run'] ] = true; }
		}
	}

	$appended = 0;
	foreach ( glob( $runs . '/*.json' ) as $path ) {
		$name = basename( $path );
		if ( isset( $seen[ $name ] ) ) { continue; }
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) { continue; }
		// An engine run holds every step; an older single-step run holds one.
		$steps = isset( $data['steps'] ) && is_array( $data['steps'] ) ? $data['steps'] : array( $data );
		foreach ( $steps as $step ) {
			$row = array(
				'run' => $name, 'step' => $step['step'] ?? '', 'provider' => $step['provider'] ?? 'openai',
				'model' => $step['model'] ?? '', 'tier' => $step['tier'] ?? '', 'quality' => $step['quality'] ?? '',
				'seconds' => $step['seconds'] ?? null, 'usage' => $step['usage'] ?? array(),
				'cost_usd' => $step['cost_usd'] ?? null, 'status' => $step['status'] ?? '',
				'passed' => $step['passed'] ?? ( $step['scores']['passed'] ?? null ),
				'total' => $step['total'] ?? ( $step['scores']['total'] ?? null ),
				'recorded_at' => gmdate( 'c' ),
			);
			if ( ! $dry ) { file_put_contents( $ledger, json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND ); }
			$appended++;
		}
	}

	$groups = array();
	foreach ( glob( $runs . '/*' ) as $path ) {
		$name = basename( $path );
		foreach ( array( 'article', 'canonical_recipe', 'featured' ) as $step ) {
			if ( false !== strpos( $name, '-' . $step . '-' ) ) { $groups[ substr( $name, 0, strpos( $name, '-' . $step . '-' ) ) . '|' . $step ][] = $path; }
		}
	}

	$removed = 0;
	foreach ( $groups as $paths ) {
		// Group a .webp with its .json so an image and its measurements go together.
		$stems = array();
		foreach ( $paths as $path ) { $stems[ preg_replace( '/\.[a-z0-9]+$/i', '', $path ) ][] = $path; }
		if ( count( $stems ) <= 1 ) { continue; }
		// Newest first by modification time. Sorting by name kept "-retry-" ahead of
		// a later plain generation, because "r" sorts after a digit.
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
	exit( 0 );
}

fwrite( STDERR, "php tools/lab.php <run|step|judge|report|prune> [subject] [--flags]\n" );
exit( 2 );
