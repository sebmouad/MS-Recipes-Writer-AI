# Prompt lab

Proves a prompt before it reaches the plugin. Runs one pipeline step against
the real API with no WordPress and no database, scores the answer with the
plugin's own quality gate, and records the time and cost.

```bash
export OPENAI_API_KEY=sk-...                 # never committed, never in chat
php tools/prompt-lab.php list                # steps and what each must produce
php tools/prompt-lab.php show article        # the prompt that ships today
php tools/prompt-lab.php run article         # measure the shipped prompt
php tools/prompt-lab.php run article --variant=v2 --brief=tarte-pommes
php tools/prompt-lab.php promote article v2  # what to paste into the defaults
```

## How it works

- The shipped prompt is read from `MSRWA_Settings::defaults()`, so `run`
  without a variant always measures exactly what the plugin ships.
- A candidate lives in `tools/prompts/<step>.<variant>.txt`. Keep variants
  small and name what they change, for example `article.v2-outline.txt`.
- The input is assembled the way the pipeline assembles it (`tools/lib/steps.php`),
  so a result here transfers to production.
- Scoring reuses `MSRWA_Quality` and `MSRWA_Recipe`, plus the outline the
  specification requires. A run exits non-zero when a check fails, so it can
  gate a loop.
- Every run is written to `tools/runs/` (ignored by git) with the prompt, the
  output, the usage, the cost and the scorecard.

## Working method

1. `run <step>` to get the baseline: score, seconds, cost.
2. Write a variant that changes **one** thing.
3. `run <step> --variant=...` and compare the scorecard and the cost.
4. Repeat until every check passes on at least three different briefs.
5. `promote` and paste the winner into the default in
   `includes/class-msrwa-settings.php`. Those defaults seed the `prompts`
   table, and the settings screen keeps every version.

Briefs live in `tools/fixtures/*.json` and carry the upstream data a step
needs, so any step can be tested on its own. Add a second and a third brief
from different cuisines before promoting anything: a prompt tuned on one
recipe usually overfits.
