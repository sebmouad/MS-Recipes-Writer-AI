# Architecture

The plugin has one job. A writer hands over several recipes and several
photographs without saying which go together; the plugin works out the pairing,
builds a brief per recipe, sends every one of them to the engine, and turns what
comes back into a WordPress draft.

Everything below describes the code as it is. [`ENGINE.md`](ENGINE.md) is the
engine's own contract and is the document to read before changing anything
under `includes/engine/`.

## The shape of a run

```
Composer  ── POST /msrwa/v1/batches
   │          recipes split, photographs described, pairing proposed
   ▼
batch row (status: matching → ready)
   │
   │  the writer confirms or corrects the pairing, then dispatches
   ▼
run rows, one per recipe (queued)
   │
   │  msrwa_run_step, one cron tick per dependency wave
   ▼
MSRWA_Run::advance ──► MSRWA_Engine::run( only: this wave )
   │                      steps, calls, events, artifacts written down
   ▼
MSRWA_Draft::create ──► WordPress draft + media library + post meta
   │
   ▼
MSRWA_Run::release_stored ──► the copies WordPress now holds are dropped
```

A whole run takes some four and a half minutes, which no PHP request survives.
So it is cut where the engine already cuts it — at the dependency wave. One tick
runs the waves that are ready, writes down what they produced, and schedules the
next. Nothing is held between ticks: a request the host kills costs at most the
wave it was in, `MSRWA_Run::recover_expired()` returns the lease, and the run
resumes from the last step that finished.

## The engine, and the line around it

`includes/engine/` makes a recipe. It touches no WordPress function, reads
nothing from disk beyond its own prompts, and never exits: it takes a brief and
returns a `MSRWA_Result`. The command-line lab and this plugin call the same
code, which is what stops a prompt proven at the bench from drifting away from
the one that runs in production.

The plugin adapts to the engine, never the reverse. Everything the plugin wants
differently is expressed as the engine's own caller configuration:

- **which steps run** — `MSRWA_Profile` turns "article only" into a step list
  plus the `needs` those dropped steps leave dangling;
- **which language** — the engine's own `language` key;
- **which models, ceilings, prices, prompts** — `MSRWA_Engine_Settings` stores
  only the difference from the engine's defaults and hands it over as the caller
  layer;
- **the API keys** — under `settings.keys.<provider>`, the one branch of the
  engine's configuration that never reaches a stored record.

Seven classes are the engine's own dependencies rather than application code and
must not be treated as the plugin's to delete: `MSRWA_Json`, `MSRWA_Recipe`,
`MSRWA_Quality`, `MSRWA_Prompt`, `MSRWA_Images`, `MSRWA_Catalog`, `MSRWA_Cost`,
along with `MSRWA_Settings::defaults()`, which `Prompt`, `Quality` and `Images`
all read.

## Matching is the plugin's own step

Pairing photographs with recipes happens before any engine run and uses the
engine only the way any caller may: through `MSRWA_Engine_Call`, with a prompt of
its own. Nothing in `includes/engine/` knows it exists.

Two passes, because they cost differently. Every photograph is described once,
concurrently — the expensive half, billed per image. Then one cheap text call
reads the descriptions against the recipe titles. Describing a photograph twice
for two candidate recipes would pay twice for the same photograph, and correcting
a pairing afterwards is therefore free.

In doubt the model does not pair. A photograph left aside costs less than one
attached to the wrong dish, which would illustrate a whole article.

## Data model

Six tables, all prefixed `wp_msrwa_`.

| table | one row per | holds |
|---|---|---|
| `batches` | submission | recipes, photographs, profile, language, ceiling, the pairing |
| `runs` | recipe | state, progress, cost, seconds, the draft it became |
| `steps` | step attempted | model, seconds, tokens, cost, scorecard, failed-check count |
| `calls` | provider call | endpoint, tier, tokens, cached share, price |
| `events` | thing that happened | the timeline |
| `artifacts` | named output | what the engine produced |

Rows rather than one JSON blob per run, deliberately: a blob answers *what
happened in run twelve*, rows answer *what the review costs across every run*,
and the second is the question worth asking.

Settings live in two options, not tables: `msrwa_settings` (the difference from
the shipped defaults, keys encrypted) and `msrwa_engine_config` (the difference
from the engine's defaults).

### What is not stored twice, and what deliberately is

Once a draft exists, WordPress holds the article in `post_content`, the recipe
and the verdict in post meta, and the images in the media library. Those copies
are released from `artifacts`: `article` and `corrected` are superseded versions
nobody reads, and `canonical` and `approval` live in meta this plugin wrote
itself.

`proofread` is kept, and that is the one deliberate duplicate. `post_content` is
what an editor has since changed; the artifact is what the machine produced.
Collapsing them removes the only answer to *did the model write that claim, or
did somebody add it* — and any measurement of the engine taken from corrected
text measures editors instead. It also means a run survives its draft being
deleted.

Everything else has no WordPress home and stays: the research package, the
review, the fact check, the image prompts.

## Permissions

Three capabilities, decided in one place — `MSRWA_Rights` — because scattered
checks are how a list ends up scoped on one screen and not the next.

| capability | may |
|---|---|
| `msrwa_create` | submit work, see and act on their own |
| `msrwa_view_all` | see everyone's work |
| `msrwa_manage` | settings, the engine, analysis, deletion, the whole ledger |

`manage_options` is honoured everywhere as a superset. `msrwa_view_all` alone
does **not** widen a writer's view: sites carry that capability from an earlier
version where it meant something else.

`MSRWA_Rights::scope_sql()` returns the owner clause rather than leaving callers
to apply it. A query that forgets it has no `WHERE` at all and fails review,
where one that forgets an inline check quietly shows another writer's work.

Money is an operator's concern: a screen that may not show a figure does not
fetch it either — `MSRWA_Ledger::runs()` changes its `SELECT` list by capability.

## Screens

| screen | who | what |
|---|---|---|
| Le pass | writer | what is running, what waits to be read, what stopped |
| Nouveau lot | writer | recipes, photographs, profile, language, ceiling |
| Lot | writer | the pairing to confirm, then its recipes |
| Recette | writer | the verdict, the steps; diagnostics for managers only |
| Articles | writer | every run, filtered, with bulk actions |
| Analyse | manager | cost by step, by model, by day; failing checks; CSV export |
| Moteur | manager | every engine parameter, and where each step would route |
| Réglages | manager | keys, and whether the machinery is actually running |

Plus a meta box on the post editor, because a writer opens the article, not this
plugin, and a warning on a dashboard nobody opened has warned nobody.

The interface ships in French, English and Arabic. `tools/i18n.php` extracts and
compiles the catalogues, because there is no gettext toolchain and no build step.

## REST

Namespace `msrwa/v1`, WordPress cookies and nonce, every response `no-store`
(a page cache once served an application-password response to the public).

`GET|POST /batches`, `DELETE /batches/{id}`, `POST /batches/{id}/{pairs|schedule|dispatch}`,
`GET /batches/{id}/runs`, `POST /runs/bulk`, `POST /runs/{id}/{retry|cancel}`,
`GET /estimate`, `GET /health`, `POST /diagnostics/config`.

## Invariants

Break these and the plugin misreports itself.

1. **The plugin adapts to the engine.** Anything the plugin needs differently is
   caller configuration. An engine change is the owner's decision, not a
   convenience.
2. **A cost is `NULL` when the model carries no published rate** — never zero —
   and every total carries the count of unpriced steps beside it. A run whose
   spend cannot be verified is not a run that was free.
3. **`done` is not editorial approval.** A finished run is waiting for a reader.
   No screen phrases it as validation.
4. **The judge's verdict is the engine's opinion of its own output**, and is
   never presented as a decision to publish.
5. **List queries carry `MSRWA_Rights::scope_sql()`** before they run.
6. **Nothing stored carries a key, a private path or a binary payload** —
   everything passes through `MSRWA_DB::sanitize()`.
7. **Every top-level engine configuration group is reachable** from the Moteur
   screen; `tests/test-engine-settings.php` fails when one is not.
8. **Every static call resolves** — `tests/test-resolves.php`. PHP only notices a
   missing method at the moment of the call, which once let a fatal ship green.
9. **Artifacts are released only after the draft exists**, and `proofread` is
   never released.
10. **A run still moving is never deleted** under its own worker.
