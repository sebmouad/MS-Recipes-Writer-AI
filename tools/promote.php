<?php
/**
 * Copies a shipped prompt template into the settings defaults.
 *
 *   php tools/promote.php              every template
 *   php tools/promote.php final_approval
 *
 * The engine reads its prompts from `includes/engine/prompts/*.tpl.txt`; the
 * plugin ships the same text as a default so an administrator can edit it.
 * Two copies of the same words drift, which is why a test compares them — and
 * why this exists rather than a copy-paste.
 */

const MSRWA_PROMOTE = array(
	'research' => 'prompt_research',
	'canonical_recipe' => 'prompt_recipe',
	'article' => 'prompt_article',
	'review' => 'prompt_review',
	'proofread' => 'prompt_correction',
	'featured_image' => 'prompt_image',
	'facebook_image' => 'prompt_facebook_image',
	'final_approval' => 'prompt_final_approval',
);

if ( 'cli' !== PHP_SAPI ) { return; }

$root = dirname( __DIR__ );
require_once $root . '/tests/bootstrap.php';
require_once $root . '/tools/lib/steps.php';

$only = $argv[1] ?? '';
$settings = lab_settings();
$file = $root . '/includes/class-msrwa-settings.php';
$source = file_get_contents( $file );
$changed = array();

foreach ( MSRWA_PROMOTE as $template => $key ) {
	if ( '' !== $only && $only !== $template ) { continue; }
	$path = $root . '/includes/engine/prompts/' . $template . '.tpl.txt';
	if ( ! is_readable( $path ) ) { fwrite( STDERR, "missing template: {$template}\n" ); continue; }

	$compiled = MSRWA_Prompt::compile( file_get_contents( $path ), $settings );
	// The default is a single-quoted PHP string, so only a quote and a
	// backslash need escaping — exactly what the test undoes when comparing.
	$escaped = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $compiled );

	$pattern = "/('" . preg_quote( $key, '/' ) . "'\s*=>\s*')(.*?)(',\n)/s";
	if ( 1 !== preg_match( $pattern, $source ) ) { fwrite( STDERR, "no default found for {$key}\n" ); continue; }

	$updated = preg_replace_callback( $pattern, static function ( $match ) use ( $escaped ) {
		return $match[1] . $escaped . $match[3];
	}, $source, 1 );

	if ( $updated !== $source ) { $changed[] = $key; }
	$source = $updated;
}

if ( ! $changed ) { echo "nothing to promote\n"; return; }
file_put_contents( $file, $source );
echo implode( "\n", $changed ) . "\n" . count( $changed ) . " default(s) updated\n";
