<?php
// What a call costs is read from what the provider says it billed. Each body
// below is the shape a live call returned on 2026-09-23; the numbers are the
// ones that were billed and, before this, not counted.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

function msrwa_usage_read( $provider, array $body ) {
	$plan = array( 'kind' => 'text', 'provider' => $provider, 'model' => 'm' );
	return MSRWA_Engine_Call::read( $plan, array( 'status' => 200, 'raw' => json_encode( $body ), 'seconds' => 1.0 ) );
}

// --- Gemini ------------------------------------------------------------------

// gemini-3.6-flash, asked a one-line question: five visible tokens after 2 717
// of thinking. Google bills the thinking as output.
$thinking = msrwa_usage_read( 'gemini', array(
	'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => '19:08' ) ) ), 'finishReason' => 'STOP' ) ),
	'usageMetadata' => array( 'promptTokenCount' => 51, 'candidatesTokenCount' => 5, 'totalTokenCount' => 2773, 'thoughtsTokenCount' => 2717 ),
) );
msrwa_test_assert( 2722 === $thinking['usage']['output_tokens'], 'Gemini thinking is billed as output, so it is counted as output.' );
msrwa_test_assert( 51 === $thinking['usage']['input_tokens'], 'The prompt is still the input.' );

// url_context reading Google's pricing page: the page is input the caller pays for.
$fetched = msrwa_usage_read( 'gemini', array(
	'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => '{}' ) ) ), 'finishReason' => 'STOP' ) ),
	'usageMetadata' => array( 'promptTokenCount' => 92, 'candidatesTokenCount' => 792, 'toolUsePromptTokenCount' => 8973, 'cachedContentTokenCount' => 8020 ),
) );
msrwa_test_assert( 9065 === $fetched['usage']['input_tokens'], 'What a tool fetched is billed as input, so it is counted as input.' );
msrwa_test_assert( 8020 === $fetched['usage']['cached_input_tokens'], 'The cached share is reported beside it.' );

$grounded = msrwa_usage_read( 'gemini', array(
	'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => '{}' ) ) ), 'groundingMetadata' => array( 'webSearchQueries' => array( 'a', 'b', 'c' ) ) ) ),
	'usageMetadata' => array( 'promptTokenCount' => 10, 'candidatesTokenCount' => 10 ),
) );
msrwa_test_assert( 3 === $grounded['usage']['web_searches'], 'Each Google search a grounded answer ran is counted.' );

$plain = msrwa_usage_read( 'gemini', array(
	'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'OK' ) ) ) ) ),
	'usageMetadata' => array( 'promptTokenCount' => 9, 'candidatesTokenCount' => 1 ),
) );
msrwa_test_assert( array( 'input_tokens' => 9, 'output_tokens' => 1 ) === $plain['usage'], 'A model that did not think is read as before.' );

// --- Claude ------------------------------------------------------------------

$claude = msrwa_usage_read( 'claude', array(
	'content' => array( array( 'type' => 'text', 'text' => '{}' ) ), 'stop_reason' => 'end_turn',
	'usage' => array( 'input_tokens' => 1200, 'cache_creation_input_tokens' => 300, 'cache_read_input_tokens' => 5000, 'output_tokens' => 900, 'server_tool_use' => array( 'web_search_requests' => 2 ) ),
) );
msrwa_test_assert( 6500 === $claude['usage']['input_tokens'], 'Anthropic reports cache writes and reads apart from input; all three are input.' );
msrwa_test_assert( 2 === $claude['usage']['web_searches'], 'Claude says how many searches it ran.' );

// --- OpenAI ------------------------------------------------------------------

$openai = msrwa_usage_read( 'openai', array(
	'output' => array( array( 'type' => 'web_search_call' ), array( 'type' => 'web_search_call' ), array( 'type' => 'message', 'content' => array( array( 'text' => '{}' ) ) ) ),
	'usage' => array( 'input_tokens' => 4000, 'output_tokens' => 800 ), 'status' => 'completed',
) );
msrwa_test_assert( 2 === $openai['usage']['web_searches'], 'Each web_search_call item is one billed search.' );
msrwa_test_assert( 4000 === $openai['usage']['input_tokens'], 'OpenAI already counts search content and reasoning in its totals.' );

// --- Pricing -----------------------------------------------------------------

$config = MSRWA_Engine_Config::create( array( 'models' => array( 'gemini' => array( 'gemini-3.6-flash' => array( 0.75, 3.75 ) ) ) ) );
$cost = $config->price( 'gemini', 'gemini-3.6-flash', $thinking['usage'] );
msrwa_test_assert( abs( $cost - ( 51 * 0.75 + 2722 * 3.75 ) / 1000000 ) < 1e-12, 'The thinking is priced at the output rate.' );

$searched = $config->price( 'gemini', 'gemini-3.6-flash', array( 'input_tokens' => 0, 'output_tokens' => 0, 'web_searches' => 3 ) );
msrwa_test_assert( abs( $searched - 0.042 ) < 1e-12, 'Each search adds the provider’s published per-search fee.' );

$free = MSRWA_Engine_Config::create( array( 'providers' => array( 'gemini' => array( 'web_search_usd' => 0 ) ), 'models' => array( 'gemini' => array( 'x' => array( 1, 1 ) ) ) ) );
msrwa_test_assert( 0.0 === (float) $free->price( 'gemini', 'x', array( 'web_searches' => 5 ) ), 'A caller on a plan with free searches can set the fee to zero.' );
msrwa_test_assert( null === $config->price( 'gemini', 'unpriced', array( 'web_searches' => 5 ) ), 'An unpriced model stays unknown, never the search fee alone.' );

// --- How hard a model may think ---------------------------------------------

// Gemini counts thinking inside its output ceiling. Left unbounded, a live
// canonical recipe thought its way through the whole ceiling, stopped on
// MAX_TOKENS with its JSON cut in half, and was billed in full.
$keys = array( 'settings' => array( 'keys' => array( 'gemini' => 'k', 'openai' => 'k', 'claude' => 'k' ) ) );
$config = MSRWA_Engine_Config::create( $keys );
$wire = $config->provider( 'gemini', 'gemini-3.5-flash', 'canonical_recipe' );
$plan = MSRWA_Engine_Call::plan_text( 'gemini', 'gemini-3.5-flash', 'x', 4500, true, false, $wire );
msrwa_test_assert( array( 'thinkingLevel' => 'low' ) === ( $plan['request']['payload']['generationConfig']['thinkingConfig'] ?? null ), 'Gemini thinks at the level its provider ships.' );
msrwa_test_assert( 4500 === $plan['request']['payload']['generationConfig']['maxOutputTokens'], 'The ceiling is unchanged.' );
$vision = MSRWA_Engine_Call::plan_vision( 'gemini', 'gemini-3.5-flash', array( 'mime' => 'image/jpeg', 'data' => 'AAAA' ), 'x', 900, $config->provider( 'gemini', 'gemini-3.5-flash', 'vision' ) );
msrwa_test_assert( isset( $vision['request']['payload']['generationConfig']['thinkingConfig'] ), 'A vision pass is bounded the same way.' );
$judge = MSRWA_Engine_Call::plan_judge( 'gemini', 'gemini-3.5-flash', 'x', array(), 900, $wire );
msrwa_test_assert( isset( $judge['request']['payload']['generationConfig']['thinkingConfig'] ), 'And the judge.' );

// OpenAI and Claude think at their own default unless a level is set.
$plan = MSRWA_Engine_Call::plan_text( 'openai', 'gpt-5.6-luna', 'x', 4500, true, false, $config->provider( 'openai', 'gpt-5.6-luna', 'article' ) );
msrwa_test_assert( ! isset( $plan['request']['payload']['reasoning'] ), 'OpenAI keeps its own default when nothing is set.' );

// One level per step, spelled the way each provider spells it.
$tuned = MSRWA_Engine_Config::create( $keys + array( 'thinking' => array( 'default' => 'medium', 'research' => 'high', 'review' => 'minimal' ) ) );
msrwa_test_assert( 'high' === $tuned->thinking( 'research', 'openai' ), 'A step’s own level wins.' );
msrwa_test_assert( 'medium' === $tuned->thinking( 'article', 'gemini' ), 'Otherwise the default, over the provider’s.' );
msrwa_test_assert( '' === MSRWA_Engine_Config::create( array( 'thinking' => array( 'article' => 'enormous' ) ) )->thinking( 'article', 'openai' ), 'A level the engine does not know is not sent.' );
$plan = MSRWA_Engine_Call::plan_text( 'openai', 'gpt-5.6-luna', 'x', 4500, true, false, $tuned->provider( 'openai', 'gpt-5.6-luna', 'research' ) );
msrwa_test_assert( array( 'effort' => 'high' ) === ( $plan['request']['payload']['reasoning'] ?? null ), 'OpenAI takes it as reasoning.effort.' );
$plan = MSRWA_Engine_Call::plan_text( 'claude', 'claude-sonnet-5', 'x', 4500, true, false, $tuned->provider( 'claude', 'claude-sonnet-5', 'review' ) );
msrwa_test_assert( array( 'effort' => 'low' ) === ( $plan['request']['payload']['output_config'] ?? null ), 'Claude takes it as effort, and has no minimal: low is the least.' );
$plan = MSRWA_Engine_Call::plan_text( 'claude', 'claude-haiku-4-5-20251001', 'x', 4500, true, false, $tuned->provider( 'claude', 'claude-haiku-4-5-20251001', 'review' ) );
msrwa_test_assert( ! isset( $plan['request']['payload']['output_config'] ), 'Haiku 4.5 refuses effort, so it is never sent one.' );
$plan = MSRWA_Engine_Call::plan_text( 'openai', 'gpt-4.1', 'x', 4500, true, false, $tuned->provider( 'openai', 'gpt-4.1', 'research' ) );
msrwa_test_assert( ! isset( $plan['request']['payload']['reasoning'] ), 'Nor is a model that does not reason.' );
$plan = MSRWA_Engine_Call::plan_text( 'gemini', 'gemini-3.5-flash', 'x', 4500, true, false, $tuned->provider( 'gemini', 'gemini-3.5-flash', 'review' ) );
msrwa_test_assert( array( 'thinkingLevel' => 'minimal' ) === $plan['request']['payload']['generationConfig']['thinkingConfig'], 'Gemini takes it as thinkingLevel.' );
foreach ( array( 'claude-opus-5-5', 'claude-opus-4-5-20251101', 'claude-sonnet-4-6', 'claude-fable-5-1' ) as $model ) { msrwa_test_assert( MSRWA_Engine_Call::thinks( 'claude', $model ), $model . ' takes effort.' ); }
foreach ( array( 'claude-sonnet-4-5-20250929', 'claude-haiku-4-5-20251001' ) as $model ) { msrwa_test_assert( ! MSRWA_Engine_Call::thinks( 'claude', $model ), $model . ' does not.' ); }

// What thinking costs, in the estimate: the shapes were measured at the
// providers' default, medium, so only a higher level adds output.
msrwa_test_assert( 3000 === MSRWA_Engine_Config::thinking_allowance( 'high' ), 'high adds tokens to every call.' );
msrwa_test_assert( 0 === MSRWA_Engine_Config::thinking_allowance( 'low' ), 'low subtracts nothing: an estimate must not read low.' );
msrwa_test_assert( 0 === MSRWA_Engine_Config::thinking_allowance( '' ), 'The provider’s default is what was measured.' );

$cut = msrwa_usage_read( 'gemini', array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => '{"title":' ) ) ), 'finishReason' => 'MAX_TOKENS' ) ), 'usageMetadata' => array( 'promptTokenCount' => 3759, 'candidatesTokenCount' => 1050, 'thoughtsTokenCount' => 3435 ) ) );
msrwa_test_assert( 'MAX_TOKENS' === $cut['status'], 'A cut answer says so, so the run can warn that it was billed in full.' );

msrwa_test_done( 'engine usage and pricing' );
