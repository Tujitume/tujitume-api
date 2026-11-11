## Scoring

`POST /applications/{application}/scores` and `POST /rounds/{round}/applications/auto-score`. Assigned reviewers submit every configured criterion. Responses return `422` for incomplete criteria or invalid round state.

## Scores from AI

`application_scores` rows now have `source` (`human` default, or `ai`) and `ai_review_id`. In `ai_assisted` rounds AI writes no rows. In `ai_only` rounds AI writes one row per application as the system user *Tujitume AI Reviewer*. The average uses human rows when any exist; AI rows count only while no human has scored. Rubric maths is unchanged (shared `RubricCalculator`). See [AI review](../ai-review.md).
