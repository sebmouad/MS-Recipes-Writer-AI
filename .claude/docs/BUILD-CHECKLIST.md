# Build checklist — for the model doing the work

## Verified on a real site — 0.11.0, 2026-09-22

WordPress 7.1.1 with the SQLite database integration (the only database the
test sandbox could run; a MySQL pass is still owed), the plugin activated, lots
driven over REST with an application password and carried to a draft by
WP-Cron.

**No provider can complete a lot from this sandbox any more**, and each is
blocked for a different reason, all three established rather than guessed:
`api.openai.com` is refused by the sandbox's network policy (`CONNECT` answers
403); the Anthropic account has no credit (`HTTP 400`, "Your credit balance is
too low"); the Gemini free-tier quota is spent (`HTTP 429`, "You exceeded your
current quota"). So the steps below that need a model were verified on the last
run that could reach one, and everything after the draft was verified by
driving `MSRWA_Draft::create()` on the live site with the artifacts a finished
run carries.

- [x] `tests/real/test-site.php` green: schema, capabilities, cron, routes
  closed to the public, key check, and a lot over its ceiling refused for free.
- [x] `tests/real/test-flow.php` green: one French article-only lot, 294 s,
  $0.2570 billed against $0.2485 estimated, draft created.
- [x] One English lot by hand: written in English, 5 988 words over two pages,
  $0.2422, recipe meta mapped, JSON-LD printed once published.
- [x] Every screen opened in a real browser as administrator and as author, at
  1400 px and 390 px: no horizontal overflow, no script error, no amount shown
  to the author.
- [x] The draft opened in the real block editor: 14 blocks, **0 invalid, 0
  Classic blocks, no "unexpected or invalid content" warning**, accents and
  ligatures intact, page break where the article put it.
- [x] Attachments on a real draft: featured and Facebook images sideloaded,
  both `post_parent` the draft, both with alternative text, both with their
  sizes generated and their file on disk; the featured one set as the thumbnail.
- [x] Slug, excerpt, tags, category and the SEO meta on a real draft, read back
  through the block editor's own store.
- [x] The published page's head: meta description, Open Graph, X card and the
  Recipe JSON-LD, with the sharing image at 1200 × 630; nothing printed on a
  post this plugin did not write, and nothing printed with the setting off.
- [x] The key check and the Diagnostic screen naming a real provider refusal:
  `claude` out of credit, `gemini` out of quota, read back from the failures
  those accounts actually returned.
- [x] The model list each provider actually serves, fetched live and read back:
  Gemini returned 59 identifiers and Anthropic 12, and comparing them to the
  engine's `tiers` map found `claude:low` naming `claude-haiku-4-5`, which
  Anthropic does not serve. Proposed in ENGINE.md §7; the Diagnostic screen
  turns the routing red for it.
- [ ] Image steps, the final judge and the matcher live — need a reachable and
  funded OpenAI or Gemini key.
- [ ] The same suite on MySQL.

Executable version of the agreed specification, in the order the owner set:
**prove the prompts, then build everything, then deploy and improve on the
live site.**

Read [`ARCHITECTURE.md`](ARCHITECTURE.md) for the existing code and
[`../../CLAUDE.md`](../../CLAUDE.md) for house style and the invariants.

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

1. **Lab** (`tools/lab.php`) — the engine against a real API, no WordPress.
   Proves a prompt produces the required result before anything depends on it.
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
  **Retry until approved now runs in the engine, and was measured 2026-09-21**
  on the poulet yassa. The first verdict refused both images — peppers and a
  lemon slice in the featured photograph that the recipe does not contain, three
  bay leaves where it calls for one. Both were regenerated with those findings
  carried into the prompt as corrections, and the second verdict **approved**,
  leaving only minor findings about presentation. One refusal, one redraw, one
  approval: $0.1414 and 78 s for the loop, against $0.0075 for a verdict that
  approves first time. The invented ingredients did not come back, which is the
  part that matters: a correction is not another roll of the dice.

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
- [x] **A20 — The fixtures carried invented observations (closed 2026-09-21).**
  Four of five cited `example.test`, so every appearance measurement taken from
  them was measuring a stub. All five re-run against real sources with 1 to 3
  photographs downloaded and analysed. Found on the way: Food Network's CDN
  returns 403 to our fetch, and a single cited reference left us with no
  observation at all, so research now cites three photographs across three
  different domains. The refusal itself is respected, not worked around.
- [x] **A9 — Prompt templates (closed 2026-09-21).** All nine prompts are
  templates compiled from the settings; none hardcodes what a setting controls.
  The output language came from five prompts saying "French" and now comes from
  `site_language`. `tools/promote-prompts.php` compiles them into the shipped
  defaults and `tests/test-prompt-templates.php` asserts the defaults equal the
  compiled templates, so the plugin cannot run a prompt the lab never measured.
- [ ] *(superseded)* **A20 — The fixtures carry invented observations.** `tarte-pommes` and
  `poulet-yassa` cite `https://example.test/...`. Every measurement that depends
  on observed appearance is therefore measuring a stub. Re-run `research` with
  `lab_enrich_research_images` against real sources and store the result.

- [x] **A21 — First complete run on a new recipe, with real photographs
  (2026-09-21).** Souris d'agneau au four. Research fetched and analysed two real
  photographs from greatbritishchefs.com, which drove the recipe, the article and
  both images. Article 3 413 words at 98/100; final approval **approved** at
  10/10. Total about 0,13 $ and roughly three and a half minutes of provider time.
  Three defects found on the way: a malformed `editor_input` was forwarded as-is
  and research truthfully answered that it had been given no dish; the research
  scorecard passed a package with zero facts and zero sources; a regenerated
  image never wrote its cost.
- [x] **A22 — The judge applies two standards (owner directive, 2026-09-21).**
  *Images:* realism is the primary check, only the principal ingredients decide
  whether it is the right dish, and secondary detail, counts, garnish and
  crockery are minor — doubt resolves to minor. Four earlier calibrations had
  each over-reached. *Recipe and article:* any ingredient, step, technique or
  figure the research does not support is blocking, as is anything contradicting
  it — doubt resolves to blocking, because the reader cooks from the text.
  Neither trades against the other.
  Measured on the same run: the images fell to two minor findings while the
  article drew seven blocking ones — substitutions, accompaniments and buying
  criteria no source documents.
- [x] **A24 — The outline asked for content the strict rule forbids (closed
  2026-09-21).** Research now gathers documented substitutions and
  accompaniments, and the article may offer only what a fact names — where none
  is documented it says so plainly instead of inventing a plausible swap. Same
  rule for method: no step, placement or separate operation beyond the recipe
  and the research. Blocking findings on the article went from 5 to 0.
- [x] **A25 — Prompt trimming (owner directive, 2026-09-21).** The whole research
  package was being sent to the image models, which pushed the collage past the
  provider's 32 000-character limit and failed with HTTP 400. Images now receive
  only the dish identity and the visual observations; text steps lose
  `originality_notes`, `visual_references` and `visual_observations`, since the
  visual brief carries appearance already. Featured 0.0427 → 0.0266 $, collage
  0.0443 → 0.0326 $, about 27% where input tokens bill at $5/M. A guard now fails
  an oversized prompt locally instead of at the provider.
- [x] **A26 — Truncation is now visible.** A fourth ceiling silently ate a result:
  `research_max_output_tokens` at 4 000 against the 5 567 the package needed. The
  lab prints `!! TRUNCATED` whenever an answer stops exactly on its ceiling.
- [ ] *(superseded)* **A24 — The outline asks for content the strict rule forbids.** The article
  plan requires a *substitutions* section, and every substitution the research
  does not document is now blocking. Research has been asked to gather
  substitutions and accompaniments; re-measure whether that clears the seven
  findings, and if it does not, the outline must say that an undocumented
  section is omitted rather than invented.
- [x] **A23 — Retention and cost history (owner directive, 2026-09-21).**
  `tools/prune-runs.php` keeps the last article, the last recipe and the last
  featured image per brief, and every Facebook collage, since the collage is the
  piece still being tuned. Every cost is appended to `tools/cost-history.jsonl`
  before anything is removed: 152 calls, $5.18 measured. Generated reports left
  the repository — 8.8 MB of encoded images — and `tools/reports/` is ignored.

- [x] **A27 — Absence and invention judged oppositely (owner directive,
  2026-09-21).** Only a principal ingredient may be reported missing; any other
  may be invisible without comment, since it can be dissolved, buried, absorbed
  or out of frame. Anything edible that is visible and absent from the canonical
  list is blocking however small, and for additions doubt resolves to blocking —
  the reverse of the rest of the review. Measured on two dishes: tomato on the
  lamb (blocked, corrected, approved in one retry, $0.0721) and a dozen
  carrot-like pieces on the yassa against a recipe holding one habanero
  (blocked, corrected to a single whole habanero, approved in one retry,
  $0.0734).
- [x] **A28 — Judge stability, measured (closed 2026-09-21).** `gpt-5.6-luna`
  closes the root object early and leaves the image verdicts outside it, which
  read as "refused with no findings" and triggered an image regeneration against
  a decision nobody made. The lab now re-asks for the judgement without touching
  the images, and the scorecard fails such an answer on every contract. Seen in
  roughly one call in three; a merge-the-objects parser was tried and reverted
  because the answer is not recoverable — the root closes before the rest exists.
  Worth re-measuring on another model before phase B.

- [x] **A29 — The judge had two systematic false positives (found 2026-09-21).**
  Measuring the same artifacts five times showed it approving only 2 of 5. Both
  refusal reasons were wrong: fine green specks called parsley when thyme,
  rosemary and bay are in that recipe's list, and a glass of wine beside the
  plate read as an invented ingredient. The review now judges only what is on or
  in the dish — a drink, a bottle, a bowl alongside, cutlery are never
  ingredients — and must look for a plausible match in the list before calling
  anything added, since chopped herbs are indistinguishable at this resolution.
  Agreement went from 2/5 to 4/4.
  *Measured judges, same prompt and artifacts:* `gpt-5.6-luna` 4/5 usable and
  4/4 consistent at $0.0061; `claude-sonnet-5` 3/3 and 3/3 at $0.153;
  `gemini-3.5-flash` 3/5 and 2/3 at $0.017, and it counts objects despite the
  rule against it. OpenAI medium is the judge; Claude is worth a second opinion
  on a contested decision, at 25 times the price.
  *Also raised:* `approval_max_output_tokens` 6 000 → 14 000, the fifth ceiling
  in this project set below what the step needs. `tools/judge-stability.php`
  exists so this is re-measurable rather than re-argued.

- [x] **A30 — Research carries the recipe, and images are tiered (owner
  directive, 2026-09-21).** The package now returns what the sources say the
  recipe is — outline, ingredients with role and essential flag, numbered steps
  each with the visible cue that ends it, substitutions, accompaniments, common
  failures, storage — every line with its source. Images come in two tiers: real
  photographs of this dish (three, across three domains) and, as a fallback for
  visual direction only, up to two of a close variant, each marked with its tier.
  Measured 14/14 on the lamb: 10 ingredients all sourced, 10 steps all cued,
  3 tier-1 references. `research_max_output_tokens` 7 000 → 12 000.
- [x] **A31 — Exchange format and caching, measured (2026-09-21).**
  `tools/format-cost.php` reads the provider's own input_tokens for several
  renderings of one package. Pipe-delimited records beat compact JSON by 1.6%,
  indented lines are worse than JSON, and pretty JSON costs 13% more — a saving
  already taken. **Changing format is not worth the loss of a format everyone can
  read.**
  Caching is the opposite: an identical prompt replayed caches 6 825 of 6 828
  input tokens, 99.96%. Two recipes sharing a 1 622-token template prefix cached
  nothing, so the win is on repeats, not across recipes. That makes every retry
  path we built — the malformed-verdict re-ask, regeneration after a refusal —
  nearly free on input, and it is why retry-until-approved costs less than the
  per-call price suggests.
  *Not pursued:* reordering prompts to grow a shared prefix. The measurement says
  cross-recipe caching did not fire at 1 622 tokens, so there is nothing yet to
  optimise towards; re-measure if prompts grow.

- [x] **A32 — Merging research and the canonical recipe: measured and rejected
  (2026-09-21).** `research_recipe.tpl.txt` does both jobs in one call and works
  — 17/17 on the yassa, valid recipe, 10 ingredients, 12 steps. It just buys
  nothing. Split: 49.5s + 21.8s = 71.3s for $0.0183 + $0.0047 = $0.0230. Merged:
  84.5s for $0.0227. A 1.3% saving, inside the noise, for 13 seconds more,
  because one call's 8 635 output tokens generate sequentially where two calls
  generate 6 943 then 3 162.
  Three reasons beyond the numbers: a recipe the validator rejects costs $0.0047
  to retry today and would cost $0.0227 merged, five times more; research records
  what sources say including their disagreements while the recipe decides, and
  one call doing both may resolve a conflict silently instead of recording it;
  and since identical replays cache at 99.96%, a bigger replayed call pays more
  of the uncached remainder. The template stays in the repo, like `article_full`
  before it. **Removed from the repository on the owner's decision, 2026-09-21;
  the measurement above is the record.**
- [x] **A33 — `recipe_outline` left holes (closed 2026-09-21).** Research returned
  2 of its 4 figures on the yassa. Every key is now always present, the model
  must look for each figure before giving up on it, and `null` is only for a
  figure actually searched for and not found. Verified on both dishes: yassa
  14/14 with `cook_minutes` honestly null, lamb 14/14 with all eight keys and all
  four figures.

- [~] **A34 — The lab becomes the engine (owner directive, 2026-09-21).** Stage
  one: orchestration moves from `tools/` to `includes/engine/`, loaded by the
  plugin and the lab alike, touching no WordPress function. The plugin passes
  settings in and takes a `MSRWA_Result` back.
  `MSRWA_Result` is the envelope — ok, artifacts, steps, totals, errors, events —
  and doubles as the live progress report, with an observer called per event.
  Failure is a value: a missing key, an unknown model or a provider error all
  come back as data, and the nine `exit()` calls the lab could afford are gone.
  `MSRWA_Engine_Steps` declares what each step needs, which gives the run order
  and the parallelism: research, then the recipe, then **article with both
  images**, then **the three reviews**, then approval. Estimated critical path
  ~186s against ~250s serial.
  Found while drawing the graph: approval depended on the raw article, so it
  judged text a reader never sees. It now depends on the proofread article.
  Stage two: step inputs, scoring and the prompts moved into the engine.
  Stage three: `MSRWA_Engine::run()` and `run_step()`, the wave scheduler, the
  budget stop, and four call paths chosen by the capability a step declares —
  text, image generation, the judge that reads both images' bytes, and none at
  all. The eight CLI tools became `tools/lab.php`; the HTML report reads one
  `MSRWA_Result` rather than thirteen file paths.
  Removing the tools exposed two gaps in the engine: inspecting the cited
  photographs and the editor's own images existed only in `prompt-lab.php`, so a
  plugin-driven run would have produced research with no visual observations at
  all. Both passes are in the engine, billed to research.
  **Measured end to end 2026-09-21** on the poulet yassa: ten steps, 346.7 s,
  $0.1274 — text $0.0370, featured $0.0278, collage $0.0350, research and checks
  $0.0277. Research 14/14 with two photographs read from their bytes, recipe
  4/4, article 10/10, review 3/3, fact check 5/5, corrections 2/2, language 6/6.
  That run also found the approval step was being sent no article at all; see
  version 0.2.74. Contract in [`ENGINE.md`](ENGINE.md).
  *Remaining:* the plugin — access, interface, parameter translation, storage.

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
- [x] **A14 — Closed 2026-09-21, and the original diagnosis was wrong.** 8 000 then
  14 500 output tokens, both `max_tokens`, 148s and $0.15 on the second. Extended
  thinking is ruled out — a control call returns `thinking_tokens: 0`, so the
  default is off. The model is simply writing far longer than the 2 800-word
  brief asks. Decide before phase B: cap Claude's article role to Haiku 4.5,
  which passed 8/10 at $0.043, or add an explicit upper word bound to the prompt
  and re-measure. Not a blocker for OpenAI or Gemini, which both complete.

- [x] **A9 — Prompt templates (owner directive, 2026-09-20; closed 2026-09-21).** Prompts are
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

- [x] *(superseded by the engine's waves, verified live: one recipe, one run, 294 s, no step generated twice)* **B1.1 — One-pass runner.** A worker runs a job from intake to draft in a
  single execution under a time budget, re-scheduling only when the budget runs
  out. Removes the per-stage cron ping-pong.
  *Offline test:* one invocation advances a job through every stage; a job that
  exceeds the budget re-schedules exactly once and resumes where it stopped.
- [x] *(superseded: the `corrections` step substitutes the fact check's quotes in code, verified live)* **B1.2 — Patch, do not regenerate.** A correction rewrites only the
  sections the review named. *Offline test:* untouched sections stay
  byte-identical; the corrected section changes.
- [x] *(superseded: routing is a setting, no router call exists)* **B1.3 — Deterministic routing by default.** The agentic router becomes
  opt-in, so no model call happens before real work.
  *Offline test:* with the setting off, no routing call is recorded.
- [x] *(engine `limits.concurrency`, verified live: review and fact check ran as one wave)* **B1.4 — Parallel calls.** Independent provider calls run concurrently
  (`curl_multi`): featured and Facebook images together, image generation
  overlapping the review. *Offline test:* the transport issues one multi
  request for a batch of two, and a failure in one does not cancel the other.
- [ ] *(not met: an article-only recipe takes ~290 s on Claude Haiku; image steps not yet measured live)* **B1.5 — Target.** Article plus featured image in **90 seconds or less**
  for one recipe. *Real test:* `tests/real/test-generation.php` asserts it.

## B2 — Cost engine (started)

- [~] **T1.1 / T1.2 / T1.3** — `MSRWA_Cost` prices every step from tokens times
  the catalogue rate; the catalogue carries editable generated-image token
  counts per size and quality; the estimate reports four buckets with min and
  max. Offline coverage in `tests/test-cost.php`.
- [~] *(the compose screen recomputes live as the lot, profile and ceiling change, and warns before a lot over its ceiling is sent; the settings screen shows the full-recipe estimate beside the ceiling. The eight-number grid is not built.)* **T1.4 — Live recompute in settings.** The eight numbers on screen,
  updated without reloading through `POST /estimate`, refused to a user without
  `manage_options`.
- [~] **T1.5 — Budgets reduced to daily and monthly.** `MSRWA_Budget` reads two
  ceilings from the settings, counts what the whole site has spent over one day
  and over thirty, and refuses. A new lot is refused against its estimate before
  it starts; a recipe already running is parked back in the queue before its next
  wave rather than failed, so nothing it has produced is lost and it resumes on
  its own once there is room. A ceiling of zero is no ceiling. Offline coverage
  in `tests/test-budget-and-queue.php`.

## B3 — Model policy and escalation

- [~] *(admin may pin a model per step, from a friendly picker over the same
  routing an admin could already hand-edit as JSON; a live preview resolves
  the unsaved form through `MSRWA_Operations::preview()` before anyone saves
  or spends. Allowed-models restriction and target cost per bucket are not
  built.)* **T2.1 — Policy in settings.** Allowed models per step, target cost
  per bucket, admin may pin a model per step.
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

- [~] *(enforced by the engine's fixed section list; making it editable needs the engine change proposed in ENGINE.md §7)* **T4.1 — Editable enforced outline**, a missing section blocking delivery.
- [x] *(verified live once the site's settings reached the engine in 0.8.0)* **T4.2 — Two-part writing with continuity**, 2800 words minimum.
- [x] **T4.3 — Post-write fact check** correcting only the failing parts.
- [x] **T4.4 — Proofreading pass**, always on, last text step.
- [x] **T4.5 — Recipe JSON-LD** behind an adapter. `MSRWA_Schema`, silent when a recipe plugin prints its own; `tests/test-schema.php`, verified on a published post.

## B6 — Roles and surface

- [x] **T5.1 — Editor surface** with no cost, models, tokens, stages or
  diagnostics, on every screen including statistics and job detail.
- [x] *(`MSRWA_UI::reason()`, verified on the recipe screen as an author)* **T5.2 — Plain-language reasons** for every internal status and error.

## B7 — Site language

- [~] *(site language and per-lot language reach the prompts; English verified live, Arabic offline only)* **T6.1 — One language per site**, applied to prompts, the quality
  contract and proofreading. Three shipped.

## B8 — First complete version sweep

- [x] **B8.1 — Menus and pages.** Every screen reachable, titled, with an empty
  state and a back link; no orphan page.
- [~] **B8.2 — Security pass.** Capability check and nonce on every action,
  escaping on every output, prepared statements everywhere, no secret in stored
  data, plugin API responses uncacheable. *Offline test per rule.*
- [x] **B8.3 — Access management.** Roles and capabilities documented and
  enforced: who may create, read own, read all, manage.
- [~] *(activation and the 0.7.8 → 0.8.0 upgrade verified live; uninstall offline only)* **B8.4 — Uninstall and migration.** Activation, upgrade and uninstall
  verified on a site holding data.
- [x] **B8.5 — Release.** Version, README changelog, architecture and plan
  updated; `php tests/run.php` green.

---

# Phase C — Deploy, test, improve on the live site

- [ ] **C1 — Deployment path.** SFTP, SSH or a server-side pull, so a build
  reaches the site without a manual ZIP upload.
- [~] *(green on a local site, not yet on the production host)* **C2 — Real suite.** `php tests/real/run.php` green, including generation.
- [ ] **C3 — Performance verified live:** article plus featured image ≤ 90s.
- [ ] **C4 — Quality verified live** against the benchmark: complete sections,
  faithful to sources, no writing mistakes, cost inside the estimate.

---

## Awaiting real verification

- **T1.1 / T1.2 / T1.3** — cost engine. The article-only estimate landed within
  4 % of the bill on a real run; the image buckets need an image provider
  reachable from the test machine.
- **T6.1** — an Arabic lot, live.
- **B1.5 / C3** — timing with images.
- Everything under *Verified on a real site* that is still unticked.

## Decisions this checklist encodes

Amended 2026-09-22: *no step ever stopped for cost* now reads **no work ever
lost to cost**. A recipe that reaches a ceiling mid-lot is put back in the queue
before its next wave, keeping every step it finished, and starts again on its
own once there is room. Nothing is killed in flight and nothing is marked
failed, but the site no longer spends past its ceiling to finish a lot that was
cheap when it was dispatched.

Agreed 2026-09-20: 2800 words split 1500/1300 · one quality contract, no modes ·
editable enforced outline · own meta plus Recipe JSON-LD · research, gate,
review, fact check, always-on proofreading · four cost buckets with min/max
recomputed live · target cost per bucket · daily and monthly budgets only · no
step ever stopped for cost · policy in settings plus a frozen per-recipe plan ·
escalation to a stronger model only on rejection · assistant interviews per
bucket before proposing · editors without cost or technical detail · 1024×1024
and 1024×1536 WebP defaults · one language per site, three shipped.
