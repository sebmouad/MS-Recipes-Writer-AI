# Testing

The suite runs offline: no WordPress, no database, no network, no API keys.
It exists so a change can be proven before it reaches a site.

```bash
php tests/run.php            # lint every PHP file, then run every test
php tests/run.php lists      # only tests whose filename contains "lists"
```

The runner exits non-zero when a lint or a test fails; CI
(`.github/workflows/ci.yml`) runs exactly this command on PHP 7.4, 8.1 and 8.3.

## What each test covers

| File | Covers |
| --- | --- |
| `test-contracts.php` | Catalogue eligibility, input normalization, stage transitions, router plan, quality gate pass and rejection |
| `test-openai-contracts.php` | Request shape and response parsing of the OpenAI transport, offline |
| `test-inline-links.php` | Internal links stay inside paragraphs, no external target, no nested anchor |
| `test-editorial-report.php` | A partial draft never gets a passing verdict; a structural score never hides a failed review |
| `test-presentation.php` | Public state vocabulary, article quality (stored and artifact based), batch aggregation over articles |
| `test-lists.php` | Filter sanitization, capability scoping, prepared parameters, quality filter mapping |
| `test-admin-lists.php` | Rendering of both lists: scoping in SQL, filters applied, escaping, batch controls, pagination |
| `test-queue.php` | Batches keep draining past the concurrency limit, expired leases free their slot, an unclaimed job is re-scheduled |
| `test-version.php` | Plugin header, `MSRWA_VERSION`, README version and changelog agree; every class keeps its direct-access guard |
| `test-draft-integration.php` | Draft creation against a real site. Skipped by the runner; run with `wp eval-file` on localhost |

## Writing a test

Start from the shared harness. It declares the WordPress functions the plugin
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
- `msrwa_test_as_editor()` / `msrwa_test_as_admin()` — set capabilities and the current user.
- `msrwa_test_settings( array( 'max_corrections' => 0 ) )` — override the settings double.
- `$GLOBALS['wpdb']->on( $needle, $rows )` — return rows for queries containing `$needle`;
  `->default_var( 3 )` sets what `get_var()` (counts) returns; `->log()` and
  `->matching()` expose the recorded SQL.
- `msrwa_test_assert()`, `msrwa_test_contains()`, `msrwa_test_missing()` record
  failures without stopping, so one run reports every problem.
- `msrwa_test_done( 'label' )` prints the result and sets the exit code.

Failure messages state the rule being protected, not the mechanics: they are
read by whoever broke the rule months later.

## Conventions that keep the suite honest

- Assert behaviour, not implementation. SQL assertions check the clause that
  enforces a rule (`j.owner_id = 7`), not the whole statement.
- Every new rule in `docs/ARCHITECTURE.md` under *Invariants* deserves a test.
- When fixing a bug, add the case that fails before the fix.
- Verify a test can fail: break the code, run it, restore. A test that never
  fails protects nothing.

## Known gaps

- No test executes real SQL, so schema and index changes are unverified until
  a real site runs the migration.
- The pipeline stage machine, image generation and provider adapters beyond
  the OpenAI transport have no offline coverage.
- No browser-level check of the admin screens; rendering tests assert markup
  fragments only.
