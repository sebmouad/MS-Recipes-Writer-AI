# Measured and rejected

Prompts that were built, run against the real API and scored, then not adopted.
They stay here so a settled question is not reopened every few months, and so
the next person can re-run the comparison rather than take it on trust.

They are not compiled into the settings and do not ship. `tools/prompt-lab.php`
can still run them: the step names them explicitly.

## `research_recipe.tpl.txt` — research and the canonical recipe in one call

Rejected 2026-09-21. It works: 17/17 on the poulet yassa, valid recipe, 10
ingredients, 12 steps. It simply buys nothing.

| | Time | Cost |
|---|---:|---:|
| Research + recipe, separate | 49.5s + 21.8s = **71.3s** | $0.0183 + $0.0047 = **$0.0230** |
| Merged | **84.5s** | **$0.0227** |

A 1.3% saving, inside the noise, for 13 seconds more: one call's 8 635 output
tokens generate sequentially where two calls generate 6 943 then 3 162.

Three reasons beyond the numbers:

1. A recipe the validator rejects costs $0.0047 to retry today, and $0.0227
   merged — five times more for the same failure.
2. Research records what the sources say, disagreements included; the canonical
   recipe decides between them. One call doing both can settle a conflict
   silently instead of recording it.
3. Identical replays cache at 99.96%, so a larger replayed call pays more of the
   uncached remainder.

Re-run it with:

```bash
php tools/prompt-lab.php run research_recipe --brief=poulet-yassa --provider=openai --tier=medium
```
