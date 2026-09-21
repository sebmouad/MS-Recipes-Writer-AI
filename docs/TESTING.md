# Testing

Three layers. Each answers a different question, and all three gate a task.

| Layer | Question | Runs | Needs |
| --- | --- | --- | --- |
| **Prompt lab** `tools/` | does the prompt produce the required result? | while tuning a prompt | an API key |
| **Offline** `tests/` | does the code keep its contracts? | every push, CI | nothing |
| **Real** `tests/real/` | does it work on a real site, for real money? | before ticking a task | WordPress + app password |

```bash
php tools/prompt-lab.php run research  # create the shared evidence package first
php tests/run.php                      # offline: lint + every offline test
php tests/run.php lists                # filter by filename fragment
php tests/real/run.php                 # real: preflight, then run what it can
```

The prompt lab needs no WordPress and no database. It starts from a title-,
article- or image-led editor brief, creates one sourced research package, and
passes that same package to the canonical recipe, single-call article, images,
review, fact-check and proofreading. See `tools/README.md` for the commands and
real-image provenance rules. Maintained lab prompts can be compared with the
plugin defaults before promotion.

The offline runner exits non-zero on any lint or test failure; CI
(`.github/workflows/ci.yml`) runs it on PHP 8.1 and 8.3.

## Credentials

Real tests read everything from the environment. **Never commit a key, and
never paste one into a chat message.** Set them where the runtime can read
them: for Claude Code on the web, in the environment's variables; on a server,
in the shell profile or the site's `wp-config.php`.

| Variable | What it is | Needed for |
| --- | --- | --- |
| `MSRWA_TEST_WP_PATH` | Absolute path to a WordPress installation with the plugin active | every real test |
| `MSRWA_TEST_SITE_URL` | Public or local URL of that site | draft and JSON-LD checks |
| `MSRWA_OPENAI_KEY` | OpenAI key with access to text, images and web search | writing, images, research |
| `MSRWA_GEMINI_KEY` | Google Gemini key | only if Gemini is in the routing |
| `MSRWA_CLAUDE_KEY` | Anthropic key | only if Claude is in the routing |
| `MSRWA_TEST_BUDGET_USD` | Maximum a single real run may spend, for example `2.00` | every real test that calls a provider |
| `OPENAI_API_KEY` | OpenAI key used by the prompt lab only | `tools/prompt-lab.php` |

A real test that would exceed `MSRWA_TEST_BUDGET_USD` refuses to start. Each
run prints what it spent.

Use a **staging site**, never production: real tests create posts, jobs and
attachments.

## Writing an offline test

Start from the shared harness: it declares the WordPress functions the plugin
touches, a recording `$wpdb`, capability helpers and assertions.

```php
<?php
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'recipe', 'publisher', 'presentation', 'lists' );

msrwa_test_as_editor( 7 );                 // or msrwa_test_as_admin()
$GLOBALS['wpdb']->on( 'FROM wp_msrwa_jobs', array( $row ) );

$args = MSRWA_Lists::sanitize_args( array( 'msrwa_author' => '99' ) );
msrwa_test_assert( 7 === $args['author'], 'A scoped editor must not read another author.' );

msrwa_test_done( 'MSRWA my contract' );
```

Harness API:

- `msrwa_test_load( 'lists', 'admin', … )` — require plugin classes by short name.
- `msrwa_test_as_editor()` / `msrwa_test_as_admin()` — capabilities and current user.
- `msrwa_test_settings( array( 'max_corrections' => 0 ) )` — override the settings double.
- `$GLOBALS['wpdb']->on( $needle, $rows )` — rows for queries containing `$needle`;
  `->default_var( 3 )` sets what `get_var()` returns; `->log()` and `->matching()`
  expose recorded SQL. Register the **narrowest** needle first: the first match wins.
- `msrwa_test_assert()`, `msrwa_test_contains()`, `msrwa_test_missing()` record
  failures without stopping, so one run reports everything.
- `msrwa_test_done( 'label' )` prints the result and sets the exit code.

## Writing a real test

Put it in `tests/real/`, named `test-<subject>.php`. It runs inside a real
WordPress through WP-CLI, so every WordPress and plugin function is available
for real.

```php
<?php
// tests/real/test-example.php — run by tests/real/run.php
msrwa_real_require( 'openai' );            // skips cleanly if the key is absent
$budget = msrwa_real_budget();             // refuses to run past the cap

$batch = msrwa_real_create_batch( 'Tarte aux pommes …' );
msrwa_real_wait( $batch, 900 );            // drive the queue, bounded

$job = msrwa_real_job( $batch );
msrwa_real_assert( $job['draft_post_id'] > 0, 'A draft must exist.' );
msrwa_real_report( $batch );               // prints cost, tokens, verdict
```

Rules for real tests:

- **Clean up**: delete the posts, attachments, jobs and batches created, unless
  the test is asked to keep them for inspection.
- **Bounded**: never loop without a deadline; the queue is asynchronous.
- **Honest**: report the real cost, and fail loudly rather than skipping a
  provider error.
- **Idempotent**: safe to run twice in a row.

## What is covered today

| File | Covers |
| --- | --- |
| `test-contracts.php` | Catalogue eligibility, input normalization, stage transitions, router plan, quality gate |
| `test-openai-contracts.php` | OpenAI transport request shape and response parsing, offline |
| `test-inline-links.php` | Internal links stay inside paragraphs, no external target, no nested anchor |
| `test-editorial-report.php` | A partial draft never gets a passing verdict; a score never hides a failed review |
| `test-ai-quality.php` | Independent content and image verdicts |
| `test-presentation.php` | Public state vocabulary, article quality, batch aggregation over articles |
| `test-lists.php` | Filter sanitization, capability scoping, prepared parameters, quality filter mapping |
| `test-admin-lists.php` | List rendering: scoping in SQL, filters, escaping, row actions, queue notice |
| `test-stats.php` | Article-scoped quality statistics, delivery verdict versus structural gate |
| `test-queue.php` | Batch draining past the concurrency limit, slot accounting, re-scheduling |
| `test-version.php` | Plugin header, constant, README changelog and direct-access guards agree |
| `test-draft-integration.php` | Draft creation on a real site; skipped offline |

## Gaps

- No offline test executes SQL: schema and index changes are only proven by a
  real migration.
- The pipeline stage machine, image generation and the Gemini and Claude
  adapters have no offline coverage.
- `tests/real/` is scaffolding until the credentials above exist.
