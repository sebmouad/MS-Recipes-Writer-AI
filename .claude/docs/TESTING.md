# Testing

Three layers. Each answers a different question, and all three gate a task.

| Layer | Question | Runs | Needs |
| --- | --- | --- | --- |
| **Prompt lab** `tools/` | does the prompt produce the required result? | while tuning a prompt | an API key |
| **Offline** `tests/` | does the code keep its contracts? | every push, CI | nothing |
| **Real** `tests/real/` | does it work on a real site, for real money? | before ticking a task | WordPress + app password |

```bash
php tools/lab.php step research  # create the shared evidence package first
php tools/lab.php run --brief=…  # or the whole pipeline, with its report
php tests/run.php                # offline: lint + every offline test
php tests/run.php lists          # filter by filename fragment
php tests/real/run.php           # real: preflight, then run what it can
```

The prompt lab needs no WordPress and no database. It starts from a title-,
article- or image-led editor brief, creates one sourced research package, and
passes that same package to the canonical recipe, single-call article, images,
review, fact-check and proofreading. See [`LAB.md`](LAB.md) for the commands and
real-image provenance rules. Maintained lab prompts can be compared with the
plugin defaults before promotion.

The offline runner exits non-zero on any lint or test failure; CI
(`.github/workflows/ci.yml`) runs it on PHP 8.1 and 8.3.

## Credentials

Real tests read everything from the environment. **Never commit a key.** Set
them where the runtime can read them: in the environment's variables for a
hosted agent, in the shell profile on a server. The provider keys themselves are
not read by the real tests: they are typed into the site's own *Réglages*
screen, where the plugin encrypts them, and the tests drive that site.

| Variable | What it is | Needed for |
| --- | --- | --- |
| `MSRWA_TEST_SITE_URL` | URL of a WordPress site with the plugin active | every real test |
| `MSRWA_WP_USER` | an administrator's login on that site | every real test |
| `MSRWA_WP_APP_PASSWORD` | that user's application password (*Users → Profile*) | every real test |
| `MSRWA_TEST_BUDGET_USD` | the most one real run may spend, for example `1.00` | every test that calls a provider |
| `MSRWA_TEST_PHOTO` | optional: a real photograph of a Normandy apple tart | `test-flow.php`, to check the writer's photograph is paired and attached to the draft |
| `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY` | provider keys for the lab only | `tools/lab.php` |

A real test that would exceed `MSRWA_TEST_BUDGET_USD` refuses to start, and
each run prints what it spent. Application passwords need HTTPS unless the site
declares `WP_ENVIRONMENT_TYPE` as `local`.

Use a **staging site**, never production: real tests create posts, lots and
attachments. The real layer reaches the API through `?rest_route=`, so it works
with plain permalinks as well as pretty ones.

### A throwaway local site

What the 0.8.0 verification ran on, when no staging site is at hand:

1. WordPress from `git clone --branch <version> https://github.com/WordPress/WordPress`.
2. A database: MySQL if the machine has one; otherwise the
   [SQLite database integration](https://github.com/WordPress/sqlite-database-integration)
   (its `db.copy` becomes `wp-content/db.php`).
3. `wp-config.php` with `WP_ENVIRONMENT_TYPE` set to `local`, then
   `wp_install()` from a PHP script, and `PHP_CLI_SERVER_WORKERS=4 php -S
   127.0.0.1:8080` — several workers, because WP-Cron calls the site back while
   a request is still open.
4. Symlink this repository into `wp-content/plugins/`, activate it, create an
   application password, and export the four variables above.

## Writing an offline test

Start from the shared harness: it declares the WordPress functions the plugin
touches, a recording `$wpdb`, capability helpers and assertions.

```php
<?php
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'ledger' );

msrwa_test_as_editor( 7 );                 // or msrwa_test_as_admin()
$GLOBALS['wpdb']->on( 'FROM wp_msrwa_runs', array( $row ) );

MSRWA_Ledger::runs( array() );
msrwa_test_contains( $GLOBALS['wpdb']->log(), 'owner_id = 7', 'A writer reads only their own runs.' );

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

Put it in `tests/real/`, named `test-<subject>.php`. It drives the live site
over REST with the application password, so it needs no shell on the site.

```php
<?php
require __DIR__ . '/lib.php';
$budget = msrwa_real_budget();             // refuses to run past the cap

$created = msrwa_real_request( 'POST', '/msrwa/v1/batches', array( 'recipes' => "Tarte…", 'profile' => 'article', 'budget' => $budget ) );
msrwa_real_request( 'POST', '/msrwa/v1/batches/' . $created['body']['id'] . '/dispatch' );
msrwa_real_wait( function () { /* poll /batches/{id}/runs */ }, 900, 15 );   // bounded

msrwa_real_assert( $condition, 'What must be true.' );
msrwa_real_done( 'label' );
```

Rules for real tests:

- **Clean up** what you create, unless asked to keep it for inspection.
- **Bounded**: never loop without a deadline; the queue is asynchronous.
- **Honest**: report the real cost, and fail loudly rather than skipping a
  provider error.
- **Idempotent**: safe to run twice in a row.

## What is covered today

Offline, `php tests/run.php` — lint plus one file per contract:

| Area | Files |
| --- | --- |
| Engine and its configuration | `test-engine*.php`, `test-json.php`, `test-resolves.php`, `test-audit-handoff.php` |
| Prompts and scoring | `test-prompt*.php`, `test-ai-quality.php`, `test-approval*.php`, `test-image-*.php`, `test-visual-reference.php`, `test-recipe-structured-data.php` |
| Money | `test-cost.php`, `test-estimate.php`, `test-budget-and-queue.php`, `test-export.php`, `test-analytics.php` |
| The lot and its runs | `test-intake.php`, `test-match.php`, `test-profile.php`, `test-run-lifecycle.php`, `test-bulk-and-schedule.php`, `test-storage.php`, `test-retention.php` |
| Screens and rights | `test-screens.php`, `test-admin-pages.php`, `test-operations.php` |
| Settings and keys | `test-settings-save.php`, `test-keys.php` |
| Publishing | `test-schema.php` |
| Release | `test-version.php`, `test-i18n.php`, `test-uninstall.php` |

Real, `php tests/real/run.php`:

| File | Spends | Proves |
| --- | --- | --- |
| `test-site.php` | nothing | schema and migration, capabilities per role, cron armed, uploads writable, an estimate exists, every key opens its provider, a lot over its ceiling is refused, nothing answers anonymously |
| `test-parallel.php` | three article-only recipes | the lot runs side by side on the site's own worker (at least two at once, about as long as its longest recipe), and the page's fallback moves nothing when nothing is waiting |
| `test-flow.php` | one article-only recipe | submit as the form does, with a photograph → dispatch → cron → draft carrying that photograph, cost within the ceiling and near the estimate, the draft carries its excerpt, slug and tags |
| `test-upload.php` | one photograph, described once | a lot with photographs sent from disk as a browser form does: a file that is not a photograph refuses the lot and leaves the media library untouched, a real one becomes the writer's attachment, and leaves with its lot when that lot is deleted unused |

## Gaps

- No offline test executes SQL; schema changes are proven by the real layer.
- Image generation, the final judge and photograph matching have not run live
  from the test machine used for 0.8.0 (no image provider reachable).
- The real layer has run on SQLite, not yet on MySQL.
