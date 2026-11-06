# Match-score frontend migration notes

This migration replaces program-level custom fields with Round 1 questions and server-calculated question scoring.

## Removed program fields and endpoints

Do not read or submit removed fixed program properties such as `eligibility_criteria`, `required_documents`, `program_focus`, `regions`, `startup_stage_focus`, `impact_objectives`, `social_impact_areas`, `bonus_points`, `evaluation_criteria`, `funder_type`, `program_brief_pdf`, `start_date`, or `end_date` as persisted program properties. `program_industry_id` remains a core field. Use `application_start_date` and `application_deadline` for program dates.

Do not call program custom-field endpoints or submit a `custom_fields` array. The former program custom-field model, values, and API have been removed. Configure questions through the round endpoints; questions belong to a round rather than the program.

| Old UI / payload concept | New location |
|---|---|
| Application form fields or eligibility questions | `POST /rounds/{round}/questions`; initial applicant form questions belong to Round 1 |
| Program-level match score or bonus considerations | Program `use_match_score` and Round 1 question `use_in_match_score`/`match_points`/`match_answers` |
| Round-specific file requirements | `application_round.required_documents` and `round_documents` |
| Knockout screening | Round question type `knockout` / `knockout_yes_no`; never score it |
| Program open date | `application_start_date` |
| Application close date | `application_deadline` |

## New application payload

Keep the existing required application identity/business fields and send the dynamic form answers through `round_answers`. Do not send `custom_answers`.

```json
{
  "business_id": 84,
  "startup_name": "Northstar Foods",
  "contact_person_name": "Amina Otieno",
  "contact_person_email": "amina@example.test",
  "sector": "Food processing",
  "headquarters_location": "Nairobi, Kenya",
  "total_amount_requested": 40000,
  "round_answers": [
    {"question_id": 41, "response": "[\"Niagara\",\"NY\"]"},
    {"question_id": 42, "response": "6"},
    {"question_id": 43, "response": "{\"region\":\"East Africa\",\"country\":\"Kenya\"}"}
  ]
}
```

Send structured checkbox, ranking, location, and matrix answers as JSON-encoded strings. Use multipart form data for file question answers:

```text
round_answers[0][question_id]=43
round_answers[0][response]=<attach the file>
```

The uploaded file must satisfy the question's `options`. The frontend file picker and backend both enforce the accepted file types.

## Client match scores are ignored

Do not calculate or submit a score as authoritative. `match_score` and `score_breakdown` in a request are ignored. The backend computes and stores the score from Round 1 answers. The preview endpoint is `POST /match-score/{program_id}` and accepts `round_answers`; it returns only the score, threshold status, and applicant-safe question match statuses.

The threshold is informative for the program owner. It does not prevent application submission or automatically reject an applicant. Hide the match-score preview when the program has scoring disabled. The preview can be calculated before submission but is optional.

## Frontend rollout checklist

- [ ] Remove program field bindings and payload properties for removed columns; do not send `custom_fields` or `custom_answers`.
- [ ] Read `application_round.questions` from the program application-info response to build the question form.
- [ ] Render matrix rows and columns from `row:` and `col:` option values.
- [ ] Restrict file upload pickers to the configured file categories/types and submit one file per file question.
- [ ] Serialize structured answers as JSON strings in `round_answers`.
- [ ] Include the question ID on each answer and send knockout answers in the same round-answer list.
- [ ] Stop treating client-side match score or breakdown as authoritative.
- [ ] Allow submission whether the optional preview has been calculated or not, and regardless of whether the score is below threshold.
- [ ] Do not render `match_points` or `match_answers` in applicant form/question resources.
- [ ] Use `application_start_date` and `application_deadline` for program date display.
