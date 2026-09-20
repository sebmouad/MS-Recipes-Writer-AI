# Build checklist — for the model doing the work

Executable version of the agreed specification. Each task states what to build,
which files it touches, how it must be tested, and when it may be marked done.

Read [`ARCHITECTURE.md`](ARCHITECTURE.md) first for the existing code, and
[`../CLAUDE.md`](../CLAUDE.md) for house style and the invariants.

## How to use this file

- Work tasks **in order**. A task assumes everything above it exists.
- **One task per commit.** Message in the imperative, body explaining why.
- Mark a task `[x]` **only** when: its offline tests pass (`php tests/run.php`),
  its real test passed on a real site when credentials exist, the version was
  bumped with a README changelog entry, and the commit is pushed to `main`.
  When credentials do not exist yet, mark it `[~]` — built and covered
  offline, awaiting real verification — and list it under *Awaiting real
  verification* at the bottom.
- Update this file **in the same commit** as the code.
- If a task turns out to be wrong, do not silently change it: write what you
  found under the task and fix the specification in the same commit.

## Test policy

Two layers, both required.

1. **Offline** (`tests/`, runs in CI on every push): pure PHP, no network, no
   database. Protects contracts and regressions. This is the fast gate.
2. **Real** (`tests/real/`, run on demand): a real WordPress site, a real
   database, real provider calls with real keys. A task is only *done* after
   its real test ran green. Real tests read credentials from environment
   variables — never from the repository — and print the amount they spent.

See [`TESTING.md`](TESTING.md) for the harness API, the credential names and
how to run each layer.

---

## Phase 1 — Cost engine

Replaces flat per-stage constants with amounts derived from tokens and prices.

- [~] **T1.1 — Step cost model.** New `MSRWA_Cost`: for each pipeline step,
  compute expected input and output tokens from the configured word counts,
  outline size, prompt lengths and output limits, then price them with the
  catalogue rate of the model that step would use.
  *Files:* `includes/class-msrwa-cost.php`, `ms-recipes-writer-ai.php`.
  *Offline test:* `tests/test-cost.php` — token estimate rises with word count;
  price follows the model rate; an unknown model yields no fabricated price.
  *Done when:* a step's estimate equals tokens × catalogue rate, verifiably.

- [~] **T1.2 — Image price table.** Image APIs bill per image, not per token.
  Add price per (provider, model, size, quality) to the catalogue, with the
  admin able to correct it and a source URL per row.
  *Files:* `includes/class-msrwa-catalog.php`, `includes/class-msrwa-cost.php`,
  settings screen.
  *Offline test:* a 1024×1024 and a 1024×1536 entry price differently; a
  missing entry is reported as unknown, never as zero.

- [~] **T1.3 — Four buckets, min and max.** `MSRWA_Cost::estimate()` returns
  `article`, `featured`, `facebook`, `other`, each with `min` and `max`, plus a
  recipe total. *min* = every step passes first try on its standard model;
  *max* = every step escalates and uses every correction cycle.
  *Offline test:* max ≥ min for every bucket; raising the correction limit
  raises max only; raising the word count raises the article bucket only.

- [ ] **T1.4 — Live recompute in settings.** The settings screen shows the
  eight numbers and updates them when any input changes (word count, outline,
  image sizes, models, search options, correction limit) without reloading.
  *Files:* `includes/class-msrwa-admin.php`, `assets/admin.js`, REST endpoint
  `POST /estimate` returning the estimate for a candidate settings payload.
  *Offline test:* the endpoint returns all four buckets for a posted payload
  and refuses a payload from a user without `manage_options`.
  *Real test:* change the word count in a browser, see the article bucket move.

- [ ] **T1.5 — Budgets reduced to daily and monthly.** Remove the per-recipe
  budget and every cost-driven stop inside a job. When a limit is reached:
  running jobs finish completely, including images; new batches are refused
  with a clear message.
  *Files:* `includes/class-msrwa-db.php` (reservations), `class-msrwa-rest.php`,
  `class-msrwa-settings.php`, `class-msrwa-pipeline.php`.
  *Offline test:* a job over budget still completes its remaining steps; a new
  batch is refused with the right error; no `paused_budget` is ever set.
  *Migration:* keep the column, stop reading it; document in ARCHITECTURE.

## Phase 2 — Model policy and escalation

- [ ] **T2.1 — Policy in settings.** Allowed models per step (writing, review,
  fact check, proofreading, images, vision, search) and a target cost per
  bucket. Admin may pin one model per step.
  *Offline test:* a pinned model wins over automatic choice; a model lacking
  the required capability is refused with a reason.

- [ ] **T2.2 — Frozen plan per recipe.** At creation each job stores the plan
  it will use for every step, and the pipeline reads only that plan, so later
  settings changes cannot alter a running job.
  *Offline test:* changing settings after creation does not change the stored
  plan; the estimate shown on the job matches its plan.

- [ ] **T2.3 — Escalation ladder.** When the structural gate, the AI review,
  the fact check or the proofreading rejects a step, retry it with the next
  model up from the allowed list instead of the same one, bounded by the
  correction limit. Record each escalation as an event.
  *Offline test:* a rejected step selects a stronger model; the ladder stops at
  the correction limit; a step with no stronger model available degrades to a
  retry and says so.
  *Real test:* force a rejection and observe the second call using the stronger
  model, with both calls priced in the job.

## Phase 3 — Settings assistant

- [ ] **T3.1 — Requirement interview.** Before proposing anything, the
  assistant asks the administrator, bucket by bucket: how long and how deep the
  article must be, how good the featured and Facebook images must be, how much
  research and verification is wanted. Answers are stored.
  *Offline test:* the proposal step refuses to run before the four answers
  exist.

- [ ] **T3.2 — Policy proposal.** From those answers plus connected providers,
  capabilities and prices, propose models per step, per-bucket targets, word
  count and correction limit, with the min/max it implies. It is shown as a
  diff against current settings and **never applied automatically**.
  *Offline test:* the proposal only contains connected and capable models;
  applying it writes exactly what was shown.
  *Real test:* run it against real provider catalogues and accept the result.

- [ ] **T3.3 — Inline warnings.** Each setting carries a warning when its
  combination is incoherent or needlessly expensive, for example a word count
  no selected model can produce within its target.
  *Offline test:* one warning per known incoherence, none when coherent.

## Phase 4 — Article contract

- [ ] **T4.1 — Editable enforced outline.** A settings list of required
  sections, enforced by `MSRWA_Quality`: a missing section is a blocking
  finding that sends the article back for correction. Ships with the twelve
  sections in PLAN milestone 4.
  *Offline test:* removing a section from a generated article fails the gate;
  editing the list changes what is enforced.

- [ ] **T4.2 — Two-part writing with continuity.** Part 1 ≈1500 words, part 2
  ≈1300 starting at the preparation, with continuity notes passed from part 1
  so part 2 never repeats or contradicts it. Minimum 2800 words total.
  *Offline test:* the assembled article carries the page break at the
  preparation; word counts per part are checked separately.
  *Real test:* generate one article and read both pages.

- [ ] **T4.3 — Post-write fact check.** Re-read the finished article against
  the research sources; correct wrong or unsupported quantities, times,
  temperatures and claims. **Rewrite only the failing parts.** Sources are not
  printed in the article.
  *Offline test:* a planted wrong quantity is reported; untouched paragraphs
  stay byte-identical.
  *Real test:* run on a real recipe with real search and inspect the diff.

- [ ] **T4.4 — Proofreading pass, always on.** Grammar, spelling and coherence
  between ingredients, steps and times, in the site language, as the last text
  step before images.
  *Offline test:* the pass runs on every article, including one that passed
  every earlier check.

- [ ] **T4.5 — Recipe JSON-LD.** Write schema.org Recipe structured data
  (ingredients, steps, times, servings, nutrition) behind an adapter so a
  recipe plugin can replace it later.
  *Offline test:* the JSON-LD validates against the required-property list and
  contains no HTML.
  *Real test:* Google Rich Results test on a published article.

## Phase 5 — Role separation

- [ ] **T5.1 — Editor surface.** Editors see article, quality verdict,
  publication status, draft link and a plain-language reason when attention is
  needed. No cost, models, tokens, stages, artifacts or diagnostics anywhere,
  including job detail and statistics.
  *Offline test:* render every screen as an editor and assert no cost, model or
  token string appears; assert the same screens as admin still show them.

- [ ] **T5.2 — Plain-language reasons.** Map every internal status and error
  code to a sentence an editor can act on.
  *Offline test:* every status has a mapping; no raw code reaches an editor.

## Phase 6 — Site language

- [ ] **T6.1 — Language setting.** One language per site, chosen by the admin,
  applied to prompts, the quality contract and proofreading. Three shipped.
  *Offline test:* the prompt contract and the gate follow the setting.
  *Real test:* generate one article per shipped language.

---

## Awaiting real verification

Tasks built and covered offline but not yet verified on a real site. Move them
to `[x]` once their real test has run.

- **T1.1 / T1.2 / T1.3** — `MSRWA_Cost` prices every step from tokens times the
  catalogue rate, the catalogue carries editable generated-image token counts
  per size and quality, and the estimate reports four buckets with a minimum
  and a maximum. Offline coverage in `tests/test-cost.php`. Real verification
  needs T1.4 (the settings screen showing the numbers) to be visible.

## Decisions this checklist encodes

Agreed 2026-09-20 with the owner: 2800 words split 1500/1300 · one quality
contract, no modes · editable enforced outline · own meta plus Recipe JSON-LD ·
research, gate, review, fact check, always-on proofreading · four cost buckets
with min/max recomputed live · target cost per bucket · daily and monthly
budgets only · no step ever stopped for cost · policy in settings plus a frozen
per-recipe plan · escalation to a stronger model only on rejection · assistant
interviews per bucket before proposing and never applies by itself · editors
without cost or technical detail · 1024×1024 and 1024×1536 WebP defaults · one
language per site, three shipped.
