# Lab results

Every row is one real API call made by `tools/prompt-lab.php`, scored by the
plugin's own quality gate. Raw answers stay out of git (`tools/runs/` is
ignored, 4 MB and regenerable); this table is the record. Regenerate a row with:

```bash
php tools/prompt-lab.php run <step> --variant=tpl --provider=<openai|gemini|claude> --tier=<low|medium|high>
```

Measured 2026-09-20. Prices from `tools/lib/pricing.php`, verified the same day.

## Image quality and the Facebook collage — measured 2026-09-20

`gpt-image-2.5-flare`, tarte normande, featured image 1024×1024 and collage
1024×1536. Every row is one real generation; every verdict comes from
`tools/approval-lab.php` judging that image against the same article and recipe.

### What a tier costs

| Quality | Image tokens | Seconds | Featured cost | Collage cost |
|---|---:|---:|---:|---:|
| `low` | 196 / 158 | 8.7 / 14.5 | $0.0104 | $0.0151 |
| `medium` | 439 / 343 | 10.1 / 13.7 | $0.0177 | $0.0207 |
| `high` | 1756 / 1372 | 18.0 / 20.4 | $0.0572 | $0.0516 |

### What a tier buys

The featured image was judged `good` for both verdict and realism at **every
tier**, including `low`. Raising its quality bought no editorial improvement that
the approval step could detect.

The collage is a different matter, and not in the way a price list suggests:

| Collage | Quality | Approved | Why refused |
|---|---|---|---|
| 233143 | medium | no | crockery break between collage and featured |
| 234434 | medium | no | crockery break, panel 5 to panel 6 |
| 234448 | medium | no | steps out of order; cooling rack in panel 6 |
| 234209 | high | **yes** | — |
| 234502 | high | no | steps out of order; final panel over-browned |
| 234743 | medium + fixed prompt | no | steps out of order; vessel family differs |
| 234757 | medium + fixed prompt | **yes** | — |
| 234811 | medium + fixed prompt | no | steps out of order; vessel and light change |

**Quality tier is not the lever.** `high` costs 2.5× and still failed one run in
two. Both tiers fail the same two ways: panels out of the recipe's order, and a
serving vessel that appears nowhere else in the sequence.

The order failures had a cause in our own prompt. The generic panel roles named
"whisking, mixing" as panel 2, which for a tart pushes the custard ahead of
lining the case — exactly the error the judge caught twice. The prompt now
subordinates the roles to the canonical step order. That moved medium from 0/3
to 1/3 approved, which is an improvement and not a fix.

**Open question for the owner.** One image generation is being asked to compose
six ordered, mutually consistent scenes, and it does not do so reliably. Two
routes, priced:

- *Retry until approved.* At 1 in 3, an accepted medium collage costs about
  three generations plus three judgements: roughly $0.077.
- *Generate six panels separately and compose the grid ourselves.* Order becomes
  guaranteed by construction and each panel can carry the previous one as a
  reference for continuity. Six `low` panels cost $0.062, six `medium` panels
  $0.106, and the composition itself is free.

The second costs about the same as retrying and is deterministic, but it is an
architecture change rather than a prompt change, so it is not made here.

## Per-step matrix

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
