<?php
// The recipe, the article and the review read the same research package. With
// it after each step's own instructions no two calls began alike, and nothing
// a provider had cached was ever reused. They now open on the same bytes.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'images', 'prompt', 'json' );
require_once dirname( __DIR__ ) . '/tools/lib/steps.php';

$brief = lab_brief( 'tarte-pommes' );
$brief['article'] = array( 'title' => 'Tarte', 'content_html' => '<h2>Tarte</h2><p>Texte.</p>' );
$inputs = array();
foreach ( array( 'canonical_recipe', 'article', 'review' ) as $step ) { $inputs[ $step ] = MSRWA_Engine_Input::build( $step, 'INSTRUCTIONS FOR ' . $step, $brief ); }

$research = MSRWA_Engine_Input::shared_context( $brief );
$recipe = MSRWA_Engine_Input::shared_context( $brief, true );
msrwa_test_assert( strlen( $research ) > 2000, 'The research package is worth caching; ' . strlen( $research ) . ' characters.' );
foreach ( $inputs as $step => $input ) { msrwa_test_assert( 0 === strpos( $input, rtrim( $research ) ), $step . ' opens on the research package.' ); }
msrwa_test_assert( 0 === strpos( $inputs['article'], $recipe ) && 0 === strpos( $inputs['review'], $recipe ), 'The article and the review then share the recipe too.' );
msrwa_test_assert( false !== strpos( $inputs['review'], 'INSTRUCTIONS FOR review' ) && strpos( $inputs['review'], 'INSTRUCTIONS' ) > strlen( $recipe ) - 3, 'Each step’s own instructions come after the shared part.' );

// OpenAI caches from the start of the request, where each step's own
// instructions sit: one key per step, the same for every recipe, so the next
// recipe finds them. Keyed by the recipe's research, nothing was ever reused.
$keys = array( 'settings' => array( 'keys' => array( 'openai' => 'k', 'claude' => 'k' ) ) );
$sent = array();
MSRWA_Engine_Call::$transport = static function ( $url, $payload ) use ( &$sent ) { $sent[] = $payload; return array( 'status' => 500, 'raw' => '{}' ); };
$artifacts = array( 'research' => $brief['research'], 'canonical' => $brief['canonical'], 'article' => $brief['article'] );
foreach ( array( 'canonical_recipe', 'article', 'review' ) as $step ) {
	MSRWA_Engine::run_step( $step, array( 'title' => 'Tarte', 'artifacts' => $artifacts ), array( 'config' => $keys ) );
}
$cache_keys = array_map( static function ( $payload ) { return (string) ( $payload['prompt_cache_key'] ?? '' ); }, $sent );
msrwa_test_assert( array( 'msrwa-canonical_recipe', 'msrwa-article', 'msrwa-review' ) === $cache_keys, 'Each step carries its own key: ' . implode( ', ', $cache_keys ) . '.' );
$first = $sent;
MSRWA_Engine::run_step( 'article', array( 'title' => 'Crêpes', 'artifacts' => array( 'research' => array( 'dish_identity' => array( 'name' => 'Crêpes' ) ) ) + $artifacts ), array( 'config' => $keys ) );
msrwa_test_assert( 'msrwa-article' === (string) ( end( $sent )['prompt_cache_key'] ?? '' ), 'The same for another recipe, whose research differs.' );
$sent = $first;
// OpenAI serves from its cache only what is sent as the instructions: each
// step's own prompt, the same on every recipe, goes there.
foreach ( array( 0 => 'canonical_recipe.tpl.txt', 1 => 'article.tpl.txt', 2 => 'review.tpl.txt' ) as $call => $file ) {
	$fixed = MSRWA_Prompt::compile( trim( (string) file_get_contents( MSRWA_Engine_Input::prompt_path( $file ) ) ), MSRWA_Engine_Input::settings() );
	msrwa_test_assert( substr( $fixed, 0, 200 ) === substr( (string) ( $sent[ $call ]['instructions'] ?? '' ), 0, 200 ), $file . ' is sent as the instructions.' );
	msrwa_test_assert( false === strpos( (string) ( $sent[ $call ]['input'] ?? '' ), substr( $fixed, 0, 200 ) ), 'And not again in the input.' );
	msrwa_test_assert( 0 === strpos( (string) ( $sent[ $call ]['input'] ?? '' ), 'RESEARCH PACKAGE:' ), 'The input opens on the research the three share.' );
	// OpenAI refuses JSON mode when the input itself never says "json".
	msrwa_test_assert( false !== stripos( (string) ( $sent[ $call ]['input'] ?? '' ), 'json' ), 'The input still asks for JSON, or JSON mode is refused.' );
}

// Claude caches only what is marked: a breakpoint after the research, and after the recipe.
$sent = array();
MSRWA_Engine::run_step( 'review', array( 'title' => 'Tarte', 'artifacts' => $artifacts ), array( 'config' => $keys + array( 'routing' => array( 'review' => 'claude:medium' ) ) ) );
$content = $sent[0]['messages'][0]['content'] ?? '';
msrwa_test_assert( is_array( $content ) && 3 === count( $content ), 'Claude is sent the prompt in three blocks.' );
msrwa_test_assert( isset( $content[0]['cache_control'], $content[1]['cache_control'] ) && ! isset( $content[2]['cache_control'] ), 'The two shared parts are marked for the cache, the rest is not.' );
$joined = implode( '', array_column( (array) $content, 'text' ) );
msrwa_test_assert( 0 === strpos( $joined, rtrim( $recipe ) ) && false !== strpos( $joined, 'ARTICLE:' ) && str_ends_with( $joined, 'no commentary.' ), 'Nothing is lost in the split.' );
MSRWA_Engine_Call::$transport = null;

// Priced as billed: a read at a tenth, a Claude write at 1.25 times the input rate.
$config = MSRWA_Engine_Config::create();
$plain = $config->price( 'claude', 'claude-sonnet-5', array( 'input_tokens' => 10000, 'output_tokens' => 0 ) );
$read = $config->price( 'claude', 'claude-sonnet-5', array( 'input_tokens' => 10000, 'output_tokens' => 0, 'cached_input_tokens' => 8000 ) );
$write = $config->price( 'claude', 'claude-sonnet-5', array( 'input_tokens' => 10000, 'output_tokens' => 0, 'cache_write_tokens' => 8000 ) );
msrwa_test_assert( abs( $read - ( 2000 + 800 ) * 2.0 / 1e6 ) < 1e-9, 'A cache read is billed at a tenth; got ' . $read );
msrwa_test_assert( abs( $write - ( 10000 + 2000 ) * 2.0 / 1e6 ) < 1e-9, 'A cache write at 1.25 times; got ' . $write );
msrwa_test_assert( $plain < $write, 'So a write costs a little more than no cache at all.' );
$openai = $config->price( 'openai', 'gpt-5.6-luna', array( 'input_tokens' => 10000, 'output_tokens' => 0, 'cached_input_tokens' => 8000 ) );
msrwa_test_assert( abs( $openai - ( 2000 + 800 ) * 0.2 / 1e6 ) < 1e-9, 'OpenAI bills its cached input at a tenth; got ' . $openai );

msrwa_test_done( 'the steps that share the research open on it, and the cache is priced' );
