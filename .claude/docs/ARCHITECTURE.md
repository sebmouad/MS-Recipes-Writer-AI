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
   │                     article as blocks (MSRWA_Blocks), excerpt, slug,
   │                     tags, SEO, recipe card meta
   │                     (published later: MSRWA_Schema prints Recipe JSON-LD,
   │                      MSRWA_Head the description and the share preview)
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

A tick keeps starting waves for up to `MSRWA_Run::TICK_SECONDS` (150 s),
renewing its lease before each: WordPress's cron fires only on a visit, and a
recipe that handed every wave back waited for a visitor seven times. Each wave
is still written down before the next begins.

Between two ticks a run is `running` with no lease, and only its cron event
carries it on. WordPress keeps every event in one option, so workers finishing
together can overwrite each other's next event — two of three recipes in a
live lot sat forever that way. The watchdog therefore re-arms every leaseless
`running` run as well as every `queued` one; `queue()` skips a run already
armed and the claim stops a duplicate from spending anything.

Two things can stop a tick before it claims anything: an operator hold
(`MSRWA_Queue`) and a spending ceiling the site has reached (`MSRWA_Budget`).
Either one parks the run — back to `queued`, lease released, `updated_at`
untouched so it keeps its place in line — and it resumes by itself when the hold
lifts or the day rolls over. Neither is a failure, and neither loses a step.

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
- **which language** — `settings.site_language`, which is what the prompts
  read (the engine's top-level `language` key is recorded but read by nothing),
  with the French accent checks relaxed for other languages;
- **which site settings** — `settings` carries the site's complete prompt and
  quality settings, from `MSRWA_Settings::engine_settings()`: a non-empty
  `settings` replaces the engine's defaults wholesale;
- **which models, ceilings, prices, prompts** — `MSRWA_Engine_Settings` stores
  only the difference from the engine's defaults and hands it over as the caller
  layer;
- **the API keys** — under `settings.keys.<provider>`, with the provider named
  the engine's way (`MSRWA_Settings::key_fields()`), the one branch of the
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
| `msrwa_create` + `upload_files` | submit work, see and act on their own |
| `msrwa_view_all` | see everyone's work |
| `msrwa_manage` | settings, the engine, analysis, deletion, the whole ledger |

The plugin is for users WordPress already trusts with uploads, since a lot's
photographs are stored on the site: `MSRWA_Rights::may_write()` requires
`upload_files` beside `msrwa_create` — authors, editors and administrators by
default, never a contributor. Without it there is no menu, no screen, no route
and no box in the post editor, whatever else the user holds.

`manage_options` is honoured everywhere as a superset. `msrwa_view_all` alone
does **not** widen a writer's view: sites carry that capability from an earlier
version where it meant something else.

`MSRWA_Rights::scope_sql()` returns the owner clause rather than leaving callers
to apply it. A query that forgets it has no `WHERE` at all and fails review,
where one that forgets an inline check quietly shows another writer's work.

Money is an operator's concern: a screen that may not show a figure does not
fetch it either — `MSRWA_Ledger::runs()` changes its `SELECT` list by capability,
the pass skips the spend query for a writer, and `GET /estimate` answers a writer
with whether the lot fits and nothing else. What a writer reads instead is one
sentence per recipe from `MSRWA_UI::reason()`: where it stands and who has to
act, never a model name or an HTTP status.

## Screens

| screen | who | what |
|---|---|---|
| Le pass | writer | what is running, what waits to be read, what stopped |
| Nouveau lot | writer | recipes, photographs, profile, language, ceiling |
| Lot | writer | the pairing to confirm, then its recipes |
| Recette | writer | where it stands in one sentence, the verdict, the steps by name; scores, models, calls and timeline for managers only |
| Articles | writer | every run, filtered, with bulk actions |
| Analyse | manager | cost by step, by model, by day; failing checks; CSV export |
| Moteur | manager | every engine parameter, and where each step would route |
| Diagnostic | manager | the eight things that must be true for a recipe to finish, each with its remedy, and a report to paste into a request for help |
| Réglages | manager | keys and a free check that each one works, ceilings, the article (language, length, pages, JSON-LD), retention, and whether the machinery is running |

Plus the verdict on the post editor, because a writer opens the article, not
this plugin, and a warning on a dashboard nobody opened has warned nobody. It
reaches both editors from one `MSRWA_Editor::verdict()`: a meta box for the
classic editor, and — since the block editor folds meta boxes away behind a
collapsed drawer — a sidebar panel and a pre-publish check for the block
editor, the second opening by itself when something blocks publication. It also
carries the SEO title, meta description and Facebook caption the article wrote.

The article itself reaches the post as blocks. The engine returns one string of
semantic HTML, and stored as-is the block editor makes a single Classic block
of the whole article — unmovable, untouchable by any block-level tool, which is
the one thing an editor came to work on. `MSRWA_Blocks` converts the tags the
article contract allows (headings, paragraphs, lists with each item its own
block, the page break) and wraps anything else in an HTML block rather than
dropping it.

On the public site, `MSRWA_Schema` prints the recipe as schema.org Recipe
JSON-LD on published posts that carry `_msrwa_recipe`, unless a recipe plugin
that prints its own is active. `MSRWA_Head` does the same for the meta
description, the Open Graph tags and the X card, using the 1200 × 630 image the
engine drew for sharing and falling back to the featured one; it steps aside
when any of the usual SEO plugins is active, and speaks only for posts this
plugin wrote.

`MSRWA_Catalog` is the table that keeps the engine holding nothing but the
editorial process. Which models exist, what they cost and which step each may
serve are not process — they change when a provider renames a model or moves a
price — so they are data the owner edits on the Modèles screen, and the engine
is handed generated `models` and `tiers` through the caller layer it already
accepts. No engine change was needed: both groups were already overridable, and
a group typed by hand still wins over the generated one.

Tiers are derived rather than stored: the engine's own choice for each tier
when the site has that model enabled, priced, served and known to write, and
otherwise the cheapest, middle and dearest of what the site actually has. That
is what stops a tier naming a model nobody priced or the provider retired,
without letting a model's place in a price list decide what every step runs
on. They are the text routes only — image generation names its model outright —
which is why "known to write" is required and not assumed.

What a provider lists is filtered before it is stored: `MSRWA_Catalog::role()`
keeps the chat and image families the engine can call and nothing else, so
speech, music, video, embeddings, dated snapshots and retired generations never
become rows. Rates that shipped are brought up to date on each version; a rate a
person typed or a model looked up never is. Every priced model hands its rate
to the engine — enabling a model only decides whether it can be a tier — and
what the Moteur screen stores is merged over the catalogue model by model, and
compared against it by value, so saving that form never freezes the
catalogue's rates in place.

How hard each step may think is engine configuration (`thinking`), edited on
the Moteur screen like routing. `MSRWA_Estimate` adds to a step's estimated
output only what a level above the measured `medium` would add, capped by the
step's ceiling, so a lower level never makes an estimate read low.

An estimate is two numbers: `cost_usd`, one pass through the profile, and
`max_usd`, every final-approval attempt refused with both images redrawn. The
per-recipe ceiling is checked against the first; screens show both and warn
when the second is above the ceiling. Research is priced at its measured two
paid searches; the maximum prices every allowed tool call as one. A later
version of the article replaces only the fields it filled
(`MSRWA_Engine::filled()`), in the engine and in the draft alike.

What the catalogue keeps is decided by `MSRWA_Catalog::keep()`: the two newest
generations of each role per provider, and anything shipped, named by the
engine, or named by a typed route. OpenAI rates are read straight off each
model's page (`READ`, "page"), a fourth provenance beside shipped, typed and
looked up.

It holds three kinds of knowledge, deliberately kept apart. which identifiers exist, which only the provider knows and which is
fetched; what they cost, which no provider API states — checked against all
three live responses — so a rate is typed by a person or looked up by a model
and marked as such, never silently trusted; and which step a model may serve,
which is the owner's editorial judgement and is only ever set by hand. A route naming a model the provider does not list is a stop, because
that step cannot run; a provider never asked says nothing about its models, so
silence is reported as unknown rather than as absence.

The interface is light only, by the owner's decision, and ships in French,
English and Arabic. `MSRWA_I18N::load()` is hooked on `init`: WordPress 6.7 and
later would load the catalogues without it, which is why nothing noticed it was
missing, but an older site would have stayed French whatever the reader chose.

Anything a provider says back is a credential risk, not only what this plugin
sends. `MSRWA_DB::sanitize()` masks by key name *and* redacts key-shaped
strings wherever they appear, because OpenAI answers a bad key by quoting part
of it and that sentence is stored as a step's error. `tools/i18n.php` extracts and
compiles the catalogues, because there is no gettext toolchain and no build step.

## REST

Namespace `msrwa/v1`, WordPress cookies and nonce, every response `no-store`
(a page cache once served an application-password response to the public).

`GET|POST /batches`, `DELETE /batches/{id}`, `POST /batches/{id}/{pairs|schedule|dispatch}`,
`GET /batches/{id}/runs`, `POST /runs/bulk`, `POST /runs/{id}/{retry|cancel}`,
`GET /estimate`, `GET /health`, `GET|POST /queue`, `POST /retention`,
`POST /keys/check`, `POST /diagnostics/config`.

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
11. **A budget belongs to the site, not to a reader.** `MSRWA_Budget::spent()`
    is deliberately unscoped: a writer refused a lot has to be able to see the
    figure that refused it.
12. **A ceiling parks a run; it never fails one.** Nothing about the article
    went wrong, so nothing it produced is thrown away.
13. **The engine gets the site's whole `settings`, not the keys alone**, and
    every key under the provider name the engine configures —
    `tests/test-settings-save.php`.
14. **A lot whose recipe is estimated above its per-recipe ceiling is refused at
    dispatch.** Stopping it part way would pay for everything before the stop
    and deliver nothing.
15. **Model output written to a meta key a third-party plugin reads is stripped
    of markup first**, in `MSRWA_Draft::map_recipe()` as everywhere else in that
    file. A card plugin's own template is not this plugin's to trust.

16. **A lot's photographs are the files its writer sent, kept out of the
    media library.** They are uploaded from the writer's computer with the lot
    (`photos[]`), never picked from the library: `POST /batches` reads no
    attachment id. `MSRWA_Intake::check()` checks every file from its bytes
    (JPEG, PNG or WebP, under `MSRWA_Admin::photo_bytes()`, at most
    `MSRWA_Intake::MAX_PHOTOS`) and refuses the whole lot on one bad file.
    `MSRWA_Sources` keeps them under `uploads/msrwa/` — closed by `.htaccess`
    and served only by `GET /batches/<id>/photos/<name>` to someone who may see
    the lot — named after their bytes, so the same file sent twice is one file.
    `lots/<lot>/` holds them while the lot is paired; dispatch moves each into
    its run's `<run>/sources/` with `source.json` (what the writer provided;
    what the engine completed and the web photographs it read, in
    `references/`) and drops the rest. Deleting a lot or a run, retention and
    uninstall remove its folder. The media library holds only an article's
    generated images. Lots sent before 0.24.0 still name library attachments
    marked `_msrwa_sent_by_writer`; `MSRWA_Intake::forget()` and
    `engine_image()` serve them and touch nothing else.

17. **The draft is written for the MS stack, in its readers' formats.**
    `MSRWA_Stack::write()` owns the keys in `MSRWA_Stack::KEYS` (the generic
    mapping skips them): minutes as integers, lists one per line, difficulty
    as `easy|medium|hard`, `fb_images_data` as a slashed JSON string. No value
    is invented; an absent one stays absent. When the MS Recipes theme or MS
    SEO Plus prints the head (`MSRWA_Stack::owns_head()`), `MSRWA_Head` and
    `MSRWA_Schema` print nothing and `MSRWA_Stack::enrich()` adds to their
    Recipe graph instead. A lot's language and ceiling are the site settings;
    `POST /batches` reads neither from the request.

18. **Only a model able to serve a step is given it.** `MSRWA_Compat` decides:
    the model must be known to have what the step needs (research searches the
    web, the final approval and the photograph reading read images, the images
    are drawn), must not be in the measured-unfit table
    (`MSRWA_Compat::unfit()` — gpt-5-nano and gpt-5.4-mini on research), and
    must be switched on and not taken off the step on the Modèles screen. A capability nobody
    established counts as missing. Tiers are resolved as the engine resolves
    them, so `openai:low` is judged as gpt-5-nano. Saving the Moteur screen,
    dispatching a lot and the routing diagnostic all refuse on the same rule;
    the pickers disable what it refuses. The Modèles and Moteur screens read
    the same `MSRWA_Compat::steps()` and `choices()`: one list of steps, one
    family rule, one catalogue.

19. **A lot is text, photographs, or both.** Without text, `MSRWA_Match`
    names one recipe per dish the photographs show (`propose()`), marked
    `from_photographs`; a lot in which no dish is recognised is refused and
    its uploads discarded. Without photographs, nothing is paired or billed.

20. **A recipe with the writer's photographs is researched from them.** With
    `research.web_search` at `without_images`, the engine writes the research
    from the brief and the photographs, with no web search, as soon as one is
    readable; without one it searches and reads one web photograph
    (`limits.web_images_inspected`). Each photograph is read once, by
    the pairing, with the engine's own observation instruction; the brief
    carries that reading and the engine does not look again. A lot of one
    recipe is paired without a call. The plugin hands the engine
    `MSRWA_Sources::reader()` as `read_image`, which reads only a stored name
    in that run's folder, and `keeper()` as `keep_image`; the engine never
    fetches a writer's photograph over the network. Saving the pairing keeps
    the model's confidence on rows the writer left alone.

21. **Generated images are left for MS Image Optimizer to finish.** They are
    stored lossy, named after the post slug, with alt, title, caption and
    description from the SEO title and description; their attachment ids
    live on `_msrwa_{kind}_generated`, never on a key containing "image",
    which the optimizer reads as article content.

22. **An article's state after it leaves the drafts is WordPress's.** The
    Articles screen, the pass and its counters read the post's status; a
    "to review" or "to fix" flag holds only while the post is a draft or
    pending review.

23. **Every job keeps its whole history, written once.** `MSRWA_History`
    records each stage — `provided`, `matching`, `pairing` (the writer's
    corrections), `brief` (the text and visual reference handed to the
    engine), `web_reference`, `engine_brief` (what the research completed),
    one `step` per engine step with its prompt, answer, checks and cost, and
    `result` — as a row in `msrwa_history` and as a numbered file under
    `uploads/msrwa/<run>/history/`. A lot's stages are written with run 0
    and copied into each job's folder at dispatch. Nothing rewrites a stage.
    Rows and files pass through `MSRWA_DB::sanitize()`, follow the artifacts
    retention age (`MSRWA_Retention::history()`), and go with the lot or the
    job.
