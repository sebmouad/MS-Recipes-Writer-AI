# Roadmap

Working notes for whoever continues this plugin, human or model. Keep it
current: move items between sections in the same commit that changes the code,
and add what you discover.

Target: a version that can run on a production site without surprising its
editors about cost, quality or data.

## Done

- DB-first state: settings, catalogue, prompts, artifacts and snapshots in
  versioned tables; secrets redacted before any write.
- Pipeline with bounded corrections, budget reservations, provider cost
  capture and uncertain-result handling.
- Deterministic structural gate before the AI review; editorial report at
  delivery; partial drafts saved for human recovery.
- Quality measured on the **article**, stored on the job row, filterable.
- Articles and Jobs lists: full pagination, search, publication/quality/state/
  stage/period filters, per-editor scoping, administrator author filter, whole
  job record per row.
- Offline test suite plus CI on PHP 7.4/8.1/8.3, with regression tests for the
  admin lists, statistics and queue scheduling.
- Queue drains a whole batch: free slots are refilled as jobs finish and an
  unclaimed job re-schedules itself instead of being dropped.

## Next — ordered

1. **Internationalisation.** Every user-facing string is hardcoded French.
   Wrap them in `__()`/`esc_html__()` with the `ms-recipes-writer-ai` text
   domain, ship `languages/ms-recipes-writer-ai.pot`, keep French as the
   shipped translation. Large but mechanical; do it before the string set
   grows further.
2. **Database-backed tests.** The suite never executes SQL. Add an optional
   integration path (`wp eval-file` or a MySQL service in CI) covering the
   migration, the list queries and the batch lifecycle. This is the largest
   remaining risk to a production release.
3. **Migration cost.** The quality backfill runs in 200-row chunks during
   `install()`. On a large install it should become a scheduled task with a
   resume cursor.
4. **Retention and privacy.** `purge_expired()` deletes events and calls by
   age; artifacts and snapshots grow without bound. Add a retention policy per
   artifact kind and document what a site owner keeps.

## Known gaps and risks

- **Unaudited modules.** `class-msrwa-settings.php`,
  `class-msrwa-images.php`, `class-msrwa-storage.php` and the provider
  adapters have not had a line-by-line review. `class-msrwa-pipeline.php` was
  read end to end in the 0.2.42 audit but has no offline coverage of its stage
  machine; that is the next test to write.
- **Cron dependency.** Without Action Scheduler, progress depends on WP-Cron;
  a site with `DISABLE_WP_CRON` and no system cron silently stops processing.
- **Cost estimates** come from the catalogue captured at call time. They are
  not an invoice, and an interrupted call may still be billed. Never present
  them as billing.
- **Concurrency** relies on MySQL `GET_LOCK`. Hosts that pool connections
  across requests can behave differently; verify on the target host.
- **No uninstall of media.** Generated attachments survive uninstall by
  design; document this for site owners.

## Conventions for changes

- One behaviour per commit, with the test that proves it.
- Update `docs/ARCHITECTURE.md` when an invariant changes, and this file when
  the plan changes.
- Bump the version in `ms-recipes-writer-ai.php` (header and constant) and add
  a README changelog section; `tests/test-version.php` enforces it. The version
  also triggers the database migration, so never reuse one.
