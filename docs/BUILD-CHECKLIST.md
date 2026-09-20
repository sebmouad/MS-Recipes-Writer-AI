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

- [ ] **A0 — Baselines.** Run every step on three briefs and write the numbers
  here. Measured on the live site before this phase: research 24s / $0.0244
  (13,475 input tokens), canonical 21s, article 40s for 2,539 words, review 12s.
- [ ] **A1 — Article.** Two parts with continuity, page 2 opening on the
  preparation, 2800 words minimum, every required section present, no metadata
  in the body, question-style H2s, paragraphs of two to four sentences.
  *Gate:* `run article` passes every check on three briefs.
- [ ] **A2 — Canonical recipe.** Valid against `MSRWA_Recipe::validate` on the
  first attempt, no repair call needed, quantities coherent with the steps.
- [ ] **A3 — Research.** Same factual quality for a fraction of the input
  tokens: the 13,475-token call is the most expensive of the pipeline.
  *Gate:* sources still carry URLs, facts still cover times and temperatures.
- [ ] **A4 — Review.** Returns a boolean verdict plus findings that name the
  **section to patch**, never a full rewrite instruction.
- [ ] **A5 — Fact check** (new step). Compares the finished article to the
  research sources and returns only the passages to correct, with the source
  that contradicts them.
- [ ] **A6 — Proofreading** (new step). Grammar, spelling and coherence between
  ingredients, steps and times, returning the corrected text only.
- [ ] **A7 — Images.** Featured and Facebook prompts: appetising, faithful to
  the recipe, no text in the image, no invented dish.
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
