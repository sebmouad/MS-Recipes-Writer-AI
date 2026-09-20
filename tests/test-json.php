<?php
// Every provider answer in tools/runs/ that failed to parse failed for one of
// three reasons. Each case below is a real answer, not an invented one.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'json' );

$clean = MSRWA_Json::decode( '{"title":"Tarte normande","words":2800}' );
msrwa_test_assert( is_array( $clean ) && 'Tarte normande' === $clean['title'], 'Valid JSON must decode unchanged.' );

// Claude Opus 5 with web search, 2026-09-20: a sentence before the object.
$preamble = MSRWA_Json::decode( 'I\'ll research this dish now.{"recipe_facts":[{"source":"https://example.org","text":"Cuire 45 minutes."}]}' );
msrwa_test_assert( is_array( $preamble ) && 1 === count( $preamble['recipe_facts'] ), 'A sentence written before the object must not lose the answer.' );

// Claude Sonnet 5, 2026-09-20: a raw newline inside an 18 KB content_html value.
$raw = "{\"content_html\":\"<p>Premier paragraphe.</p>\n<p>Second paragraphe.</p>\",\"words\":2900}";
msrwa_test_assert( null === json_decode( $raw, true ), 'The captured answer really is invalid JSON.' );
$repaired = MSRWA_Json::decode( $raw );
msrwa_test_assert( is_array( $repaired ), 'A raw newline inside a string must be repaired, not thrown away.' );
msrwa_test_contains( $repaired['content_html'], "<p>Premier paragraphe.</p>\n<p>Second paragraphe.</p>", 'The repair must keep the newline as content.' );
msrwa_test_assert( 2900 === $repaired['words'], 'The repair must not disturb the rest of the answer.' );

// A fenced answer, which Gemini returns when asked for JSON without a mime type.
$fenced = MSRWA_Json::decode( "```json\n{\"pass\":true,\"findings\":[]}\n```" );
msrwa_test_assert( is_array( $fenced ) && true === $fenced['pass'], 'A Markdown fence must be stripped.' );

// An escaped quote inside a string must survive the control-character repair.
$escaped = MSRWA_Json::decode( "{\"excerpt\":\"Une tarte dite \\\"normande\\\".\nFondante.\"}" );
msrwa_test_assert( is_array( $escaped ), 'An escaped quote must not desynchronise the repair.' );
msrwa_test_contains( $escaped['excerpt'], 'dite "normande".', 'The escaped quote must decode to a quote.' );

// A truncated answer is a real error and must stay one.
msrwa_test_assert( null === MSRWA_Json::decode( '{"content_html":"<p>Coupé au milieu' ), 'A truncated answer must fail rather than decode partially.' );
msrwa_test_assert( null === MSRWA_Json::decode( 'Je ne peux pas répondre.' ), 'An answer with no JSON at all must fail.' );
msrwa_test_assert( null === MSRWA_Json::decode( '' ), 'An empty answer must fail.' );

msrwa_test_done( 'json' );
