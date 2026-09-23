<?php
// A price looked up by a model is an indication, never an invoice. What is
// held here is the refusing: a model asked for a price it cannot find will
// sometimes answer with a confident number anyway, and a wrong rate that
// looks authoritative is worse than an empty cell — an empty cell stops the
// estimate, a wrong one quietly bills against it.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MSRWA_VERSION', '0.0.0-test' );
define( 'MSRWA_DIR', dirname( __DIR__ ) . '/' );
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'catalog', 'json', 'prices' );
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

/** What remember_price() was asked to store, without a database. */
$GLOBALS['msrwa_stored_prices'] = array();

$wanted = array(
	'claude:claude-sonnet-5' => array( 'provider' => 'claude', 'model_id' => 'claude-sonnet-5' ),
	'openai:gpt-5.6-luna' => array( 'provider' => 'openai', 'model_id' => 'gpt-5.6-luna' ),
);

// --- A well-formed answer -------------------------------------------------

$good = array( 'prices' => array(
	array( 'key' => 'claude:claude-sonnet-5', 'input' => 3, 'output' => 15, 'source' => 'https://platform.claude.com/docs/en/about-claude/pricing' ),
) );
$out = MSRWA_Prices::apply( $wanted, $good );
msrwa_test_assert( 2 === $out['asked'], 'It reports how many it asked about.' );
msrwa_test_assert( 1 === $out['found'], 'And how many came back usable.' );
msrwa_test_assert( 'found' === $out['results']['claude:claude-sonnet-5']['state'], 'A rate with a page behind it is kept.' );
// A model omitted from the answer is the correct answer when the page does
// not carry a price. It must read as missing, never as zero.
msrwa_test_assert( 'missing' === $out['results']['openai:gpt-5.6-luna']['state'], 'A model the answer left out is missing, not free.' );

// --- Answers that must be refused -----------------------------------------

$refused = array(
	'no source at all' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 3, 'output' => 15 ),
	'an empty source' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 3, 'output' => 15, 'source' => '' ),
	'a price of zero' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 0, 'output' => 15, 'source' => 'https://example.test/p' ),
	'a negative price' => array( 'key' => 'claude:claude-sonnet-5', 'input' => -3, 'output' => 15, 'source' => 'https://example.test/p' ),
	'an absurd price' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 5000, 'output' => 15, 'source' => 'https://example.test/p' ),
	'words instead of a number' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 'about three dollars', 'output' => 15, 'source' => 'https://example.test/p' ),
	'a null price' => array( 'key' => 'claude:claude-sonnet-5', 'input' => null, 'output' => 15, 'source' => 'https://example.test/p' ),
	'only half a rate' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 3, 'source' => 'https://example.test/p' ),
	'a page that is not the provider’s' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 3, 'output' => 15, 'source' => 'https://llm-prices.example.com/claude' ),
	'another provider’s page' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 3, 'output' => 15, 'source' => 'https://ai.google.dev/gemini-api/docs/pricing' ),
	'a look-alike host' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 3, 'output' => 15, 'source' => 'https://notanthropic.com/pricing' ),
	'plain http' => array( 'key' => 'claude:claude-sonnet-5', 'input' => 3, 'output' => 15, 'source' => 'http://platform.claude.com/docs/en/about-claude/pricing' ),
);
foreach ( $refused as $why => $entry ) {
	$out = MSRWA_Prices::apply( $wanted, array( 'prices' => array( $entry ) ) );
	msrwa_test_assert( 0 === $out['found'], 'Refused: ' . $why . '.' );
	msrwa_test_assert( 'found' !== $out['results']['claude:claude-sonnet-5']['state'], 'And says so rather than storing it: ' . $why . '.' );
}

// A rate for something nobody asked about is not quietly written somewhere.
$stray = MSRWA_Prices::apply( $wanted, array( 'prices' => array(
	array( 'key' => 'claude:a-model-nobody-asked-about', 'input' => 1, 'output' => 2, 'source' => 'https://example.test/p' ),
) ) );
msrwa_test_assert( 0 === $stray['found'], 'A rate for a model that was not asked about is ignored.' );
msrwa_test_assert( 2 === count( $stray['results'] ), 'Only what was asked about is reported on.' );

// --- Answers that are not answers -----------------------------------------

foreach ( array( null, '', 'not json', array(), array( 'prices' => 'nonsense' ), array( 'prices' => array( 'flat', 3 ) ) ) as $rubbish ) {
	$out = MSRWA_Prices::apply( $wanted, $rubbish );
	msrwa_test_assert( 0 === $out['found'], 'Nothing usable yields nothing stored.' );
	msrwa_test_assert( 2 === count( $out['results'] ), 'And every model asked about is still accounted for.' );
}

// --- The prompt -----------------------------------------------------------

$prompt = MSRWA_Prices::prompt( $wanted );
foreach ( array_keys( $wanted ) as $key ) {
	msrwa_test_contains( $prompt, $key, 'The prompt names ' . $key . '.' );
}
// The three things that decide whether the answer is usable at all.
msrwa_test_contains( $prompt, 'MILLION', 'It fixes the unit, because providers quote per thousand and per million.' );
msrwa_test_contains( $prompt, 'source', 'It demands the page the number was read from.' );
msrwa_test_contains( $prompt, 'A missing entry is correct', 'And makes omission the right answer, so a gap is not filled with a guess.' );
msrwa_test_contains( $prompt, 'provider\'s own', 'Only the provider\'s own page counts as a source.' );

// One provider per question, pointed at its page: Gemini reads it with
// url_context, which worked on a key whose search grounding was out of quota.
$claude_only = array( 'claude:claude-sonnet-5' => $wanted['claude:claude-sonnet-5'] );
msrwa_test_contains( MSRWA_Prices::prompt( $claude_only, 'claude' ), 'https://platform.claude.com/docs/en/about-claude/pricing', 'The question names the provider’s own page.' );
// OpenAI's pricing page hides every model but its flagships behind a script;
// the lookup read "no rate found" for Luna there. Each model's page has it.
msrwa_test_contains( MSRWA_Prices::prompt( array( 'openai:gpt-5.6-luna' => $wanted['openai:gpt-5.6-luna'] ), 'openai' ), 'https://developers.openai.com/api/docs/models/gpt-5.6-luna', 'An OpenAI model is asked about on its own page.' );
msrwa_test_contains( $prompt, 'thinking', 'It says reasoning is billed as output, which is where Gemini’s real rate lives.' );
$images = array( 'openai:gpt-image-2.5-flare' => array( 'provider' => 'openai', 'model_id' => 'gpt-image-2.5-flare' ) );
msrwa_test_contains( MSRWA_Prices::prompt( $images, 'openai' ), 'IMAGE output', 'An image model is asked for its image output rate, not its text one.' );
msrwa_test_assert( false === strpos( $prompt, 'IMAGE output' ), 'A text model is not.' );
foreach ( MSRWA_Prices::pages() as $provider => $page ) {
	msrwa_test_assert( MSRWA_Prices::own_page( $provider, $page['url'] ), 'The page the lookup is sent to is one it will accept: ' . $provider . '.' );
}
msrwa_test_assert( MSRWA_Prices::own_page( 'openai', 'https://platform.openai.com/docs/pricing' ), 'A subdomain of the provider is the provider.' );

$gemini = MSRWA_Prices::wire( array( 'provider' => 'gemini', 'wire' => array( 'web_search_tool' => array( 'google_search' => array() ) ) ) );
msrwa_test_assert( array( 'url_context' => array() ) === $gemini['web_search_tool'], 'Gemini opens the page instead of searching for it.' );
$claude = MSRWA_Prices::wire( array( 'provider' => 'claude', 'wire' => array( 'web_search_tool' => array( 'type' => 'web_search_20250305' ) ) ) );
msrwa_test_assert( 'web_search_20250305' === $claude['web_search_tool']['type'], 'The others keep their search tool.' );

// --- Read straight off the page -------------------------------------------

// OpenAI's page for each model, trimmed from the real one of 2026-09-23: the
// labels run into their neighbours, and a comparison with other models
// follows. The first input and output rate are the model's own.
$page = '<div>Text tokens<span>Per 1M tokens</span><div>Input</div><div>$0.20</div><div>Cached input</div><div>$0.02</div><div>Output</div><div>$1.20</div>'
	. '<div>Quick comparison</div><div>Input</div><div>Cached input</div><div>Output</div><div>GPT-5.6 Terra</div><div>$2.00</div></div><script>var x = "Input$9.99 Output$99";</script>';
msrwa_test_assert( array( 'input' => 0.2, 'output' => 1.2 ) === MSRWA_Prices::parse_page( $page ), 'The model’s own rate is read, not the comparison beside it.' );
msrwa_test_assert( null === MSRWA_Prices::parse_page( '<p>GPT-Image-2 Model</p>' ), 'A page that states no rate yields none.' );
msrwa_test_assert( null === MSRWA_Prices::parse_page( '<p>Input $0 Output $0</p>' ), 'A rate of zero is not a rate.' );
msrwa_test_assert( null === MSRWA_Prices::read_page( 'gemini', 'gemini-3.5-flash' ), 'Only a provider with a page per model is read this way.' );
msrwa_test_assert( 'page' === MSRWA_Catalog::READ && ! in_array( MSRWA_Catalog::READ, array( MSRWA_Catalog::SHIPPED, MSRWA_Catalog::MANUAL, MSRWA_Catalog::LOOKED_UP ), true ), 'A rate read off the page is its own provenance.' );

// --- Which routes are tried --------------------------------------------------

putenv( 'OPENAI_API_KEY=' );
putenv( 'ANTHROPIC_API_KEY=' );
putenv( 'GEMINI_API_KEY=' );
$config = MSRWA_Engine_Config::create( array(
	'routing' => array( 'research' => 'claude:medium' ),
	'settings' => array( 'keys' => array( 'claude' => 'k1', 'gemini' => 'k2' ) ),
) );
$rows = array(
	array( 'provider' => 'gemini', 'model_id' => 'gemini-3.5-flash-lite', 'input_usd' => 0.30, 'served' => true ),
	array( 'provider' => 'gemini', 'model_id' => 'gemini-3.8-flash', 'input_usd' => 0.75, 'served' => null ),
	array( 'provider' => 'gemini', 'model_id' => 'gemini-2.0-gone', 'input_usd' => 0.10, 'served' => false ),
	array( 'provider' => 'gemini', 'model_id' => 'lyria-3.5', 'input_usd' => 0.01, 'served' => true ),
	array( 'provider' => 'openai', 'model_id' => 'gpt-5-nano', 'input_usd' => 0.05, 'served' => true ),
);
$routes = array_map( static function ( $route ) { return $route['provider'] . ':' . $route['model']; }, MSRWA_Prices::routes( $config, $rows ) );
msrwa_test_assert( 'claude:claude-sonnet-5' === $routes[0], 'The research route is tried first; got ' . implode( ', ', $routes ) );
msrwa_test_assert( in_array( 'gemini:gemini-3.1-flash-lite', $routes, true ), 'Then another provider the site has a key for, so one refusal does not end the lookup.' );
msrwa_test_assert( ! preg_grep( '/^openai:/', $routes ), 'A provider with no key is never tried.' );
msrwa_test_assert( count( $routes ) === count( array_unique( $routes ) ), 'No route is tried twice.' );
msrwa_test_assert( 'gemini:gemini-3.5-flash-lite' === $routes[1], 'After research, the cheapest model the site can write with, enabled or not; got ' . implode( ', ', $routes ) );
msrwa_test_assert( ! in_array( 'gemini:gemini-2.0-gone', $routes, true ), 'Never a model the provider no longer serves.' );
msrwa_test_assert( ! in_array( 'gemini:lyria-3.5', $routes, true ), 'Nor one that cannot write.' );
msrwa_test_assert( count( $routes ) <= MSRWA_Prices::ATTEMPTS, 'A lookup gives up after a bounded number of refusals.' );

msrwa_test_done( 'looked-up prices' );
