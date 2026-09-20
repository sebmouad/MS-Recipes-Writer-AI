# Specification — editorial quality and cost model

Decisions agreed with the product owner on 2026-09-20. This is the contract the
implementation must satisfy; `docs/ROADMAP.md` tracks progress against it and
`docs/ARCHITECTURE.md` describes the code as it exists today.

Where a value is called a *default*, it ships as that value and the
administrator can change it in the settings screen.

## 1. Goal

Produce recipe articles at least as complete and as well written as
[MS-Cook-Writer-AI](https://github.com/sebmouad/MS-Cook-Writer-AI), with no
writing mistakes, several recipes per batch, and a cost the owner can predict
before spending it. MS-Cook-Writer-AI is a **benchmark to beat**, not a code
base to copy: keep this plugin's queue, quality gate, artifacts and budgets,
and take from it what is genuinely better in the output.

What is worth taking from it, confirmed by reading its source:

- The article is written in **two parts** with continuity notes passed from
  part 1 to part 2, page 2 starting at the preparation section.
- H2 titles phrased as questions people actually search.
- Short paragraphs of two to four sentences, for readability and ad placement.
- Commercially useful sub-topics **only when they help the reader**: equipment
  (air fryer, robot pâtissier, Thermomix, cocotte, moule) and dietary variants
  (protéinée, sans gluten, IG bas, allégée, sans lactose).
- Metadata (title, meta description, slug, category, tags, alt text) never
  appears inside the article body.

## 2. Article contract

- **Minimum 2800 useful words**, split over two pages: **≈1500** on page 1,
  **≈1300** on page 2. The existing `<!--nextpage-->` pagination carries this.
- Page 1 covers introduction, why this recipe works, choosing ingredients,
  substitutions and equipment. Page 2 starts at the detailed preparation and
  covers mistakes to avoid, storage, variants, serving, FAQ and conclusion.
- **Fixed outline, administrator editable.** The required sections are a
  setting; the quality gate rejects and corrects an article that misses one.
  Shipped list: introduction · pourquoi cette recette · ingrédients et leur
  choix · substitutions sûres · matériel et équipement · préparation détaillée
  étape par étape · erreurs à éviter · conservation et réchauffage · variantes
  et adaptations (régimes) · accompagnements et service · FAQ · conclusion
  utile.
- One quality contract for the whole site: **no Rapide/Premium modes**, and no
  per-batch quality switch for editors.
- Structured data: the plugin writes **its own recipe meta plus a complete
  schema.org Recipe JSON-LD** (ingredients, steps, times, servings, nutrition).
  No dependency on WP Recipe Maker or Tasty Recipes; keep the writer behind an
  adapter so one can be plugged in later.
- **Language**: the administrator selects one language for the whole site.
  Three are shipped (default French; see open items for the other two). Articles
  are never mixed across languages on one site.

## 3. Quality and verification

Every article passes, in order:

1. **Web research** before writing, with sources retained.
2. **Deterministic structural gate** — length, required sections, headings,
   paragraphs, recipe completeness, SEO field lengths (`MSRWA_Quality`).
3. **AI editorial review** of the draft.
4. **Post-write fact check**: the finished article is re-read against the
   research sources; quantities, times, temperatures and factual claims that
   are wrong or unsupported are corrected. **Only the failing parts are
   rewritten**, never the whole article.
5. **Proofreading pass, always on**: grammar, spelling, and coherence between
   ingredients, steps and times, in the site language.
6. **Image reviews** for the featured and Facebook images.
7. **Delivery verdict** (`editorial_review`) stored on the article row.

Sources are used for verification; they are **not** printed in the article.

## 4. Cost model

Cost is reported and estimated in **four buckets**:

| Bucket | Covers |
| --- | --- |
| **Article** | Writing both parts, review, fact check, proofreading, corrections |
| **Featured** | Featured image generation and its review |
| **Facebook** | Facebook image generation and its review |
| **Other** | Association, web research, model routing, vision analysis of references |

Rules:

- **Estimates are derived, never flat constants.** For each step: tokens needed
  (from the configured word counts, prompt sizes and output limits) × the price
  of the model that step would actually use. Images are priced from a table per
  (model, size, quality), because image APIs bill per image, not per token.
- Every bucket and every recipe carries a **minimum and a maximum**:
  - *min* — every step passes on the first attempt with its standard model;
  - *max* — every step escalates to the stronger model and uses all its
    correction cycles.
- The settings screen **recomputes min/max live** whenever a setting changes:
  word count, outline, image sizes, models, search options, correction limit.
- **Target cost per bucket** (administrator): guides model choice. It is a
  target, never a gate.
- **Only two budgets exist: daily and monthly.** There is no per-recipe budget
  and no per-bucket ceiling.
- **No step is ever stopped for cost.** When the daily or monthly budget is
  reached: jobs already running finish completely, including their images; new
  batches are refused with a clear message until the period resets.
- Displayed amounts remain estimates from the captured catalogue, never an
  invoice.

## 5. Model policy

Three layers:

1. **Policy in settings** (administrator, once): which models are allowed for
   each step, and the target cost per bucket.
2. **A plan per recipe**, chosen automatically at creation from that policy and
   **frozen for the job**, so one article is written with a coherent set of
   models and the estimate matches what ran.
3. **Escalation on failure only**: when a step is rejected by the gate, the
   review, the fact check or the proofreading, it is retried with the **next
   model up**, not the same one. Bounded by the correction limit. This is what
   keeps premium pricing on the articles that need it.

Automatic choice takes the best capable model per step; the administrator can
**pin a specific model for any step**.

### AI-assisted policy setup

The settings screen offers an assistant which:

1. **First asks the administrator, bucket by bucket, what their requirement
   is** (how long and how deep the article must be, how good the featured and
   Facebook images must be, how much research and verification they want).
2. Reads the connected providers, model capabilities and current prices.
3. Proposes a complete policy — models per step, per-bucket targets, word
   counts, correction limits — with the min/max cost it implies.
4. **Never applies anything on its own**: the administrator reviews, edits and
   accepts.

Alongside the proposal, each setting carries an inline warning when a
combination is incoherent or needlessly expensive, for example a word count no
selected model can reach within its target.

## 6. Roles

- **Editors** see: the article, its quality verdict, publication status, the
  draft, and a plain-language reason when something needs attention. They see
  **no cost, no models, no tokens, no stages, no diagnostics**, and only their
  own content.
- **Administrators** keep every control and every diagnostic, across all
  editors.

## 7. Images

- Featured image: **1024×1024 WebP** (default).
- Facebook image: **1024×1536 WebP** (default).
- Both sizes and the format are settings. Note Meta's feed recommendation is
  usually landscape 1200×630; portrait fills more of a mobile feed.

## 8. Implementation order

Each phase ships with its tests and a version bump, per `CLAUDE.md`.

1. **Cost engine** — per-step token estimator, image price table, four buckets,
   min/max, live recompute in settings, budgets reduced to daily and monthly,
   removal of every cost-driven stop except the new-batch refusal.
2. **Model policy and escalation** — allowed models per step, per-bucket
   targets, frozen per-recipe plan, escalation ladder on rejection.
3. **AI-assisted policy setup** — the requirement interview, the proposal, the
   inline warnings.
4. **Article contract** — enforced editable outline, two-part writing with
   continuity, fact-check pass, always-on proofreading, Recipe JSON-LD.
5. **Role separation** — editor surface without cost or technical detail.
6. **Site language setting** — three shipped languages, one per site.

## 9. Open items

- **Which three languages** ship. Default assumption: French (default), English,
  Spanish. Confirm or replace.
- Exact wording of the shipped outline section titles, once a first article is
  reviewed against a real page.
