# Build checklist — for the model doing the work

Executable version of the agreed specification, in the order the owner set:
**prove the prompts, then build everything, then deploy and improve on the
live site.**

Read [`ARCHITECTURE.md`](ARCHITECTURE.md) for the existing code and
[`../CLAUDE.md`](../CLAUDE.md) for house style and the invariants.

## How to use this file

- Work phases in order; inside a phase, work tasks in order.
- **One task per commit**, message in the imperative, body explaining why.
- Mark `[x]` only when: offline tests pass (`php tests/run.php`), the real test
  passed when it could run, the version was bumped with a README entry, and the
  commit is pushed to `main`. Use `[~]` for built and covered offline but not
  yet verified live, and list it under *Awaiting real verification*.
- Update this file **in the same commit** as the code.
- If a task proves wrong, write what you found under it and fix the
  specification in the same commit rather than silently changing course.

## Test policy

Three layers, described in [`TESTING.md`](TESTING.md).

1. **Prompt lab** (`tools/prompt-lab.php`) — real API, no WordPress. Proves a
   prompt produces the required result before any engine depends on it.
2. **Offline** (`tests/`) — pure PHP, no network. The fast gate, runs in CI.
3. **Real** (`tests/real/`) — live WordPress over REST with an application
   password. A task is only *done* after its real test ran green.

---

# Phase A — Prompts proven before code

Nothing in phase B is built on an unproven prompt. Each task means: measure the
shipped prompt, write variants that change one thing, keep the one that passes
every check on **at least three different briefs**, then promote it into the
defaults in `includes/class-msrwa-settings.php`, which seed the `prompts` table.

Needs `OPENAI_API_KEY` in the environment. Record the baseline (score, seconds,
cost) in the task before changing anything.

- [x] **A0 — Baselines.** Run every step on three briefs and write the numbers
  here. Measured on the live site before this phase: research 24s / $0.0244
  (13,475 input tokens), canonical 21s, article 40s for 2,539 words, review 12s.
- [~] **A1 — Article.** One model call writes the complete article. It receives
  the canonical recipe and the exact research package used upstream, including
  sourced method facts and bounded observations from real images. *Gate:*
  `run article` passes every check on title-, article- and image-led briefs.
- [~] **A2 — Canonical recipe.** Written in English, `food_safety` added after the schema rejected the first draft. Passes on OpenAI and Claude; Gemini low drops `calories_estimate` and `keywords`. *Original:* **A2 — Canonical recipe.** Valid against `MSRWA_Recipe::validate` on the
  first attempt, no repair call needed, quantities coherent with the steps.
- [~] **A3 — Research.** One reusable package separates ingredient facts,
  preparation facts, safety, references, real-image provenance, visible-only
  observations and uncertainties. *Gate:* every fact is sourced; every visual
  reference carries HTTPS image and source URLs; no image observation infers
  hidden ingredients, quantities or method.

  *Superseded approach, kept for the reasoning (2026-09-20).* A parallel attempt
  had the research model describe the photographs it found from memory, in six
  named facets — colour, surface, texture, plating, garnish, doneness cues — and
  scored 8/8 on both briefs with `gpt-5.6-luna` (44.9s/$0.0152 and
  37.2s/$0.0124), with the article then reaching 10/10 including
  `visual_final_notes`. Downloading the cited image and running vision over the
  real bytes, as this task now does, is strictly stronger evidence: a model
  describing a page it searched can confabulate, and the byte-level pass cannot.
  Two things from the superseded attempt are still worth taking: scoring the
  observations for words that cannot be seen (délicieux, authentique, savoureux
  — an observation is not an advertisement), and asserting in an offline test
  that every downstream step still receives the package, since a step that stops
  receiving it does not fail, it silently invents an appearance.

  *Still open from the original task:* input tokens stay high (45–58k) because
  the web-search results are billed, and Claude high costs $0.19 against OpenAI
  medium's $0.015 for the same scorecard.
- [x] **A15 — Google Recipe structured data completed (owner-approved, 2026-09-20).**
  The audit against the agreed specification found the recipe produced 16 fields
  but not all of what Google reads. `recipe_category` and `description` are now
  produced and validated; `total_minutes` was produced but missing from
  `integration_mapping`, so it never reached the post. Two defects surfaced while
  proving it: `canonical_max_output_tokens` at 2 600 truncated the answer (the
  delivered report's own recipe used 3 260 output tokens), and the "invent
  nothing" rule suppressed staples — a poulet yassa came back with four
  ingredients and no oil or salt. Both fixed; both briefs now pass 4/4 with 7–9
  ingredients and 14–16 steps.
  *Still open:* `recipeInstructions` are plain text rather than `HowToStep`, so
  the per-step rich result is not available; and no `aggregateRating` or `video`,
  which cannot be produced honestly.

- [~] **A16 — Final approval (owner-approved, 2026-09-20).** The fifth agreed
  prompt, and the only one with nothing behind it: the plugin's
  `prompt_image_review` was never a lab step and judged one image at a time, so
  the delivered report signed its visuals off by eye and applied the text
  reviews' corrections by hand. `tools/approval-lab.php` now sends the article
  and both images in one call via `lab_call_judge`, and `lab_score_approval`
  scores the verdict on nine contracts.
  Four calibration defects were found and fixed against real artifacts: realism
  and fidelity shared a rule (an image scored "good" while its own summary named
  an absent ingredient); background styling was then read as an ingredient; a
  documented accompaniment (rice with yassa) was blocked; and everything became
  blocking — 17 blocking findings on one tarte, which is not a gate. Severity is
  now defined explicitly. Both briefs finish at 9/9, ~22s, $0.005, refusing for
  the right reasons with no false positives.
  *Awaiting real verification:* the step exists in the lab and the prompt ships
  in `prompt_final_approval`, but the engine does not call it yet — that is phase
  B. Measured on OpenAI medium only; the other eight cells are unmeasured.

- [~] **A17 — Image quality per image (owner directive, 2026-09-20).** Images were
  74% of the measured $0.1856 per recipe, and a single setting governed both.
  The administrator now sets each, defaulting to `medium`. Measured on
  `gpt-image-2.5-flare` at 1024×1024: `low` 196 tokens / 8.7s / $0.0104,
  `medium` 439 / 10.1s / $0.0177, `high` 1756 / 18.0s / $0.0572. The delivered
  report ran `high` ($0.137 for the pair); `medium` costs $0.038 for the pair.
  The estimate now prices each image at its own quality — it previously applied
  one value to both, so it was wrong whenever they differed — and the lab reads
  the shipped value instead of a hard-coded `medium`.
  *Not yet decided:* whether `medium` output is editorially equal to `high`.
  Both were generated and look strong, but nothing has judged them against each
  other — the approval step (A16) can now do exactly that.

- [ ] **A18 — The six-panel collage is unreliable in one generation
  (measured 2026-09-20).** Eight real generations judged by A16: `medium` 0/3
  approved, `high` 1/2, `medium` with a corrected prompt 1/3. Failures are the
  same two kinds at both tiers — panels out of the recipe's order, and a serving
  vessel appearing nowhere else. Quality tier is not the lever: `high` costs 2.5×
  and still failed half its runs. The featured image, by contrast, is `good` at
  every tier including `low`.
  Part of the cause was ours: the generic panel roles put "whisking, mixing" at
  panel 2, which for a tart places the custard before the case is lined. Roles
  are now subordinate to the canonical step order and panel 6 must use the
  observed serving vessel; that moved `medium` from 0/3 to 1/3.
  *Decision needed from the owner before phase B:* retry until approved (about
  $0.077 per accepted collage at a 1-in-3 rate) or generate six panels separately
  and compose the grid locally ($0.062 at `low`, $0.106 at `medium`, order
  guaranteed by construction, each panel able to carry the previous as a
  continuity reference). The second is an architecture change, so it is not made
  unilaterally.

- [~] **A19 — Research feeds every step, distilled (owner directive, 2026-09-21).**
  What research read and what it observed in real photographs now reaches the
  canonical recipe, the article, both images and the final approval — as a
  derived brief rather than raw JSON. `lab_visual_brief()` turns the recipe and
  the observations into constraints a model can obey: exact counts from the
  quantities (bounded to the mise en place), the cookware the recipe names, the
  scale and doneness, the observed appearance, and one named serving
  presentation shared by both images.
  Measured on the tarte over eight collages and four pairs: panels out of order
  went from 3 refusals in 5 to none; the vessel mismatch between the featured
  photograph and the collage's last panel went from near-systematic to none.
  Two lessons worth keeping: describing the presentation was not enough — two
  calls both reading "whole, seen at three quarters" still chose a plate and a
  tin, so the vessel has to be *named*; and our own prompt was fighting the
  brief, since "APPETITE HERO at peak texture" pushed panel 6 browner than the
  observations allowed.
  Ingredient counting is now minor rather than blocking: a reader takes
  quantities from the list, and image models do not count reliably.
  *Blocked on a fixture, not on the prompts.* Both fixtures' `visual_observations`
  are placeholders citing `example.test`; no real photograph was ever analysed
  for them. The colour refusals that remain are enforcing a one-line invented
  reference. Re-run research with real image scraping on both fixtures before
  reading any further colour result.
- [ ] **A20 — The fixtures carry invented observations.** `tarte-pommes` and
  `poulet-yassa` cite `https://example.test/...`. Every measurement that depends
  on observed appearance is therefore measuring a stub. Re-run `research` with
  `lab_enrich_research_images` against real sources and store the result.

- [~] **A4 — Review.** Written in English; findings must name the section to patch. *Original:* **A4 — Review.** Returns a boolean verdict plus findings that name the
  **section to patch**, never a full rewrite instruction.
- [~] **A5 — Fact check** (new step). Written; scored on whether it quotes the article verbatim rather than inventing a sentence to correct. *Original:* **A5 — Fact check** (new step). Compares the finished article to the
  research sources and returns only the passages to correct, with the source
  that contradicts them.
- [~] **A6 — Proofreading** (new step). Written; scored on structure preserved, page break kept and every figure untouched. *Original:* **A6 — Proofreading** (new step). Grammar, spelling and coherence between
  ingredients, steps and times, returning the corrected text only.
- [~] **A7 — Images.** Featured and Facebook prompts consume the shared
  research package directly. Real photographs guide only visible appearance;
  the canonical recipe controls identity, ingredients and steps. References
  are never copied or reused as assets. Awaiting live visual validation.
- [x] **A11 — Model answers that do not parse (found 2026-09-20).** Two of the
  54 matrix cells scored zero for a reason that was ours, not the prompt's:
  Opus 5 wrote "I'll research this dish now." before the object, and Sonnet 5
  left a raw newline inside an 18 KB `content_html` string. `MSRWA_Json` repairs
  both and still fails on a truncated answer; `tests/test-json.php` carries the
  captured payloads. Re-ran research on Claude high: 0/5 → **5/5**, 42s, $0.1875.
- [x] **A12 — The output ceiling was below the word target (found 2026-09-20).**
  `article_max_output_tokens` shipped at 8 000 while the quality gate demands
  2 800 words. Measured over fifteen real article answers: 1.88 tokens per word
  at best, 3.97 on a model billing its reasoning. Every truncation was billed in
  full and scored zero — $0.086 on one article call, $0.164 on a proofreading
  call. The ceiling now derives from the word target
  (`MSRWA_Cost::output_budget`), the cost estimate uses the same measured band
  instead of 1.6 tokens per word, and `quality_max_words` was raised from 2 400,
  which was below the 2 800 minimum it was meant to cap.

- [x] **A13 — A truncated answer was stored as an empty one (found 2026-09-20).**
  An answer stopped at `max_tokens` ends mid-character; that one broken UTF-8
  sequence makes `json_encode` return false for the whole string. Sonnet 5 billed
  14 500 output tokens on the article step and the saved answer was zero bytes,
  which reads as "the model returned nothing" when it returned an article.
  `MSRWA_Json::valid_utf8` scrubs the tail before reading and before storing.
- [ ] **A14 — Sonnet 5 exceeds any article ceiling we have tried.** 8 000 then
  14 500 output tokens, both `max_tokens`, 148s and $0.15 on the second. Extended
  thinking is ruled out — a control call returns `thinking_tokens: 0`, so the
  default is off. The model is simply writing far longer than the 2 800-word
  brief asks. Decide before phase B: cap Claude's article role to Haiku 4.5,
  which passed 8/10 at $0.043, or add an explicit upper word bound to the prompt
  and re-measure. Not a blocker for OpenAI or Gemini, which both complete.

- [ ] **A9 — Prompt templates (owner directive, 2026-09-20).** Prompts are
  templates compiled from the settings by `MSRWA_Prompt`, used by the lab and
  the engine alike: word count, one or two pages, page-two opening heading,
  editable outline, image sizes and format, language, FAQ count, internal link
  limit. Done for the article and both image prompts; the remaining steps are
  still plain English text and must be converted before phase B.
- [x] **A10 — Recipe fields stay separate.** The canonical recipe remains its
  own call; the article is a single subsequent call using the canonical recipe
  and the same research package.
- [ ] **A8 — Promote.** Winners written into the defaults, version bumped,
  `tools/runs/` summary of before and after in the commit body.

---

# Phase B — Build the complete first version

## B1 — Speed: the runner (do this first)

Measured on the live site: 293s of provider work inside a 10-minute wall clock,
14 worker runs for one recipe, and the article generated four times.

- [ ] **B1.1 — One-pass runner.** A worker runs a job from intake to draft in a
  single execution under a time budget, re-scheduling only when the budget runs
  out. Removes the per-stage cron ping-pong.
  *Offline test:* one invocation advances a job through every stage; a job that
  exceeds the budget re-schedules exactly once and resumes where it stopped.
- [ ] **B1.2 — Patch, do not regenerate.** A correction rewrites only the
  sections the review named. *Offline test:* untouched sections stay
  byte-identical; the corrected section changes.
- [ ] **B1.3 — Deterministic routing by default.** The agentic router becomes
  opt-in, so no model call happens before real work.
  *Offline test:* with the setting off, no routing call is recorded.
- [ ] **B1.4 — Parallel calls.** Independent provider calls run concurrently
  (`curl_multi`): featured and Facebook images together, image generation
  overlapping the review. *Offline test:* the transport issues one multi
  request for a batch of two, and a failure in one does not cancel the other.
- [ ] **B1.5 — Target.** Article plus featured image in **90 seconds or less**
  for one recipe. *Real test:* `tests/real/test-generation.php` asserts it.

## B2 — Cost engine (started)

- [~] **T1.1 / T1.2 / T1.3** — `MSRWA_Cost` prices every step from tokens times
  the catalogue rate; the catalogue carries editable generated-image token
  counts per size and quality; the estimate reports four buckets with min and
  max. Offline coverage in `tests/test-cost.php`.
- [ ] **T1.4 — Live recompute in settings.** The eight numbers on screen,
  updated without reloading through `POST /estimate`, refused to a user without
  `manage_options`.
- [ ] **T1.5 — Budgets reduced to daily and monthly.** No per-recipe budget, no
  cost-driven stop inside a job: running jobs finish, new batches are refused.

## B3 — Model policy and escalation

- [ ] **T2.1 — Policy in settings.** Allowed models per step, target cost per
  bucket, admin may pin a model per step.
- [ ] **T2.2 — Frozen plan per recipe.** Later settings changes cannot alter a
  running job.
- [ ] **T2.3 — Escalation ladder.** A rejected step retries with the next model
  up, bounded by the correction limit, each escalation recorded as an event.

## B4 — Settings assistant

- [ ] **T3.1 — Requirement interview**, bucket by bucket, before proposing.
- [ ] **T3.2 — Policy proposal** from connected providers and prices, shown as
  a diff, never applied automatically.
- [ ] **T3.3 — Inline warnings** on incoherent or needlessly expensive settings.

## B5 — Article contract

- [ ] **T4.1 — Editable enforced outline**, a missing section blocking delivery.
- [ ] **T4.2 — Two-part writing with continuity**, 2800 words minimum.
- [ ] **T4.3 — Post-write fact check** correcting only the failing parts.
- [ ] **T4.4 — Proofreading pass**, always on, last text step.
- [ ] **T4.5 — Recipe JSON-LD** behind an adapter.

## B6 — Roles and surface

- [ ] **T5.1 — Editor surface** with no cost, models, tokens, stages or
  diagnostics, on every screen including statistics and job detail.
- [ ] **T5.2 — Plain-language reasons** for every internal status and error.

## B7 — Site language

- [ ] **T6.1 — One language per site**, applied to prompts, the quality
  contract and proofreading. Three shipped.

## B8 — First complete version sweep

- [ ] **B8.1 — Menus and pages.** Every screen reachable, titled, with an empty
  state and a back link; no orphan page.
- [ ] **B8.2 — Security pass.** Capability check and nonce on every action,
  escaping on every output, prepared statements everywhere, no secret in stored
  data, plugin API responses uncacheable. *Offline test per rule.*
- [ ] **B8.3 — Access management.** Roles and capabilities documented and
  enforced: who may create, read own, read all, manage.
- [ ] **B8.4 — Uninstall and migration.** Activation, upgrade and uninstall
  verified on a site holding data.
- [ ] **B8.5 — Release.** Version, README changelog, architecture and plan
  updated; `php tests/run.php` green.

---

# Phase C — Deploy, test, improve on the live site

- [ ] **C1 — Deployment path.** SFTP, SSH or a server-side pull, so a build
  reaches the site without a manual ZIP upload.
- [ ] **C2 — Real suite.** `php tests/real/run.php` green, including generation.
- [ ] **C3 — Performance verified live:** article plus featured image ≤ 90s.
- [ ] **C4 — Quality verified live** against the benchmark: complete sections,
  faithful to sources, no writing mistakes, cost inside the estimate.

---

## Awaiting real verification

- **T1.1 / T1.2 / T1.3** — cost engine. Real verification needs T1.4 so the
  numbers are visible on screen.

## Decisions this checklist encodes

Agreed 2026-09-20: 2800 words split 1500/1300 · one quality contract, no modes ·
editable enforced outline · own meta plus Recipe JSON-LD · research, gate,
review, fact check, always-on proofreading · four cost buckets with min/max
recomputed live · target cost per bucket · daily and monthly budgets only · no
step ever stopped for cost · policy in settings plus a frozen per-recipe plan ·
escalation to a stronger model only on rejection · assistant interviews per
bucket before proposing · editors without cost or technical detail · 1024×1024
and 1024×1536 WebP defaults · one language per site, three shipped.
