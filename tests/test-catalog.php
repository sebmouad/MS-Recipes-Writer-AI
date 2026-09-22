<?php
// Three kinds of knowledge that must not be confused: which models exist (only
// the provider knows), what they cost (no provider API says), and which step
// each may serve (the owner's editorial judgement). Mixing them is how a route
// ends up naming a model that cannot run.
//
// The table round-trip belongs to tests/real/; what is held here is the part
// that decides what the engine is handed, which is pure given rows.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'catalog' );

/** A catalogue row as the table hands it over. */
function msrwa_row( $provider, $id, $in, $out, $served = null, $steps = array(), $capabilities = array( 'text' => true ) ) {
	return array(
		'provider' => $provider, 'model_id' => $id, 'label' => $id,
		'input_usd' => $in, 'output_usd' => $out,
		'served' => $served, 'steps' => $steps, 'capabilities' => $capabilities, 'limits' => array(),
	);
}

// --- What the engine is handed -------------------------------------------

$rows = array(
	msrwa_row( 'claude', 'claude-haiku-4-5-20251001', 1.0, 5.0, true ),
	msrwa_row( 'claude', 'claude-sonnet-5', 2.0, 10.0, true ),
	msrwa_row( 'claude', 'claude-opus-5', 5.0, 25.0, true ),
	msrwa_row( 'gemini', 'gemini-3.5-flash', 1.5, 9.0, true ),
);
$handed = MSRWA_Catalog::for_engine( $rows );

msrwa_test_assert( array( 'models', 'tiers' ) === array_keys( $handed ), 'The caller layer carries exactly the two groups the engine reads.' );
msrwa_test_assert( array( 1.0, 5.0 ) === $handed['models']['claude']['claude-haiku-4-5-20251001'], 'A rate reaches the engine as [input, output].' );

// Cheapest, dearest, middle — by input rate. This is what stops a tier ever
// naming a model nobody priced or the provider retired, which is exactly how
// `claude:low` came to point at an identifier Anthropic no longer serves.
msrwa_test_assert( 'claude-haiku-4-5-20251001' === $handed['tiers']['low']['claude'], 'low is the cheapest the site actually has.' );
msrwa_test_assert( 'claude-opus-5' === $handed['tiers']['high']['claude'], 'high is the dearest.' );
msrwa_test_assert( 'claude-sonnet-5' === $handed['tiers']['medium']['claude'], 'medium sits between them.' );
msrwa_test_assert( 'gemini-3.5-flash' === $handed['tiers']['low']['gemini'], 'A provider with one model uses it for every tier.' );
msrwa_test_assert( 'gemini-3.5-flash' === $handed['tiers']['high']['gemini'], 'Including the dearest.' );

// --- What must never reach the engine ------------------------------------

$mixed = array(
	msrwa_row( 'claude', 'claude-sonnet-5', 2.0, 10.0, true ),
	// Unpriced: the ceiling that refuses an over-budget lot could not see it.
	msrwa_row( 'claude', 'claude-unpriced', null, null, true ),
	// Retired: the provider was asked and did not name it.
	msrwa_row( 'claude', 'claude-retired', 1.0, 5.0, false ),
	// Never asked about. Silence is not a denial, so it stays usable.
	msrwa_row( 'claude', 'claude-unasked', 0.5, 2.0, null ),
);
$handed = MSRWA_Catalog::for_engine( $mixed );
msrwa_test_missing( wp_json_encode( $handed['models'] ), 'claude-unpriced', 'A model with no rate never reaches the engine.' );
msrwa_test_contains( wp_json_encode( $handed['models'] ), 'claude-retired', 'A retired model keeps its rate, for the runs that used it.' );
foreach ( $handed['tiers'] as $tier => $byprovider ) {
	msrwa_test_assert( 'claude-retired' !== $byprovider['claude'], 'A model the provider no longer serves is never a ' . $tier . ' tier.' );
}
msrwa_test_assert( 'claude-unasked' === $handed['tiers']['low']['claude'], 'A model nothing has asked about is still usable: silence is not a denial.' );

// Tiers are the text routes; image generation names its model outright. A
// catalogue sorted by price alone once put an image model in front of the
// article step, which is the cheapest way there is to ruin a lot.
$images = array(
	msrwa_row( 'openai', 'gpt-image-cheap', 0.01, 0.02, true, array(), array( 'text' => false, 'image_generation' => true ) ),
	msrwa_row( 'openai', 'gpt-5.6-luna', 0.2, 1.2, true ),
	msrwa_row( 'openai', 'gpt-unknown-ability', 0.05, 0.1, true, array(), array() ),
);
$handed = MSRWA_Catalog::for_engine( $images );
msrwa_test_assert( 'gpt-5.6-luna' === $handed['tiers']['low']['openai'], 'A text tier never names an image model, however cheap.' );
msrwa_test_assert( 'gpt-5.6-luna' === $handed['tiers']['high']['openai'], 'Nor at the top.' );
// Prose routed at a model nobody has established can write it is a gamble
// with the owner's money, so an unstated capability is not assumed.
msrwa_test_contains( wp_json_encode( $handed['models'] ), 'gpt-unknown-ability', 'A model of unknown ability keeps its rate,' );
foreach ( $handed['tiers'] as $tier => $byprovider ) {
	msrwa_test_assert( 'gpt-unknown-ability' !== $byprovider['openai'], 'but is not made the ' . $tier . ' text tier on a guess.' );
}

// A provider the catalogue cannot fill is left out entirely, so the engine's
// own default stands rather than half a map routing one provider and not another.
$empty = MSRWA_Catalog::for_engine( array( msrwa_row( 'openai', 'gpt-x', null, null, true ) ) );
msrwa_test_assert( array() === $empty['models'], 'Nothing priced means nothing handed over.' );
msrwa_test_assert( array() === $empty['tiers'], 'And no tiers at all, rather than a broken one.' );

// --- The model x step grid ------------------------------------------------

$free = msrwa_row( 'claude', 'claude-sonnet-5', 2.0, 10.0, true );
msrwa_test_assert( MSRWA_Catalog::allows( $free, 'article' ), 'A model with nothing decided about it may serve any step: a fresh install must still run.' );

$narrow = msrwa_row( 'claude', 'claude-sonnet-5', 2.0, 10.0, true, array( 'article', 'proofread' ) );
msrwa_test_assert( MSRWA_Catalog::allows( $narrow, 'article' ), 'A step the owner ticked is allowed.' );
msrwa_test_assert( MSRWA_Catalog::allows( $narrow, 'proofread' ), 'And so is the other one.' );
msrwa_test_assert( ! MSRWA_Catalog::allows( $narrow, 'research' ), 'A step left unticked is refused.' );

// --- The shipped catalogue seeds the table --------------------------------

$catalog = MSRWA_Catalog::defaults();
msrwa_test_assert( array( 'openai', 'gemini', 'claude' ) === array_keys( $catalog ), 'One section per provider, named the engine’s way.' );
foreach ( $catalog as $provider => $models ) {
	foreach ( $models as $id => $model ) {
		msrwa_test_assert( '' !== trim( (string) ( $model['label'] ?? '' ) ), $provider . ':' . $id . ' has a name a person can read.' );
		msrwa_test_assert( (float) ( $model['input'] ?? 0 ) > 0 && (float) ( $model['output'] ?? 0 ) > 0, $provider . ':' . $id . ' is priced.' );
		// A price nobody can check is a price nobody will maintain.
		msrwa_test_assert( 0 === strpos( (string) ( $model['source'] ?? '' ), 'https://' ), $provider . ':' . $id . ' names where its rate came from.' );
	}
}

// The three provenances are distinct values, because a rate a model looked up
// must never be indistinguishable from one a person checked.
$methods = array( MSRWA_Catalog::SHIPPED, MSRWA_Catalog::MANUAL, MSRWA_Catalog::LOOKED_UP );
msrwa_test_assert( 3 === count( array_unique( $methods ) ), 'Shipped, typed and looked-up are three different things.' );

msrwa_test_done( 'model catalogue' );
