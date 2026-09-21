# Working in this repository

WordPress plugin, PHP, no build step. It generates culinary articles with AI
providers, measures them, and hands editors a draft.

Two documents drive the work:

- [`docs/PLAN.md`](docs/PLAN.md) — the six milestones in plain language, for
  the site owner.
- [`docs/BUILD-CHECKLIST.md`](docs/BUILD-CHECKLIST.md) — the task list to
  execute, with the tests each task needs and the rule for marking it done.

[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) describes the code as it exists;
read it before changing behaviour. [`docs/ENGINE.md`](docs/ENGINE.md) is the
engine's contract — how it is called, configured and what it returns — and is
what the plugin is written against.

## Commands

```bash
php tests/run.php          # lint + offline suite; must be green before committing
php tests/run.php lists    # filter by filename fragment
php -l includes/class-msrwa-admin.php
```

There is no package manager, no transpiler and no vendor directory. CSS and JS
in `assets/` are edited directly and cache-busted by file modification time.

## Branch policy

Work on `main` and push there. Feature branches are not used in this
repository.

## House style

Match the surrounding code; it is deliberate and consistent.

- WordPress style: tabs, spaces inside parentheses, `'value' === $variable`
  comparisons, `array()` over `[]`, one class per file, `final class`,
  `MSRWA_` prefix, filenames `class-msrwa-<short-name>.php`.
- Short methods on one line when they fit; long HTML templates stay inline
  rather than being split into partials.
- Every class file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`
  (enforced by `tests/test-version.php`).
- Comments explain *why*, in English, and are rare. User-facing strings are
  French today — see the internationalisation item in the roadmap.
- Escape on output (`esc_html`, `esc_attr`, `esc_url`), prepare every query
  parameter, never interpolate request values into SQL.

## Rules that must not be broken

They are listed as *Invariants* in `docs/ARCHITECTURE.md`. The ones that bite
hardest:

1. **Scope before you read.** Editors without `msrwa_view_all` see only rows
   they own, enforced in `MSRWA_Lists`/REST, never in a template.
2. **Quality belongs to the article**, not to the job that ran, and never to a
   job that produced nothing.
3. **No secrets in stored data.** Artifacts, snapshots, events and calls pass
   through `MSRWA_DB::sanitize_persisted_data()`.
4. **`completed` is not editorial approval.** Never phrase it as validation.
5. **Costs are estimates**, never an invoice.
6. A worker never resumes a paused, cancelled or awaiting-decision job.

## Shipping a change

1. Write or extend a test in `tests/`, and a real test in `tests/real/` when
   the change touches WordPress, the database or a provider (see
   `docs/TESTING.md`).
2. `php tests/run.php` — green, including lint.
3. Bump the version in `ms-recipes-writer-ai.php` (header **and**
   `MSRWA_VERSION`), add a `## Version x.y.z` section to `README.md`. The
   version triggers the database migration, so never reuse one.
4. Tick the task in `docs/BUILD-CHECKLIST.md` in the same commit, and update
   `docs/ARCHITECTURE.md` if an invariant moved. A task is `[x]` only after its
   real test ran; `[~]` while it is covered offline only.
5. Commit one behaviour at a time, message in the imperative, body explaining
   why.

## Database changes

`MSRWA_DB::install()` runs on activation and whenever `MSRWA_VERSION` differs
from the stored `db_version`. Add columns to the `CREATE TABLE` statement
**and** to the guarded `ALTER TABLE` list, and make any backfill idempotent
and bounded — it will run on sites with existing data.
