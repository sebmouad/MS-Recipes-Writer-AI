# Lab results

Every row is one real API call made by `tools/prompt-lab.php`, scored by the
plugin's own quality gate. Raw answers stay out of git (`tools/runs/` is
ignored, 4 MB and regenerable); this table is the record. Regenerate a row with:

```bash
php tools/prompt-lab.php run <step> --variant=tpl --provider=<openai|gemini|claude> --tier=<low|medium|high>
```

Measured 2026-09-20. Prices from `tools/lib/pricing.php`, verified the same day.

| Step | Variant | Provider | Tier | Model | Seconds | Cost USD | Score | Stop reason |
|---|---|---|---|---|---:|---:|:---:|---|
| article | en | claude | high | `claude-opus-5` | 147.1 | 0.3257 | 9/10 | end_turn |
| article | en | claude | low | `claude-haiku-4-5-20251001` | 70.8 | 0.0431 | 8/10 | end_turn |
| article | en | claude | medium | `claude-sonnet-5` | 91.4 | 0.0897 | 1/10 | end_turn |
| article | shipped | claude | medium | `claude-sonnet-5` | 148.2 | 0.1513 | 1/10 | max_tokens |
| article | en | gemini | low | `gemini-3.1-flash-lite` | 12.5 | 0.0052 | 6/10 | STOP |
| article | en | openai | high | `gpt-5.6-sol` | 106.5 | 0.1496 | 7/10 | completed |
| article | en | openai | low | `gpt-5-nano-2025-08-07` | 63.3 | 0.0041 | 7/10 | completed |
| article | en | openai | medium | `gpt-5.6-luna` | 55.6 | 0.0085 | 9/10 | completed |
| article | tpl | openai | medium | `gpt-5.6-luna` | 36.5 | 0.0080 | 10/10 | completed |
| article_full | tpl | claude | medium | `claude-sonnet-5` | 157.5 | 0.1642 | 2/12 | max_tokens |
| article_full | tpl | openai | medium | `gpt-5.6-luna` | 63 | 0.0083 | 9/12 | completed |
| canonical_recipe | en | claude | high | `claude-opus-5` | 69.1 | 0.1353 | 3/4 | end_turn |
| canonical_recipe | en | claude | low | `claude-haiku-4-5-20251001` | 15.6 | 0.0116 | 4/4 | end_turn |
| canonical_recipe | en | claude | medium | `claude-sonnet-5` | 32.4 | 0.0355 | 3/4 | end_turn |
| canonical_recipe | en | gemini | low | `gemini-3.1-flash-lite` | 3.9 | 0.0015 | 3/4 | STOP |
| canonical_recipe | en | gemini | medium | `gemini-3.5-flash` | 20.6 | 0.0164 | 4/4 | STOP |
| canonical_recipe | en | openai | high | `gpt-5.6-sol` | 69 | 0.0825 | 3/4 | completed |
| canonical_recipe | en | openai | low | `gpt-5-nano-2025-08-07` | 40.4 | 0.0035 | 4/4 | completed |
| canonical_recipe | en | openai | medium | `gpt-5.6-luna` | 20.5 | 0.0031 | 3/4 | completed |
| fact_check | en | claude | high | `claude-opus-5` | 15.6 | 0.0893 | 5/5 | end_turn |
| fact_check | en | claude | low | `claude-haiku-4-5-20251001` | 3.8 | 0.0107 | 4/5 | end_turn |
| fact_check | en | claude | medium | `claude-sonnet-5` | 35.6 | 0.0570 | 5/5 | end_turn |
| fact_check | en | gemini | low | `gemini-3.1-flash-lite` | 2.3 | 0.0019 | 5/5 | STOP |
| fact_check | en | gemini | medium | `gemini-3.5-flash` | 25.5 | 0.0116 | 5/5 | STOP |
| fact_check | en | openai | high | `gpt-5.6-sol` | 53.1 | 0.1093 | 5/5 | completed |
| fact_check | en | openai | low | `gpt-5-nano-2025-08-07` | 50.4 | 0.0043 | 5/5 | completed |
| fact_check | en | openai | medium | `gpt-5.6-luna` | 24.4 | 0.0047 | 5/5 | completed |
| proofread | en | claude | high | `claude-opus-5` | 88.9 | 0.3250 | 6/6 | end_turn |
| proofread | en | claude | low | `claude-haiku-4-5-20251001` | 74 | 0.0436 | 6/6 | end_turn |
| proofread | en | claude | medium | `claude-sonnet-5` | 125.2 | 0.1595 | 0/6 | max_tokens |
| proofread | en | gemini | low | `gemini-3.1-flash-lite` | 17 | 0.0096 | 6/6 | STOP |
| proofread | en | gemini | medium | `gemini-3.5-flash` | 49.8 | 0.0451 | 0/6 | MAX_TOKENS |
| proofread | en | openai | high | `gpt-5.6-sol` | 100.9 | 0.1668 | 6/6 | completed |
| proofread | en | openai | low | `gpt-5-nano-2025-08-07` | 70.7 | 0.0059 | 0/6 | incomplete |
| proofread | en | openai | medium | `gpt-5.6-luna` | 41.4 | 0.0091 | 6/6 | completed |
| research | en | claude | high | `claude-opus-5` | 36.2 | 0.3379 | 0/5 | end_turn |
| research | shipped | claude | high | `claude-opus-5` | 42 | 0.1875 | 5/5 | end_turn |
| research | en | claude | low | `claude-haiku-4-5-20251001` | 17 | 0.0387 | 5/5 | end_turn |
| research | en | claude | medium | `claude-sonnet-5` | 61.4 | 0.1091 | 5/5 | end_turn |
| research | en | openai | high | `gpt-5.6-sol` | 60.3 | 0.2634 | 5/5 | completed |
| research | en | openai | low | `gpt-5-nano-2025-08-07` | 82.3 | 0.0076 | 0/5 | incomplete |
| research | en | openai | medium | `gpt-5.6-luna` | 29.4 | 0.0104 | 5/5 | completed |
| research | tpl | openai | medium | `gpt-5.6-luna` | 38 | 0.0124 | 8/8 | completed |
| review | en | claude | high | `claude-opus-5` | 29.2 | 0.1230 | 3/3 | end_turn |
| review | en | claude | low | `claude-haiku-4-5-20251001` | 0.8 | 0.0091 | 3/3 | end_turn |
| review | en | claude | medium | `claude-sonnet-5` | 38 | 0.0580 | 3/3 | end_turn |
| review | en | gemini | low | `gemini-3.1-flash-lite` | 3 | 0.0018 | 3/3 | STOP |
| review | en | gemini | medium | `gemini-3.5-flash` | 14 | 0.0106 | 3/3 | STOP |
| review | en | openai | high | `gpt-5.6-sol` | 76.1 | 0.1153 | 3/3 | completed |
| review | en | openai | low | `gpt-5-nano-2025-08-07` | 14.4 | 0.0014 | 3/3 | completed |
| review | en | openai | medium | `gpt-5.6-luna` | 26.7 | 0.0043 | 3/3 | completed |
