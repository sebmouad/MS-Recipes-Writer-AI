# The engine

`includes/engine/` makes a recipe. It touches no WordPress function, reads
nothing off disk but its own prompts, and never exits. You give it a brief; it
gives you a `MSRWA_Result`.

Two callers use it and they have opposite needs. The plugin must never be
killed mid-request by a bad setting or a provider outage. The lab wants
everything that happened written down. Both are served by the same rule: **a
failure is a value, not an exception.**

---

## 1 · Structure

| File | Responsibility | Lines |
|---|---|---:|
| `class-msrwa-engine.php` | The entry point: `run()`, `run_step()`, waves, retries | 463 |
| `class-msrwa-engine-config.php` | Three layers of settings, clamped and recorded | 161 |
| `class-msrwa-engine-steps.php` | What each step needs, produces, costs, and asks a model for | 113 |
| `class-msrwa-engine-input.php` | What a step is given before it runs | 354 |
| `class-msrwa-engine-score.php` | Whether an answer satisfied its step's contract | 278 |
| `class-msrwa-engine-call.php` | Every provider call, normalized across three providers | 288 |
| `class-msrwa-result.php` | What comes back, and the progress report while it runs | 107 |
| `prompts/*.tpl.txt` | The nine prompts. Engine data: it runs them, it carries them | — |
| `load.php` | Loads the shared plugin classes, then the engine | — |

`MSRWA_Prompt`, `MSRWA_Quality`, `MSRWA_Recipe`, `MSRWA_Images`, `MSRWA_Json`
and `MSRWA_Cost` are shared with the plugin and loaded by `load.php` when they
are not already there. Nothing else is required.

### The pipeline

Ten steps. `needs` declares what a step waits on, and everything whose inputs
exist may run at once:

| Wave | Steps that may run together | Bucket |
|---|---|---|
| 1 | research | other |
| 2 | canonical recipe | article |
| 3 | **article · featured image · Facebook collage** | article · featured · facebook |
| 4 | **review · fact check** | article |
| 5 | apply corrections *(no model runs)* | article |
| 6 | proofread | article |
| 7 | final approval | other |

The images depend on the recipe and the research, not on the article, so they
are drawn while it is written. The approval judges the proofread text, because
that is what a reader gets.

**A wave's calls go out together.** `limits.concurrency` (4 by default, 1 to
turn it off) decides how many are in flight at once. Only the first attempt is
shared: a step that has to be asked again is asked on its own, because by then
it is no longer doing the same thing as the others. Measured on one recipe:
436s serial, 290s concurrent.

### Four call paths

A step declares a capability and the engine routes on it:

- **text** — research (with web search), the recipe, the article, the two
  reviews, proofreading. JSON in, JSON out, repaired by `MSRWA_Json` when a
  model fences it or leaks a control character.
- **image_generation** — the two images, written into a workspace the caller
  names.
- **vision** — the final approval, which reads both images' bytes alongside the
  article. It is the only point where the three can be checked against each
  other.
- **none** — applying the fact check's corrections. It quotes the sentence it
  objects to verbatim and supplies the replacement, so this is a substitution,
  not a judgement: no model, no cost, nothing to invent. A correction whose
  quote cannot be located in the HTML goes to the editor rather than being
  dropped.

### Retry until approved

A refused approval does not ask the same question again. It regenerates the
images the judge blocked, carrying its findings into the prompt as corrections,
and then asks again — up to `attempts.final_approval`. A malformed verdict is
not a refusal: the engine re-asks without touching the images, because
regenerating against a decision nobody made costs money for nothing.

---

## 2 · How it is called

### As an API — one recipe

```php
require_once MSRWA_PATH . 'includes/engine/load.php';

$result = MSRWA_Engine::run(
    array( 'title' => 'Souris d’agneau au four', 'text' => '', 'images' => array() ),
    array(
        'config'    => $engine_config,        // what the site stores, in engine keys
        'run'       => array(),               // what this one job asks for
        'workspace' => $uploads . '/msrwa',   // where generated images are written
        'only'      => array(),               // a subset of steps, or all of them
    ),
    function ( $event ) use ( $job_id ) {      // progress, as it happens
        MSRWA_DB::record_event( $job_id, $event );
    }
);
```

### As an API — one step

Used when a job runs one stage per worker invocation, and by the lab to measure
a prompt. Whatever the step needs is passed in; nothing is read from disk.

```php
$result = MSRWA_Engine::run_step( 'article', array(
    'title'     => $job->title,
    'artifacts' => array( 'research' => $research, 'canonical' => $canonical ),
), $options, $observer );
```

### As a CLI

```bash
php tools/lab.php run   --brief=souris-agneau-four --report=/tmp/out.html
php tools/lab.php run   --brief=tarte-pommes --only=research,canonical_recipe
php tools/lab.php step  article --brief=tarte-pommes --research=<run>.json --canonical=<run>.json
php tools/lab.php judge --brief=tarte-pommes --draws=5 --featured=<file>.webp --facebook=<file>.webp
php tools/lab.php report --run=<run>.json --output=/tmp/out.html
```

`tools/lab.php` is a fixture reader, a flag translator and a printer. It holds
no pipeline knowledge, which is the point: what the lab measures and what the
plugin runs cannot drift, because they are the same call.

---

## 3 · Defaults, and who overrides them

**Nothing the engine does is hardcoded.** Provider endpoints, model prices,
tiers, the step registry, the prompts, every scoring threshold and every ceiling
is a configuration key. What `defaults()` holds is a default, not a law, and
`tests/test-engine-configurable.php` reads the engine's own source and fails if
a URL or a key name reappears in the code.

Three layers, each overriding the one before:

1. `MSRWA_Engine_Config::defaults()` — what the engine does when told nothing.
   The measured values. Every output ceiling in that list has been too low at
   least once, and a truncated answer is billed in full and scores zero.
2. **The caller** — what the plugin stores, or what a lab flag says.
3. **This run** — what one job asks for, such as a retry at a higher tier.

| Key | What it decides |
|---|---|
| `routing` | which provider and model serves each step |
| `max_output` | the token ceiling per step |
| `attempts` | how many times a step may be asked again |
| `images` | quality, format, ratio, native size and panel count |
| `limits` | budget, HTTP timeout, image byte cap, how many photographs are read |
| `providers` | endpoints, headers, key variables, each provider's web-search tool |
| `models` | price per million tokens, as `[input, output]` |
| `tiers` | what `provider:low\|medium\|high` resolves to |
| `steps` | overrides for the registry — label, bucket, dependencies, prompt file |
| `prompts` | prompt text replacing the shipped templates |
| `thresholds` | every number a scorecard compares against |
| `settings` | prompt and quality settings the shared classes read |

Overriding is surgical. A caller that moves one provider's endpoint keeps that
provider's headers; a caller that recharges one step to another bucket keeps its
dependencies; a caller that moves one threshold leaves the rest at the measured
value. A caller may also **add** a step the engine has never had, and it takes
part in the wave scheduling like any other.

```php
$options['config'] = array(
    'providers'  => array( 'openai' => array( 'text_endpoint' => 'https://my-gateway/v1/responses' ) ),
    'models'     => array( 'openai' => array( 'my-model' => array( 1.0, 2.0 ) ) ),
    'tiers'      => array( 'medium' => array( 'openai' => 'my-model' ) ),
    'prompts'    => array( 'article' => $stored_prompt ),
    'thresholds' => array( 'article_accents_per_1000' => 25 ),
);
```

**Layers two and three speak the engine's vocabulary.** A caller with its own
spelling translates on its own side. The engine is the piece being reused, so
it cannot carry a mapping per caller — and when the plugin renames a setting,
exactly one readable place breaks.

A hand-edited value is clamped into what providers actually accept
(`max_output` to 256–32000, `attempts` to 1–6, the timeout to 5–3600 seconds).
An unknown key is kept rather than rejected, so a newer caller and an older
engine still work together.

The resolved configuration is recorded on the run — **minus `settings`, which
can hold keys, and minus the environment variable names, which say where a key
is kept** — together with `_provenance`, which names the layer that decided each
key. A report months later answers "what was this run with, and who decided"
without guessing.

---

## 4 · Input, output and progress

**Input** is a brief: `title`, `text`, `images`, plus `artifacts` when earlier
steps have already run. Nothing is a file path. Reading a saved run off disk is
the lab's business.

**Output** is a `MSRWA_Result`:

```php
$result->ok          // false as soon as any step fails; a partial run still returns its artifacts
$result->artifacts   // research, canonical, article, featured, facebook, review,
                     // fact_check, corrected, proofread, approval, config, brief
$result->steps       // one entry per step attempted, in order: model, seconds,
                     // usage, cost_usd, attempts, passed/total, checks, error
$result->errors      // every failure, each naming its step
$result->events      // everything that happened, with the second it happened at
$result->totals()    // seconds, cost, tokens, and the four budget buckets
$result->to_array()  // the whole thing as plain data, for storage and for the report
```

**Progress** is the observer: a callable given to `run()` and called with each
event the moment it occurs. It is the same stream the report renders afterwards,
so a live progress bar and a post-hoc record cannot disagree.

The engine reports what it did with the data, not only what it cost:

| Event | What it says |
|---|---|
| `config` | which keys the caller overrode, and the full provenance |
| `wave` | which steps may run together now |
| `attempt` | which attempt of how many |
| `input` | where the prompt came from, its size, the input size, the ceiling, which artifacts were attached, whether web search was on |
| `call` | which endpoint answered, on which model and tier, tokens in and out, **how many the provider served from its own cache**, the price, the stop status |
| `observe` | how many photographs were read from their bytes |
| `warning` | an answer that stopped on the ceiling — cut, and billed in full |
| `retry` | why the step is being asked again |
| `decision` | a refusal the engine cannot act on, going to the editor |
| `error` | a failure, naming its step |
| `step` / `finish` | the summary per step, and for the run |

Each step also records the prompt it ran, where that prompt came from, and the
size of the input it was given, so a run explains itself without a replay.

**A run stops rather than overspend.** With `limits.budget_usd` set, the engine
refuses to start a step once the spend has reached it, and says so as an error
naming the step it did not run.

---

## 5 · The HTML report

`tools/report.php` renders one `MSRWA_Result` into one self-contained page.
`report_render( array $run )` takes the array and returns the HTML; it decides
nothing, it shows what the run decided.

In the order the run happened: the summary and the bill per budget bucket, the
step table, the editor's brief, the real photographs and what was read from
their bytes, the research, the canonical recipe **read as a recipe**, the
finished article, the SEO metadata, the corrections actually applied to the
text, both images, the approval verdict with blocking *and* minor findings,
every check with what it measured, one row per provider call (endpoint, tokens,
cache hits, price), what each step was handed, the configuration with the layer
that decided each key, and the run's own timeline.

The detail is folded away until a reader opens it. Minor findings are shown as
prominently as blocking ones, because a minor finding exists precisely so a
person can decide. It works at phone width: the grids stack, the step table
stacks with its column names, and nothing runs off the side.

**API keys never appear in it.** The configuration it prints is
`$config->to_array()`, which drops `settings`.

---

## 6 · What the plugin has to do

The plugin owns exactly four things the engine deliberately does not:

- **Access.** Capabilities, nonces, per-editor scoping. The engine has no idea
  who is asking.
- **Interface.** The composer screen, the settings screens, the progress bar
  fed by the observer.
- **Configuration.** Every engine parameter exposed to an administrator, and
  translated from the plugin's own vocabulary into the engine's before the call.
  Two details the plugin must get right, both found on a live site in 0.8.0:
  keys go under `settings.keys.<provider>` with the provider named the engine's
  way (`claude`, never `anthropic`), and `settings` carries the site's
  **complete** prompt and quality settings — a non-empty `settings` replaces
  the shipped defaults wholesale, so handing over the keys alone once made
  every threshold zero. The article language travels as
  `settings.site_language`, which is what the prompts read.
- **Storage.** Artifacts, steps, events and costs into the database, through
  `MSRWA_DB::sanitize_persisted_data()`. Quality belongs to the article, never
  to the job that ran; `completed` is never editorial approval; costs are
  estimates, never an invoice.

The engine changes for none of this.

---

## 7 · Changes the owner approved, and what is still open

### Applied on 2026-09-23, with the owner's approval

The engine changes only on the owner's decision. These five were proposed
here, approved, and are in. `tests/test-engine-language.php` holds them.

1. **The article prompt demanded French accents in every language.**
   `prompts/article.tpl.txt` listed `é, è, ê, à…` and said "text without
   accents is rejected" whatever `{{language}}` was, so an English or Arabic
   article was asked for typography its language does not have. That line is
   now inside `{{#if french}}`, with `french` set by
   `MSRWA_Prompt::variables()` from the site's language.

2. **The required-sections check knew only French headings.**
   `MSRWA_Engine_Score::required_sections()` matched French synonyms, so a
   correct English article failed every time — seen live at 9/10, "missing:
   choix, matériel, erreurs, conservation" — and paid for a correction round
   that could not fix anything. It now takes the settings, reads
   `settings.required_sections` when a caller supplies one, and otherwise uses
   the shipped outline for the site's language. French, English and Arabic
   ship, each requiring the same ten sections. Supplying an outline is also
   what milestone T4.1 needs.

3. **The top-level `language` key was read by nothing.** It was recorded on
   every run and shown on the Moteur screen while every prompt read
   `settings.site_language`. `create()` now copies it across when the caller
   set one and not the other; a stated `site_language` still wins.

4. **Nothing checked the maximum length.** The words check compared against
   `quality_min_words` only, so a live English article came back at 5 988
   words for a 2 800–3 600 target and passed — and an over-long article is
   paid for twice more, because review and proofread each read it whole. It
   now fails above `quality_max_words` with 15% of tolerance, and the detail
   names which end it failed so a correction knows what to do.

5. **`claude:low` named a model Anthropic does not serve.** The `tiers` map
   said `claude-haiku-4-5`; Anthropic lists `claude-haiku-4-5-20251001`. Any
   step routed there died with a model-not-found after every step before it
   had been paid for. Both the tier and its entry in `models` now use the
   identifier that exists. All nine tier routes are priced and served.

### Applied on 2026-09-23, at the owner's request to fix the pricing

8. **Billed tokens the engine never counted.** `MSRWA_Engine_Call::read()`
   took Gemini's `candidatesTokenCount` as the whole output, but Google bills
   thinking as output and reports it apart: a live `gemini-3.6-flash` call
   answered five visible tokens after 2 717 of thinking and was priced at a
   five-hundredth of its cost. It now adds `thoughtsTokenCount` to output and
   `toolUsePromptTokenCount` (what `url_context` or grounding read — 8 973
   tokens for one pricing page) to input. Claude's cache writes and reads are
   added to input. Each web search is counted as `usage.web_searches`, from
   OpenAI's `web_search_call` items, Anthropic's
   `server_tool_use.web_search_requests` and Gemini's `webSearchQueries`, and
   `price()` adds `providers.<name>.web_search_usd` per search — 0.01 for
   OpenAI and Anthropic, 0.014 for Google, each overridable through the caller
   layer. `tests/test-engine-usage.php` holds the live shapes.

9. **Gemini thought its way through the output ceiling.** Gemini counts
   thinking inside `maxOutputTokens` and thinks hard by default. A live
   canonical recipe on `gemini-3.5-flash` spent the whole 4 500-token ceiling,
   stopped on `MAX_TOKENS` with its JSON cut, and was billed $0.046. Every
   Gemini request now carries `providers.gemini.thinking` as `thinkingConfig`,
   shipped as `{"thinkingLevel": "low"}`; the same step then passed 4/4 for
   $0.0227. A cut answer is also recognised from the provider's own stop
   reason (`MAX_TOKENS`, `max_tokens`, `incomplete`), since Gemini stops a few
   tokens short of the ceiling and the count alone missed it.

10. **One thinking level per step, on every provider.** Item 9 bounded
    Gemini alone, through a raw `thinkingConfig`. The engine now has a
    `thinking` group — a level per step or `default`, from `minimal`, `low`,
    `medium`, `high` — resolved by `MSRWA_Engine_Config::thinking()` as step,
    then default, then `providers.<name>.thinking_level` (Gemini ships `low`),
    then nothing. `provider( $name, $model, $step )` carries the level in the
    wire and `MSRWA_Engine_Call::think()` spells it per provider in text,
    vision and judge requests: `thinkingLevel`, `reasoning.effort`, or Claude's
    `output_config.effort` (`minimal` sent as `low`), never to a model that
    refuses it. OpenAI's `reasoning_tokens` and Gemini's `thoughtsTokenCount`
    are reported as `usage.thinking_tokens`, and each call event records its
    level. A caller's raw `providers.gemini.thinking` from 0.15.0 is still
    honoured when no level is set.

11. **Searches had no ceiling on OpenAI, and cached input was billed in
    full.** A live research call ran 13 searches at $0.01 each against an
    estimate that assumed 3. `limits.web_searches` (10) now caps every call —
    `max_tool_calls` on OpenAI, the tool's `max_uses` on Claude, never above a
    tool's own lower cap — and `web_searches( $provider )` is the number the
    estimate prices. `price()` bills cached input at
    `providers.<name>.cached_input_ratio` (0.1 for OpenAI, from its pricing
    page; 1.0, full price, wherever no ratio is published). OpenAI can overrun
    the cap by one call; seen once, 11 under a cap of 10.

12. **Search and thinking economies, measured before shipping.** Only a
    `search` action is billed by OpenAI; `open_page` and `find_in_page` are
    now recorded as `usage.page_reads` and not priced. The OpenAI search tool
    ships with `search_context_size: low`. `limits.web_searches` (3) is the
    paid searches expected and Claude's cap; `limits.web_tool_calls` (10) is
    OpenAI's `max_tool_calls`. The research prompt says a search is billed and
    a page read is not, and asks for at most three. OpenAI thinks at `low`
    (`providers.openai.thinking_level`), except `research`, `fact_check`,
    `proofread` and `final_approval`, which stay at `medium` — research at low
    once returned no photograph of the dish. The collage prompt requires each
    panel to show the state every earlier step left, and each ingredient only
    from its own step; the article prompt keeps storage, reheating and safety
    advice to what a source states. `MSRWA_Engine::filled()` stops an empty
    later version of the article from replacing the one before it. Every
    change was measured on live runs; see README 0.18.0.

13. **The budget holds inside the approval loop.** `perform()` prices the
    next round — the last attempt plus the images `images_to_retry()` would
    redraw, at what they last cost — and does not start it when it would
    cross `limits.budget_usd`; a `decision` event says so. The offline suite
    drives the loop through `MSRWA_Engine_Call::$transport`, a test seam that
    is null in production.

14. **A model per image.** `draw()` routes through
    `MSRWA_Engine_Config::image_route()`: `routing.featured_image` or
    `routing.facebook_image` when set, otherwise `routing.image`. Quality was
    already per image (`images.<kind>_quality`); the estimate now prices it,
    from real token counts per quality (`MSRWA_Estimate::quality_factor()`).

15. **A refused sentence is repaired, not the whole article.** The final
    approval's findings carry a `replacement` for the quoted sentence
    (`""` removes it). When every blocking article finding has a quote found
    verbatim and a replacement, `MSRWA_Engine_Score::article_repairs()` lists
    them, `before_retry()` applies them in code to the `proofread` artifact
    (recorded under `approval_repairs`) and the judge is asked again; an
    article finding it cannot repair ends the loop without redrawing images,
    which could not change the verdict. `attempts.final_approval` is 4, the
    budget guard of item 13 still bounding every round. The fact check turns
    an explanation no source gives into a correction (`after` may be empty,
    removing the sentence), the article prompt forbids inventing one, and
    `max_output.fact_check` is 12000: at 4000 two live fact checks of three
    were cut and stopped their run. `working_set()` no longer sends the
    correction logs to later steps, which put removed sentences back in front
    of the judge.

16. **The judge sees the article, not its history.** `working_set()` also
    drops the proofread's `changes` and `clean`: the change log quotes the
    sentences it replaced, and the final approval refused an article over one
    of them. The final-approval prompt now says a count is minor in the
    collage too, even where the visual brief states one; collage redraws fell
    from six to one on the same three recipes.

### Still open

6. **The engine's `models` list is a second source of truth for prices.** The
   plugin now owns the catalogue — models, rates and per-step compatibility in
   a table of its own — and hands the engine generated `models` and `tiers`
   through the caller layer. The engine's own list stays as the fallback for
   running it without WordPress, which is what it is for; but on a site, two
   lists still exist and only the generated one is authoritative. *Proposal:*
   leave it, and treat the engine's list as documentation of what the engine
   would do alone. Nothing to decide unless the owner wants it removed.

7. **The step registry is one editorial process.** Multiple content types,
   each with their own chain of steps, would be a second registry — `steps` is
   already overridable through the caller layer, so the engine would not have
   to change. *Proposal:* awaiting a written design before anything is built.
