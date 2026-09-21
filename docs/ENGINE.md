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

The plugin is not written yet. When it is, it owns exactly four things the
engine deliberately does not:

- **Access.** Capabilities, nonces, per-editor scoping. The engine has no idea
  who is asking.
- **Interface.** The composer screen, the settings screens, the progress bar
  fed by the observer.
- **Configuration.** Every engine parameter exposed to an administrator, and
  translated from the plugin's own vocabulary into the engine's before the call.
- **Storage.** Artifacts, steps, events and costs into the database, through
  `MSRWA_DB::sanitize_persisted_data()`. Quality belongs to the article, never
  to the job that ran; `completed` is never editorial approval; costs are
  estimates, never an invoice.

The engine changes for none of this.
