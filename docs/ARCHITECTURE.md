# Architecture

MS Recipes Writer AI turns a culinary brief into a reviewed WordPress draft:
a queue of jobs walks a fixed pipeline, every provider call is priced and
logged, and the result is measured before an editor is asked to look at it.

Everything below describes the code as it is, not as it is planned. Planned
work lives in [ROADMAP.md](ROADMAP.md).

## Runtime shape

```
Composer form (admin)
        │  POST /wp-json/msrwa/v1/batches
        ▼
    batch row ──► job rows (one per recipe)
        │
        │  MSRWA_Queue::schedule_batch → schedule_job (Action Scheduler or WP-Cron)
        ▼
    MSRWA_Pipeline::process_job  ── one stage per invocation, re-scheduled after each
        │
        ▼
    MSRWA_Publisher::create_draft ──► WordPress draft + editorial report + stored verdict
```

A job advances one stage per worker run. Each run acquires a lease
(`lock_token`, `lock_until`), does one stage, persists artifacts, then
schedules the next run. A crashed worker leaves an expired lease that
`MSRWA_Queue::recover_expired()` returns to `retry_wait`.

## The engine

`includes/engine/` is the part that makes a recipe. It touches no WordPress
function, reads nothing from disk beyond its own prompts, and never exits: it
takes a brief and returns a `MSRWA_Result`. The plugin and the prompt lab both
call it, which is what stops a prompt proven at the bench from drifting away
from the one that runs in production.

```php
$result = MSRWA_Engine::run(
    array( 'title' => 'Souris d’agneau au four' ),          // the editor’s brief
    array( 'config' => $engine_config, 'workspace' => $dir ),
    function ( $event ) { /* progress, as it happens */ }
);
```

| Class | Responsibility |
| --- | --- |
| `MSRWA_Engine` | The entry point: `run()`, `run_step()`, the wave loop and the retries |
| `MSRWA_Engine_Config` | Three layers of settings — defaults, caller, this run |
| `MSRWA_Engine_Steps` | What each step needs, produces, costs and asks a model for |
| `MSRWA_Engine_Input` | What a step is given before it runs |
| `MSRWA_Engine_Score` | Whether an answer satisfied its step's contract |
| `MSRWA_Engine_Call` | Every provider call, normalized across three providers |
| `MSRWA_Engine_Rates` | Published prices and tiers |
| `MSRWA_Result` | What comes back: artifacts, steps, totals, errors, events |

**Steps run in dependency waves.** `needs` declares what a step waits on, and
everything whose inputs exist may run together:

| Wave | Steps that may run at once |
| --- | --- |
| 1 | research |
| 2 | canonical recipe |
| 3 | **article, featured image, Facebook collage** |
| 4 | **review, fact check, proofread** |
| 5 | final approval |

**Three call paths**, chosen by the capability a step declares: text (including
the web-searching research step), image generation, and the judge that reads
both images' bytes alongside the article. A refused approval regenerates the
images the judge blocked, carrying its findings as corrections, then asks
again — that is what makes "retry until approved" converge rather than reroll.

## Files

| File | Responsibility |
| --- | --- |
| `ms-recipes-writer-ai.php` | Bootstrap, constants, require order, activation hooks |
| `includes/class-msrwa-plugin.php` | Hook registration, capabilities, migration trigger |
| `includes/class-msrwa-db.php` | Schema, migrations, artifacts, snapshots, events, calls, budget reservations |
| `includes/class-msrwa-settings.php` | Settings store, defaults, sanitization, prompt versions |
| `includes/class-msrwa-catalog.php` | Provider/model catalogue, capabilities, pricing |
| `includes/class-msrwa-router.php` | Model selection per stage (automatic prefilter or manual) |
| `includes/class-msrwa-openai.php`, `class-msrwa-providers.php` | Transport adapters, token and cost capture |
| `includes/class-msrwa-recipe.php` | Input normalization, canonical schema validation, **stage vocabulary** |
| `includes/class-msrwa-pipeline.php` | The stage machine: association → research → recipe → article → review → images → final review → draft |
| `includes/class-msrwa-quality.php` | Deterministic structural gate (score /100, blockers, benchmark) |
| `includes/class-msrwa-publisher.php` | Draft creation, meta, internal links, editorial report, stored verdict |
| `includes/class-msrwa-presentation.php` | Public vocabulary: state and **article** quality, batch aggregation |
| `includes/class-msrwa-lists.php` | Filtered, scoped, paginated reads for the admin lists |
| `includes/class-msrwa-stats.php` | Aggregates, exports, event feed |
| `includes/class-msrwa-queue.php` | Leases, concurrency, batch lifecycle, health |
| `includes/class-msrwa-rest.php` | `msrwa/v1` endpoints |
| `includes/class-msrwa-admin.php` | Menus, screens, rendering |
| `includes/class-msrwa-images.php`, `class-msrwa-storage.php` | Image generation/validation, private temporary files |

## Data model

All tables are prefixed `{$wpdb->prefix}msrwa_`. `MSRWA_DB::tables()` is the
only place table names are built.

- **batches** — one creation run. `total` must equal the number of job rows or
  the batch can never complete.
- **jobs** — one recipe. Carries `status`, `stage`, lease columns, cost, the
  linked `draft_post_id`, and the stored article verdict
  (`quality_score`, `quality_passed`, `quality_checked_at`).
- **artifacts** — versioned JSON per job and key (`article`, `canonical`,
  `quality_report`, `editorial_review`, …). One row is `current`, older ones
  are `superseded`.
- **snapshots** — immutable copies of inputs, model plans, settings, decisions.
- **events** — the decision timeline. **calls** — every provider request with
  tokens, cost, HTTP status and redacted payloads.
- **reservations** — budget held before a call, then settled or released under
  a MySQL advisory lock.

Secrets never reach these tables: `MSRWA_DB::sanitize_persisted_data()`
redacts key-like names and drops binary payloads before any write.

## Statuses, states and stages

Three vocabularies exist; do not mix them.

- **Job status** (internal, in the database): `queued`, `running`,
  `retry_wait`, `paused`, `paused_budget`, `awaiting_input`, `awaiting_admin`,
  `needs_review`, `uncertain`, `failed`, `completed`, `cancelled`.
- **Public state** (`MSRWA_Presentation::state()`): `encours`, `completed`,
  `error`, `canceled`. `completed` means processing ended, never that the text
  is editorially approved.
- **Stage** (`MSRWA_Recipe::stages()`): `intake`, `association`, `research`,
  `canonical_recipe`, `article`, `review`, `featured_image`, `facebook_image`,
  `final_review`, `draft`. Transitions are forward-only and validated by
  `MSRWA_Recipe::can_transition()`. Anything that lists stages must read this
  method, never a hand-written copy.

## Quality is a property of the article

A job that produced no article has no quality. `MSRWA_Presentation::quality()`
returns `code => 'none'` for it, and `MSRWA_Presentation::batch()` averages
only over the articles a batch produced.

Two measurements exist and answer different questions:

1. `MSRWA_Quality::evaluate()` — the deterministic structural gate run during
   the pipeline: length, headings, paragraphs, recipe completeness, SEO
   lengths, distribution. Produces `quality_report` (score /100, `pass`,
   findings, benchmark) and can send a job back for correction.
2. `MSRWA_Publisher::editorial_report()` — the delivery verdict, combining the
   structural score with the AI review result, image reviews and delivery
   findings. Produces `editorial_review` and `status`
   (`checks_passed` / `needs_review`).

The second is what editors see. It is written to the job row by
`MSRWA_DB::store_article_quality()` when the draft is created, so lists can
filter and sort on it without decoding artifacts. `quality_checked_at` marks a
row as measured; rows created before the columns existed are backfilled once
during migration.

Badge codes: `good` (completed and every check passed), `review`, `incomplete`
(failed), `uncertain`, `pending` (not measured yet), `none` (no article).

## Permissions

| Capability | Meaning |
| --- | --- |
| `msrwa_create` | May create batches (editors, administrators) |
| `msrwa_view_own` | May read their own jobs |
| `msrwa_view_all` | May read every editor's jobs (administrators) |
| `msrwa_manage` / `manage_options` | Configuration, catalogue sync, provider tests |

Scoping is enforced **in the query layer**, never in the template:
`MSRWA_Lists::sanitize_args()` pins `author` to the current user unless the
reader has `msrwa_view_all`, so a forged `msrwa_author` parameter cannot widen
a result set. REST callbacks re-check ownership row by row.

## Admin screens

- **Créer des Articles/Images** (`page()`) — composer plus two lists:
  **Articles** (default) and **Jobs**, both filtered, scoped and paginated by
  `MSRWA_Lists`. The jobs view also holds the batch controls.
- **Détail du lot** (`job_page()`) — batch KPIs, job cards, timeline, calls.
- **Détail du job** (`job_detail_page()`) — the full diagnostic: inputs, model
  plan, calls with redacted payloads, artifacts, snapshots, timeline.
- **Statistiques** (`stats_page()`) — cost, activity, article quality, exports.
- **Configuration** (`settings_page()`) — modes, budgets, prompts, catalogue,
  queue health, history.

## REST

Namespace `msrwa/v1`, all authenticated through WordPress cookies and nonce:
`POST /batches`, `GET /batches/{id}`, `POST /batches/{id}/{pause|resume|cancel}`,
`POST /jobs/{id}/{retry|cancel|association}`, `GET /stats`, `GET /events`,
`GET /export`, `GET /catalog`, `POST /catalog/sync`, `POST /test/{provider}`.

## Invariants

Break these and the plugin misreports itself:

1. A batch's `total` equals the number of its job rows.
2. Quality belongs to an article; a job without one shows no verdict.
3. `MSRWA_Presentation::state()` is the only public state vocabulary.
4. Stage lists come from `MSRWA_Recipe::stages()`.
5. List queries are scoped by capability before they run.
6. Nothing writes a provider key, a private path or binary data into
   artifacts, snapshots, events or calls.
7. A worker never resumes a paused, cancelled or awaiting-decision job.
8. Costs displayed are estimates from the captured catalogue, never an invoice.
