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

### ◐ 1. Know the cost before spending it

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

*Where it stands (0.8.0):* the new-lot screen estimates each lot live and warns
before a lot that could not finish under its ceiling is sent; such a lot is
refused for free. On a real run the estimate was $0.2485 and the bill $0.2570.
Daily and thirty-day ceilings exist. Still missing: the min/max grid per bucket
on the settings screen.

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

*Where it stands:* not started. The Moteur screen already lets you pick the
model for every step by hand, and *Vérifier les clés* tells you which providers
answer.

### ◐ 4. Articles that are complete and correct

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

*Where it stands (0.8.0):* verified on a real site — research, recipe, article
over two pages, review, fact check with corrections applied, proofreading, and
a draft carrying its excerpt, tags, SEO title and description. Recipe
structured data is printed once the article is published. The section list is
enforced but not yet editable: that needs an engine change you have to approve
(see `ENGINE.md` §7).

### ☑ 5. A simple screen for editors

Editors see their articles, the quality verdict, the publication status, the
draft, and a clear sentence when something needs their attention. No costs, no
model names, no technical detail. Administrators keep everything.

**Done when:** an editor account sees no cost and no technical information
anywhere.

*Verified (0.8.0)* in a real browser with an author account: no amount on any
screen, and each recipe says where it stands in one sentence — "the draft is
ready", "the site is not connected to a writing service for one step" — instead
of an error message.

### ◐ 6. One language per site

The administrator picks the site language; every article follows it. Three
languages ship.

**Done when:** changing the language setting changes the language of the next
article.

*Where it stands (0.8.0):* the language is chosen in *Réglages* and offered on
every new lot; French, English and Arabic ship. An English article was written
on a real site; Arabic has not been run live yet.

## What I need from you

1. **An OpenAI or Gemini key usable from a test machine that can reach them.**
   The keys you sent work for Claude; the sandbox used for 0.8.0 could not reach
   OpenAI or Gemini at all, so images, the final judge and photograph matching
   have not run live yet. Please also **rotate the three keys** you pasted into
   the chat.
2. **A staging copy of the real site** (MySQL), with an administrator's
   application password — the 0.8.0 verification ran on a local SQLite site.
3. **A decision on the four engine changes** proposed in
   [`ENGINE.md`](ENGINE.md) §7. None is applied until you say so.

See *Credentials* in [`TESTING.md`](TESTING.md) for where each item goes —
never in the repository.
