# Working in this repository

WordPress plugin, PHP, no build step. It generates culinary articles with AI
providers, measures them, and hands editors a draft.

Two documents drive the work:

- [`.claude/docs/PLAN.md`](.claude/docs/PLAN.md) — the six milestones in plain
  language, for the site owner.
- [`.claude/docs/BUILD-CHECKLIST.md`](.claude/docs/BUILD-CHECKLIST.md) — the
  task list to execute, with the tests each task needs and the rule for marking
  it done.

[`.claude/docs/ARCHITECTURE.md`](.claude/docs/ARCHITECTURE.md) describes the
code as it exists; read it before changing behaviour.
[`.claude/docs/ENGINE.md`](.claude/docs/ENGINE.md) is the engine's contract —
how it is called, configured and what it returns — and is what the plugin is
written against.

## Repository map

```
ms-recipes-writer-ai.php   bootstrap: constants, activation, autoload
uninstall.php              what is removed when the plugin is deleted
includes/                  the plugin: one class per file, MSRWA_ prefix
includes/engine/           the engine and its prompts — the owner's, see below
assets/                    admin.css and admin.js, edited directly
languages/                 .po/.pot sources and the compiled .mo catalogues
tests/                     offline suite (no WordPress); tests/real/ drives a site
tools/                     the prompt lab and the i18n compiler; no WordPress
.claude/docs/              every document but README.md
README.md                  for the site owner: current state, install, changelog
```

## Commands

```bash
php tests/run.php          # lint + offline suite; must be green before committing
php tests/run.php lists    # filter by filename fragment
php -l includes/class-msrwa-admin.php
php tests/real/run.php     # against a live site; see .claude/docs/TESTING.md
php tools/i18n.php --missing   # untranslated strings, per catalogue
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
  written in French and translated to English and Arabic: after adding one,
  run `php tools/i18n.php extract`, add it to both `languages/*.po`, then
  `php tools/i18n.php compile` — `tests/test-i18n.php` fails on any gap.
- Escape on output (`esc_html`, `esc_attr`, `esc_url`), prepare every query
  parameter, never interpolate request values into SQL.

## Rules that must not be broken

They are listed as *Invariants* in
[`.claude/docs/ARCHITECTURE.md`](.claude/docs/ARCHITECTURE.md). The ones that
bite hardest:

1. **Scope before you read.** Editors without `msrwa_view_all` see only rows
   they own, enforced by `MSRWA_Rights::scope_sql()` in the query, never in a
   template.
2. **Quality belongs to the article**, not to the job that ran, and never to a
   job that produced nothing.
3. **No secrets in stored data.** Artifacts, events and calls pass through
   `MSRWA_DB::sanitize()`.
4. **`completed` is not editorial approval.** Never phrase it as validation.
5. **Costs are estimates**, never an invoice.
6. A worker never resumes a paused, cancelled or awaiting-decision job.
7. **Writers never see money or engine vocabulary**: no amount, model, token,
   score or HTTP status on any screen or API response they can reach — the one
   exception being the site ceiling that refused their lot (invariant 11).
8. **The engine is the owner's.** Nothing under `includes/engine/` changes
   without the owner's approval; propose it in
   [`.claude/docs/ENGINE.md`](.claude/docs/ENGINE.md) §7 instead.
9. **Model output is data, never markup.** Anything a model wrote is stripped
   of tags before it is stored where another plugin or a template will read it.

## Shipping a change

1. Write or extend a test in `tests/`, and a real test in `tests/real/` when
   the change touches WordPress, the database or a provider (see
   [`.claude/docs/TESTING.md`](.claude/docs/TESTING.md)).
2. `php tests/run.php` — green, including lint.
3. Bump the version in `ms-recipes-writer-ai.php` (header **and**
   `MSRWA_VERSION`), add a `## Version x.y.z` section to `README.md`. The
   version triggers the database migration, so never reuse one.
4. Tick the task in
   [`.claude/docs/BUILD-CHECKLIST.md`](.claude/docs/BUILD-CHECKLIST.md) in the
   same commit, and update
   [`.claude/docs/ARCHITECTURE.md`](.claude/docs/ARCHITECTURE.md) if an
   invariant moved. A task is `[x]` only after its real test ran; `[~]` while
   it is covered offline only.
5. Commit one behaviour at a time, message in the imperative, body explaining
   why.

## Database changes

`MSRWA_DB::install()` runs on activation and whenever `MSRWA_VERSION` differs
from the stored `db_version`. Add columns to the `CREATE TABLE` statement
**and** to the guarded `ALTER TABLE` list, and make any backfill idempotent
and bounded — it will run on sites with existing data.
