# Prompt and image lab

The lab tests the maintained research-centred pipeline against real providers,
without WordPress or a database:

```text
editor brief (title, article or images)
  -> sourced research package (text facts + observations from real images)
  -> canonical recipe
  -> article in one call
  -> featured image / Facebook process collage
  -> review / fact-check / proofreading
```

Every downstream stage receives the same research package. Visual references
must identify both the direct real-image URL and its source page; observations
may describe only visible appearance and must not infer hidden ingredients,
quantities or method.

During a live research run, the lab downloads up to three cited public HTTPS
images into memory, sends their bytes through a bounded vision pass, saves only
the resulting observations and provenance, and discards the bytes. Search-text
guesses are replaced rather than trusted.

## Files

- `prompt-lab.php`: all text stages and scorecards.
- `image-lab.php`: featured and Facebook image generation.
- `lib/steps.php`: input contracts and deterministic scoring.
- `lib/providers.php`: provider transports, including image generation.
- `lib/pricing.php`: comparison tiers and estimated API cost.
- `prompts/`: exactly one maintained prompt per stage.
- `fixtures/`: two different cuisines for offline and API comparisons.

Generated runs belong in `tools/runs/`, which is ignored by Git.

## Workflow

Never put an API key in a command, fixture, run file or chat.

```bash
export OPENAI_API_KEY=sk-...

php tools/prompt-lab.php list
php tools/prompt-lab.php run research --brief=tarte-pommes
php tools/prompt-lab.php run canonical_recipe \
  --brief=tarte-pommes --research=tools/runs/research-maintained-openai-x-....json
php tools/prompt-lab.php run article \
  --brief=tarte-pommes --research=tools/runs/research-maintained-openai-x-....json \
  --canonical=tools/runs/canonical_recipe-maintained-openai-x-....json
php tools/prompt-lab.php run review \
  --brief=tarte-pommes --research=tools/runs/research-maintained-openai-x-....json \
  --canonical=tools/runs/canonical_recipe-maintained-openai-x-....json \
  --article=tools/runs/article-maintained-openai-x-....json
```

The same `--research` package is required for canonical recipe, article,
review, fact-check, proofreading and both image commands. If omitted, the lab
uses the package embedded in the selected fixture, which is suitable for
contract testing but not evidence of live web quality.

```bash
php tools/image-lab.php featured --brief=tarte-pommes --research=tools/runs/research-maintained-openai-x-....json
php tools/image-lab.php facebook --brief=tarte-pommes --research=tools/runs/research-maintained-openai-x-....json \
  --canonical=tools/runs/canonical_recipe-maintained-openai-x-....json
```

Maintained prompts are used by default. Add `--shipped=1` to `show` or `run`
to compare the plugin's current configured default. A temporary experiment may
use `--variant=name` and `tools/prompts/<step>.name.txt`; delete it after the
comparison so the directory remains an inventory of active contracts.

## Acceptance rule

A prompt is ready only after it passes on genuinely different briefs,
including title-led, article-led and image-led input. The maintained fixture
set also includes pastry, poultry, long-braised beef and a vegetable gratin. Check
the saved output manually as well as the scorecard, especially source quality,
real-image provenance, culinary safety, unsupported claims and visual copying.
