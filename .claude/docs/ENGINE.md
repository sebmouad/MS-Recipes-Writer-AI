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
| `prompts/*.tpl.txt` | The ten prompts. Engine data: it runs them, it carries them | — |
| `load.php` | Loads the shared plugin classes, then the engine | — |

`MSRWA_Prompt`, `MSRWA_Quality`, `MSRWA_Recipe`, `MSRWA_Images` and `MSRWA_Json`
are shared with the plugin and loaded by `load.php` when they
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

17. **The proofread returns its changes, not the article.** It answers
    `{changes: [{type, before, after}], clean}`; `MSRWA_Engine::proofread()`
    substitutes each `before` found verbatim into the corrected article, refuses
    any change that alters a figure, treats an already-present `after` as applied
    (overlapping passages), and keeps the rest as `changes_not_applied` for the
    editor. The step's checks run on the rebuilt article; an answer in the old
    shape, with `content_html`, is still taken. Live: 3 050–3 320 tokens out
    instead of 6 000, 32 s instead of 49 s, $0.006 instead of $0.0096.

18. **Image prompts say each rule once.** The collage template states the six
    moments, the order rules, continuity, realism and photography once each
    (9 828 → 4 470 characters); the featured template drops the reference to a
    research package it is no longer sent. The visual brief no longer re-lists
    the ingredients or quotes the observations a second time, and the closing
    rule 3 points at the list instead of repeating it. Live, three recipes:
    collage input 3 750 → 2 530 tokens, featured 1 800 → 1 450, every image
    approved as good and realistic.

### Applied on 2026-09-24, at the owner's request to cut research cost

The owner asked that the research search the web only when the writer sent no
photograph, and otherwise use the photograph without the cost of a search.
`tests/test-engine-research-photographs.php` holds it.

19. **The writer’s photographs never reached the research.** The plugin
    hands each brief image as `{id, url, title}`; `observe_editor_images()`
    read `image_url`, got nothing, and fetched ''. On a site served over plain
    http the fetch would have been refused anyway, since only public HTTPS is
    downloaded. Every run with a photograph recorded
    `{"image_url":"","uncertainties":"image URL is not HTTPS"}` under an event
    saying the image had been read. The engine now reads `url` too, and asks
    the caller first through `$options['read_image']` — a callable given the
    brief image and the byte limit, returning `{mime, data}` or `{error}`.
    The plugin passes `MSRWA_Intake::engine_image()`, which reads only an
    attachment marked as sent by a writer. The event now counts what was read.

20. **Research from the writer’s photographs, without a search.** A new
    top-level group, `research.web_search`: `without_images` (the default)
    or `always`. With the default and at least one readable photograph, the
    research runs `prompts/research_photographs.tpl.txt` with no web search
    tool; facts cite `brief`, `photograph` or `culinary_practice`; the
    photographs become the package's `visual_references` (source `editor`,
    tier 1) and `visual_observations`, so every later step reads them as it
    read the web's. The research contract drops the two web-only checks —
    references with a URL, HTTPS provenance — and checks the editor's
    photographs instead. With no readable photograph it searches, and says so.
    The vision calls on the writer's photographs are now billed to the
    research step; they were not counted before. Live, one tart with its
    photograph: research $0.0054 in 30.6 s, 13/13, against $0.022–0.028 and
    42–79 s for the ten searched runs before it; the recipe was approved.

### Applied on 2026-09-24, at the owner's request to add Spanish

21. **A Spanish outline.** `MSRWA_Engine_Score::outlines()` gains `es`, the
    same ten sections as the others with their Spanish wordings
    (ingredientes, elección, sustituciones, utensilios, preparación,
    errores, conservación, variantes, servir, faq), so a Spanish article is
    held to the same contract and never failed on French headings.
    `tests/test-engine-language.php` now requires an outline for every
    language the plugin offers.

22. **The collage draws a result, keeps six cells, and the judge sees it.**
    Owner's request, 2026-09-24, after a quiche and a fig tart were each
    drawn with a case of baking beans on paper in panel 3.
    `facebook_image.tpl.txt`: mise en place holds only raw ingredients and
    empty cookware; a cooking stage is shown by its result, never by
    equipment removed afterwards; paper liners, beans, weights and foil are
    forbidden in every panel. `MSRWA_Engine_Input::image_prompt()`: the
    closing checklist gains a blind-bake rule and ends on "exactly N panels,
    2 × 3, every cell filled"; the step pool states the arithmetic when the
    recipe has more steps than panels, and, when it has fewer, fills the
    extra panels with a step's before and after states, never an invented
    step. `final_approval.tpl.txt` check 4 blocks a panel whose subject is
    equipment and an empty cell; image severity defers to check 4. Lab:
    beads 0 of 10 draws, grid right in 9 of 10 at seven steps and 1 of 1 at
    four.

23. **The research is bounded.** Owner's request, 2026-09-24: a research from
    photographs stopped on its 12,000-token ceiling at 12,413 out, 17,673
    characters, unparsed, 0/13. Both research prompts cap every list (4
    substitutions, 4 accompaniments, 5 failures, 3 storage, 3 food safety, 4
    uncertainties, 3 originality notes, one short sentence each) and
    `max_output.research` rises to 16000.

24. **A recipe's photographs are read once.** Owner's request, 2026-09-24.
    `observe_editor_images()` uses an image's `observation` when the caller
    gives one, instead of a vision call; the plugin's pairing reads each
    photograph with the engine's own `vision_instruction` and passes the
    reading on. A `keep_image`
    option, called by `MSRWA_Engine_Call::observe_images()` with each web
    photograph it fetched, lets the caller keep what the research was
    written from.

25. **One visual reference is enough.** Owner's decision, 2026-09-24,
    replacing the `research.min_photographs` of 0.24.0, which asked for two
    and searched the web when a recipe had one photograph. One readable
    editor photograph means no search. Without one, the research reads
    `limits.web_images_inspected` (1) of the photographs it cites, where it
    read `limits.images_inspected` (3); that key now bounds only the
    editor's photographs. `with_editor_photographs()` still puts an editor's
    photograph first when the research searched anyway (`always`).

26. **Facebook templates.** Owner's request, 2026-09-24: a slot for a second
    Facebook image, picked per lot, before the template itself exists.
    `images.facebook_templates` maps a key to `label`, `prompt` (a file under
    `prompts/`) and, optionally, `panels`, `columns`, `ratio`, `size`; what a
    template leaves out comes from the `images.*` keys. `images.facebook_template`
    names the one a run draws; `MSRWA_Engine_Config::facebook_template()`
    resolves it and falls back to the first template whose prompt file exists,
    so a lot never fails on a template removed after it was made. `draw()`,
    the approval manifest and the panel check read it. The closing collage
    rule now states the grid from the panel count (`MSRWA_Engine_Input::grid()`)
    where it always said "2 columns × 3 rows". Shipped: one template,
    `collage`, the existing prompt. Adding one: a prompt file and an entry.

27. **The collage is six panels; foreign letters are caught.** Owner's
    decision, 2026-09-24. The `collage` template declares `panels: 6`, the
    count its prompt is written for; the site setting that could change it,
    and so contradict the prompt, is gone. `MSRWA_Engine_Score::step()` gains
    `one alphabet` on research, the recipe, the article, the fact check and
    the proofread: letters from Han, kana, Hangul, Cyrillic, Thai, Hebrew or
    Devanagari — and Arabic outside an Arabic article — fail it; Greek stays
    allowed for units and symbols, and addresses are skipped. When that is
    the only failed check, `perform()` asks once more even on the step's last
    attempt. Found with the fix: the research branch of `step()` reused
    `$step` as a loop variable, so any check placed after it never ran for
    research. Across 328 stored answers the check fires once, on the
    "润ir les pommes" that prompted it.

28. **Arabic headings are matched with or without their vowel marks.**
    Found on the first live Arabic lot, 2026-09-24: its article had the
    section on choosing the ingredients, "كيف تختار…", and was marked as
    missing it, because the outline knew only "اختيار" and "اختر".
    `MSRWA_Engine_Score::fold()` now drops Arabic short vowels and the
    tatweel, and the choosing section also accepts "تختار", "يختار" and
    "انتق". The same lot came back with 2,033 words against a 2,400 target:
    Arabic says the same in fewer words. Approved by the owner the same day:
    `MSRWA_Prompt::word_range()` holds each language to its equivalent of
    the French settings (`LENGTH_FACTORS`, Arabic 0.85), and the prompt, the
    `words` check and the quality contract all read it.

29. **A warmer, closer collage that ends opened.** Owner's request,
    2026-09-24, after comparing our collages with ones he preferred: tighter,
    warmer, richer, and a last panel that shows the inside. The `collage`
    prompt now asks for a close-up in every panel, the first included (the
    food fills 85–95% of the cell, vessels may leave it, no empty surface);
    warm side light with more contrast and true, rich colour, browning as deep
    as the cooking makes it (the observed colour still wins when a real
    photograph recorded one); one warm home-kitchen setting — wood, linen or
    gingham, herbs out of focus behind the food; and a last panel always
    opened — a slice lifted, a piece broken, a spoonful raised. The final
    approval reports `facebook_image.last_panel_opened`, and a whole last
    panel is blocking. The judge was seen to approve a whole quiche anyway, so
    `MSRWA_Engine_Score::enforce()` turns `false` into a refusal with a
    finding (in the site's language, unless the judge wrote one), which
    redraws the collage. Measured the same day on two new briefs,
    `croquettes-pommes-de-terre-jambon` and `quiche-poulet-courgettes`: four
    collages at medium quality, about $0.024 each; three opened their last
    panel unasked, the whole one was refused twice out of two, and a redraw
    came back opened and approved.

30. **Photographs, not renders.** Owner's request, 2026-09-24: the collage
    must look perfectly real. The `collage` prompt's realism and photography
    now name what gives an AI image away and ask for the opposite: irregular
    hand-cut pieces, uneven browning, drips, crumbs, fond in a used pan, meat
    fibres; no waxy or uniform gloss, no identical pieces, no symmetry, no
    halos, no over-sharpening, no HDR; a full-frame 50 mm at f/2.8 and
    ISO 400 with natural grain. Measured on `roti-orloff` and
    `potee-porc-chou`, one collage per variant: the new prompt at medium
    ($0.025) reads as a photograph where the old one read as a render; high
    ($0.055) adds the finest texture; `gpt-image-2` ($0.055) is moodier and
    ended the rôti whole. Medium stays the default. The lab's
    `--image-model` now takes `provider:model`; Gemini could not be compared
    because the engine has no Gemini image endpoint.

31. **The owner's look: bright, sharp, full-frame food.** Same day, the owner
    set two of his reference collages against item 30 — "this is what I
    expect". Item 30 had read "realistic" as moody photography; his references
    are high-key and even, on a plain pale surface with no props, sharp from
    front to back, in clean bright colour, cropped so the food is the whole
    picture and the mise en place piled up. The prompt now asks for exactly
    that: one plain pale-grey or light-wood surface and no props (the warm
    wood, gingham and herbs of item 29 are gone), even diffused daylight,
    no grain or blur on the food, a crop that never shows a vessel whole,
    a generous overlapping mise en place. The realism rules of item 30 stay,
    minus the camera settings that asked for shallow focus and grain. Three
    rounds on `roti-orloff` and `potee-porc-chou`, compared side by side with
    his references; medium quality, about $0.025 a collage.

32. **Gemini draws images, priced as images.** Owner's request, 2026-09-24:
    try Gemini against OpenAI for the collage. `providers.gemini.image_endpoint`
    is the same `generateContent` URL as text; `MSRWA_Engine_Call::plan_image()`
    sends it `responseModalities: IMAGE` and an `imageConfig` —
    `gemini_image_config()` turns the size into a ratio (1024x1536 → 2:3) and
    asks 2K at high where the model offers sizes (Gemini 3, not Flash Lite).
    `read()` takes the inline image and `convert_image()` writes it in the
    format the run asked for. Pricing: a model's rate may carry a third
    number, the image output rate; `price()` bills `image_tokens` at it and
    the rest of the output at the text rate. The shipped rates had been the
    text rates, a tenth of the image price; they are now [in, text out, image
    out] from Google's pricing page, and `gemini-3.1-flash-lite-image` joins
    them. `MSRWA_Catalog::for_engine()` hands the image rate through, since the
    catalogue table holds only two. Measured on `roti-orloff`, same prompt:
    OpenAI high ($0.056) kept the tight crop, the order and the opened last
    panel; Gemini 3 Pro Image (about $0.150) was the cleanest texture but
    framed wide; 3.1 Flash Image (about $0.071) ended whole and showed a
    carton; 3.1 Flash Lite Image (about $0.036) framed wide and sliced the
    roast raw. OpenAI stays the default.

33. **The collage at high; small sequence faults are minor.** Owner's
    decision, 2026-09-24. `images.facebook_quality` ships `high` (so does the
    Standard preset, which reads the shipped value). The final approval's
    sequence check now names what blocks — the principal food in a state the
    steps before it cannot produce (raw after cooked, cooked before the step
    that cooks it, filled before the case exists) — and what never does: a
    seasoning, aromatic, herb, spice, garnish or secondary ingredient a step
    early or late, or a secondary step out of order while the principal food
    reads right. Measured the same day on the three briefs that had cost the
    most redraws: the turnovers approved on the first collage ($0.120, against
    three redraws and $0.196 before), the croquettes first time ($0.126), the
    potée after one redraw for a whole last panel ($0.192, against three
    refusals for its aromatics and no approval before). The estimate rises to
    $0.140 for a full recipe.

34. **The brief defines the dish; an unreadable answer is asked again.**
    Owner's request, 2026-09-24: the cod gratin came back with potatoes,
    artichokes and mushrooms, the turnovers as one large pastry. The research,
    the research from photographs and the canonical recipe now hold the dish's
    form, principal ingredients and named method to the brief; a principal
    component a source adds goes to substitutions, accompaniments or
    variations, never into the recipe. The canonical recipe lists each
    ingredient once, and `MSRWA_Engine_Score::step()` checks it
    (`ingredients listed once`). Live: the gratin came back as cod, béchamel
    and cheese with potatoes as a variation; the turnovers as individual
    half-moons. On the first turnovers run the research answer did not parse
    and the recipe ended on its only attempt: an answer that fails `valid
    JSON` is now asked once more even on its last attempt, as stray letters
    already were. The plugin's shipped per-recipe ceiling rises to $0.25, which
    leaves room for a second collage after one redraw at high.

35. **The collage prompt is the owner's own.** 2026-09-24: the owner shared
    the ChatGPT prompt that produces the collages he holds up, and the style
    notes his assistant keeps about him — ultra-realistic like a dish cooked
    at home, bright natural light, a clean modern kitchen in light tones,
    soft shadows, a slight top-down angle, Pinterest composition, six square
    images in 2 × 3, the same utensils throughout, a logical progression, a
    last image sliced or opened, no text, and never the look of AI. The
    `collage` prompt is rebuilt on it, in his order: style, layout, the six
    images (ingredients, filling or sauce, first and second assembly,
    topping, the finished dish opened), food details, text. Kept from the
    engine's lessons: the recipe's order and states, one stage per panel,
    no passive moments, no packaging or baking beans. Item 31's ban on props
    goes: his own references carry a gingham cloth and blurred herbs. Five
    collages at high on the dishes of his references, then one round to crop
    tighter and deepen colour.

36. **The collage is composed, then drawn from a reference.** Owner's
    decision, 2026-09-24, after a day of side-by-side tests against his
    ChatGPT collages: a single call with any prompt missed his look or the
    recipe's order; what ChatGPT does — a chat model writes the image prompt
    from the user's message, memory and image, then the image tool draws it —
    reproduced both. The `collage` template now names `compose`
    (`facebook_compose.tpl.txt`: the writing instruction, his saved
    preferences, the look he approves, the panel rules) and `brief`
    (`facebook_brief.tpl.txt`: his ChatGPT prompt word for word, `[DISH]`
    replaced by the title). `draw()` calls `compose_collage()`: route
    `image_compose` (default `openai:medium`, `max_output.image_compose`
    3000) is sent the instruction, `MSRWA_Engine_Input::collage_brief()` (his
    brief, the recipe's ingredients, steps and serving, and what the reference
    is) and the reference image, and answers in plain text
    (`MSRWA_Engine_Call::plan_compose()`). The reference is the editor's first
    photograph of the dish when there is one, otherwise the first readable
    path in `images.style_references` (the caller's; the plugin hands the
    owner's uploaded collages). The image is drawn with that reference
    attached: on OpenAI through `image_edit_endpoint`
    (`/v1/images/edits`, multipart, one reference), on Gemini as an inline
    part. The prompt is written once per run and stored as the
    `facebook_composed` artifact; a redraw reuses it with the refusal's
    findings. The writing is billed with the image. If the writing fails, the
    template's own `prompt` draws, with a warning. The final approval is
    unchanged. Measured: the rôti Orloff, croquettes, cod gratin and
    turnovers, two draws each, matched his collages in look and order; full
    runs about $0.13 a recipe, the collage about $0.055 plus $0.002 of
    writing. Found on the first full run: the single-call path (redraws) sent
    the upload as a form and got HTTP 400; every send now passes the upload
    flag, and a test reads the source to keep it so.

37. **Cheaper collage, no packaging.** Owner's request, 2026-09-24: the live
    apple tart showed its pastry as a wrapped block in panel 1, and the collage
    was 43% of a recipe's cost. The composing instruction now has everything
    bought set out of its packaging (pastry as a sheet or ball of dough,
    butter on a plate, cream in a jug) and bans cartons, packets, wrapped
    blocks, film and labelled bottles in every panel. The reference image is
    shrunk to `limits.reference_pixels` (768 on the long side) before it is
    sent: 704 input image tokens instead of 1,536. `images.facebook_quality`
    ships `medium` again: with a reference carrying the style, seven dishes at
    medium matched high for $0.021 against $0.051 a collage. Three full runs:
    first passes $0.086–0.109 (from about $0.126), each approved after one
    redraw for a real fault, totals $0.114–0.133 with the redraw.

### Still open


6. **The engine's `models` list is a second source of truth for prices.** The
   plugin now owns the catalogue — models, rates and per-step compatibility in
   a table of its own — and hands the engine generated `models` and `tiers`
   through the caller layer. The engine's own list stays as the fallback for
   running it without WordPress, which is what it is for; but on a site, two
   lists still exist and only the generated one is authoritative. *Proposal:*
   leave it, and treat the engine's list as documentation of what the engine
   would do alone. Nothing to decide unless the owner wants it removed.

7. **A second content type — design, awaiting approval.** Owner's request,
   2026-09-24: written now, built when the second type is defined. Nothing
   in the recipe engine changes until then.

   **Where things stand.** The engine is two layers in one set of files.
   The *runtime* is already general: waves and retries (`MSRWA_Engine::run`),
   provider calls on three providers (`MSRWA_Engine_Call`), configuration in
   three layers (`MSRWA_Engine_Config`), the result and its events
   (`MSRWA_Result`), and a step registry any caller may replace (`steps`).
   The *recipe* is spread through it: `MSRWA_Engine_Input::build()` has one
   branch per recipe step and about sixty recipe references;
   `MSRWA_Engine_Score` has one contract per recipe step and the recipe
   outlines; `MSRWA_Engine::write()` special-cases `research` (photographs,
   observation) and `proofread`; `draw()` and `image_prompt()` know the
   collage; `MSRWA_Recipe` validates the canonical recipe. On the plugin
   side the queue, runs, budgets, costs, rights, catalogue, sources and
   history are general; `MSRWA_Draft`, `MSRWA_Stack`, `MSRWA_Schema`,
   `MSRWA_Head`, `MSRWA_Match::brief()` and the profiles are recipe-shaped.
   The catalogue already carries a `content_type` column. A second engine
   would share the runtime today, but could not be written without touching
   the recipe code — which is the thing to avoid.

   **The contract.** A content type is one class per side, registered by
   key and chosen by `brief['type']` (already `recipe` on every brief):

   | Engine: `MSRWA_Engine_Type_<Name>` | What it answers |
   |---|---|
   | `steps()` | its registry: steps, needs, produces, capability, prompt |
   | `input( $step, $prompt, $brief, $options )` | what a step is sent — today `MSRWA_Engine_Input::build()` |
   | `score( $step, $answer, $brief, $thresholds )` | whether an answer meets its contract — today `MSRWA_Engine_Score::step()` |
   | `after( $step, $answer, $brief, $result )` | what happens to an answer before it is kept — today research's photographs and proofread's merge |
   | `image_prompt( $kind, $brief, $options )` | the image prompts, when the type draws; Facebook templates stay per type |
   | `prompts/<type>/` | its prompt files; `recipe` keeps `prompts/` |

   | Plugin: `MSRWA_Type_<Name>` | What it answers |
   |---|---|
   | `profiles()` | what a lot may ask for, and the steps each runs |
   | `brief( $item, $images )` | the engine brief from what the writer sent — today `MSRWA_Match::brief()` |
   | `publish( $post_id, $artifacts )` | how the draft is written — today `MSRWA_Draft`, `MSRWA_Stack`, `MSRWA_Schema` |
   | `estimate()` | the token shapes the estimate prices |

   The runtime calls these and nothing else; a lot carries its `type`, and
   screens read their labels from it. History, sources, the report's
   history section, budgets and the queue need no change.

   **Order of work, when approved.** (1) Move the recipe code behind the
   engine contract as `MSRWA_Engine_Type_Recipe`, behaviour unchanged: every
   offline test green and one live lot compared field by field with a lot
   run before the move. (2) The same on the plugin side, `MSRWA_Type_Recipe`.
   (3) The second type, written against the contract alone. Steps 1 and 2
   are the engine's largest change since it was split out; each is its own
   version, reverted whole if the live lot differs.

   **Decided now.** Nothing is built. Until step 1, a second type cannot
   share this engine without touching the recipe; after it, it can.
