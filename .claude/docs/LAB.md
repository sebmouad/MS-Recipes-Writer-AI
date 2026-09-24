# The lab

The lab runs the engine against real providers, without WordPress and without a
database. It is the same engine the plugin runs, so a prompt proven here cannot
drift from the one that ships.

```text
editor brief (title, article or images)
  -> sourced research package (text facts + observations read from real images)
  -> canonical recipe
  -> article, featured image and Facebook collage, together
  -> one review (findings, fact corrections, language changes) and the final
     approval over both images, together
  -> factual corrections, then language changes, applied in code
```

The collage is drawn from the style reference the plugin ships
(`assets/style/facebook-reference.jpg`), as a site with none uploaded draws it;
`--style-reference=path` uses another, `--style-reference=none` the text alone.

Every later step receives the same research package. Visual references must
give both the direct real-image URL and its source page; observations may
describe only visible appearance, never hidden ingredients, quantities or
method.

During a live research run the engine downloads up to three cited public HTTPS
images into memory, sends their bytes through a bounded vision pass, keeps only
the observations and the provenance, and discards the bytes. Search-text guesses
about how a dish looks are replaced rather than trusted.

## Files

- `lab.php`: the only command. Everything else is the engine.
- `report.php` and `report.css`: the human HTML report, rendered from one run.
- `lib/steps.php`: reading fixtures and saved runs off disk — the lab's own job.
- `fixtures/`: briefs across different cuisines, for offline and live comparison.
- `runs/`: generated runs and images. Ignored by Git.

The prompts live with the engine, in `includes/engine/prompts/`. They are its
data: it executes them, it carries them. There is no second copy to keep in
step: what the lab measures is what the plugin runs.

## Workflow

Never put an API key in a command, a fixture, a run file or a chat.

```bash
export OPENAI_API_KEY=sk-...

# One recipe, every step, with the report at the end.
php tools/lab.php run --brief=tarte-pommes --report=/tmp/tarte.html

# Part of a recipe, when only one wave is in question.
php tools/lab.php run --brief=tarte-pommes --only=research,canonical_recipe

# One step, against artifacts an earlier run produced.
php tools/lab.php step research --brief=tarte-pommes
php tools/lab.php step article --brief=tarte-pommes \
  --research=tools/runs/tarte-pommes-research-....json \
  --canonical=tools/runs/tarte-pommes-canonical_recipe-....json

# Is the judge judging, or guessing? Five verdicts over identical artifacts.
php tools/lab.php judge --brief=tarte-pommes --draws=5 \
  --article=tools/runs/....json --featured=tools/runs/....webp --facebook=tools/runs/....webp

# The report on its own, from any saved run.
php tools/lab.php report --run=tools/runs/tarte-pommes-....json --output=/tmp/tarte.html

# Housekeeping.
php tools/lab.php prune --dry-run  # apply the retention policy to tools/runs
```

Flags common to every command: `--provider`, `--tier`, `--model` (one route for
the whole pipeline, so the comparison is provider against provider),
`--image-model`, `--budget`, `--attempts`, `--quality`, `--max-output`.

`--research` and the other artifact flags name a saved run; omit them and the
package embedded in the fixture is used, which is fine for contract testing but
is not evidence of live web quality.

## Acceptance rule

A prompt is ready only after it passes on genuinely different briefs, including
title-led, article-led and image-led input. The fixture set covers pastry,
poultry, long-braised beef and a vegetable gratin. Read the saved output
yourself as well as the scorecard — source quality, real-image provenance,
culinary safety, unsupported claims and visual copying are not things a
scorecard settles.
