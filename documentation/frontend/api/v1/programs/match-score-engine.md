# Match score engine

`App\Service\Program\MatchScore\MatchScoreEngine` computes the score on the server from a program's scoring fields. It is pure: no eval, table-driven strategies, no database inside the strategies.

## Scoring config (validated server side)
```json
{ "enabled": true, "weight": 20, "match_type": "in_set|range|equals|provided", "target": "...", "mode": "any|proportional|all" }
```
| match_type | field types | target | credit |
|---|---|---|---|
| in_set | select, multi_select | non-empty subset of the options | select: 1 if in target. multi_select `any`: overlap >= 1; `proportional`: overlap / target size; `all`: 1 only if target is a subset of the answer |
| range | number, date | `{min?, max?}` at least one, min <= max | 1 if inside (inclusive) |
| equals | boolean | true or false | 1 if equal |
| provided | any | none | 1 if a non-empty answer or file exists |

`weight` is greater than 0 and at most 1000 (relative). Only application fields can score. text/textarea/url can only use `provided`.

## Calculation
base = sum(weight_i x match_i) / sum(weight_i) x 100 over non-bonus scoring fields; unanswered fields count 0 but stay in the denominator. Rounded half up to 0..100. `is_bonus` fields add their weight x match as points after normalization, only when base >= the program's `match_score_limit` (always when null). Capped at 100. No non-bonus scoring field = `null` score.

Breakdown item: `{field_id, key, label, match_type, weight, normalized_weight, match, earned_points, answer, target}` stored in `program_applications.score_breakdown` with the integer in `match_score`. Applicants only ever see matched / partial / not matched.

## Worked examples
| Field | Setup | Answer | match |
|---|---|---|---|
| Interested places | multi_select, in_set proportional, target [Niagara, London], weight 20 | [Niagara] | 0.5 |
| Business age (years) | number, range 2..10, weight 30 | 5 | 1 |
| Business plan PDF | file, provided, weight 10 | uploaded | 1 |

Score = (20 x 0.5 + 30 x 1 + 10 x 1) / 60 x 100 = 83.33, stored as 83.
