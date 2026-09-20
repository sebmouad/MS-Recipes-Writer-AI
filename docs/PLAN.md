# Plan — what is being built

Plain-language plan for the site owner. The detailed, technical version lives
in [`BUILD-CHECKLIST.md`](BUILD-CHECKLIST.md); this file is the one to read to
know where the project stands.

Status: `☐` not started · `◐` in progress · `☑` done and verified on a real
site.

## What we are building

MS Recipes Writer must write recipe articles that are more complete and better
written than MS-Cook-Writer-AI, produce several recipes in one batch, never
ship writing mistakes, and let you see what each recipe will cost before you
spend it. Editors only write and publish; administrators control everything.

## How it is being built

Three stages, in this order:

1. **Prove the prompts.** Each writing step is tested directly against the AI,
   with no WordPress involved, until it produces what we agreed: the right
   length, every required section, correct facts, clean French. The winning
   prompts are then stored in the plugin.
2. **Build the whole plugin** on those prompts: generation engine, screens,
   menus, costs, security and access rights, as one complete first version.
3. **Deploy on the site**, measure, and improve what the real runs show.

A measurement from the current code, for reference: one recipe took over ten
minutes, of which only five were actual AI work — the rest was the plugin
waiting between steps — and the article was rewritten four times. The reference
plugin does article plus image in about sixty seconds. Fixing that is the first
build task.

## The six milestones

### ☐ 1. Know the cost before spending it

The plugin stops using fixed guesses and calculates real amounts: for each
recipe, a **minimum** and a **maximum** cost, split into four parts — the
article, the featured image, the Facebook image, and everything else
(research, matching, model choice, checks). The numbers update immediately when
you change a setting such as the word count or an image size.

Budgets become daily and monthly only. Nothing is ever interrupted in the
middle: when a limit is reached, the recipes already running finish completely
and new batches are refused until the next day or month.

**Done when:** the settings screen shows min and max per bucket and per recipe,
and they change as you edit settings.

### ☐ 2. The right model for each step, without overpaying

You choose once, in settings, which models are allowed and what each bucket
should cost. Every recipe then gets its own plan automatically, frozen for that
job. When a step is rejected by a check, it is retried with a **stronger**
model rather than the same one — so the premium price is paid only on the
articles that actually need it.

**Done when:** a rejected step visibly moves up to a better model, and the
article costs stay inside the targets on articles that pass first time.

### ☐ 3. A settings assistant that asks before it proposes

The assistant first asks you, bucket by bucket, what you require: how long and
how deep the article must be, how good each image must be, how much research
and verification you want. Then it reads the connected providers and prices and
proposes a complete policy with the cost it implies. It never changes anything
by itself — you review and accept.

**Done when:** you can answer four questions and get a working policy you only
have to approve.

### ☐ 4. Articles that are complete and correct

2800 words minimum, split over two pages (about 1500 then 1300, page two
starting at the preparation). A fixed list of sections you can edit, which the
plugin refuses to publish without: ingredients and how to choose them,
substitutions, equipment, detailed steps, mistakes to avoid, storage,
variants for diets, serving, FAQ, conclusion.

Before delivery the finished text is checked against the sources found during
research — wrong quantities, times, temperatures or claims are corrected, and
only the faulty parts are rewritten. A proofreading pass then fixes grammar,
spelling and contradictions. Recipe data is published as structured data so
Google can show rich results, with no extra recipe plugin required.

**Done when:** a generated article contains every required section, matches its
sources, and reads without mistakes.

### ☐ 5. A simple screen for editors

Editors see their articles, the quality verdict, the publication status, the
draft, and a clear sentence when something needs their attention. No costs, no
model names, no technical detail. Administrators keep everything.

**Done when:** an editor account sees no cost and no technical information
anywhere.

### ☐ 6. One language per site

The administrator picks the site language; every article follows it. Three
languages ship.

**Done when:** changing the language setting changes the language of the next
article.

## What I need from you

Real tests require real access. See *Credentials* in
[`TESTING.md`](TESTING.md) for where to put each item safely — never in the
repository.

1. **Provider API keys** for the models you intend to use: OpenAI (text,
   images and web search), and Gemini and/or Anthropic if you want them in the
   routing.
2. **A WordPress test site** the plugin can run on, with either WP-CLI access
   or admin credentials. A staging copy is ideal; a local site is fine.
3. **A spending cap for tests** — the amount per day I may use on real
   generation runs, so verification never surprises you.
4. **The three languages** you want shipped. Assumed for now: French (default),
   English, Spanish.

Until those exist, work continues with offline tests only, and milestones stay
`◐` rather than `☑`: a milestone is only *done* once it has been verified on a
real site with real providers.
