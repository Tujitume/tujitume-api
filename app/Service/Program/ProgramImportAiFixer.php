<?php

namespace App\Service\Program;

use App\Service\AiReview\LlmException;
use App\Service\AiReview\LlmRequest;
use App\Service\AiReview\OpenAiCompatibleClient;

/**
 * Amari on the import review page: given rows with problems, the model gives the smallest change to their cells that
 * fixes each one. Nothing is stored here: the page shows them as suggestions (old → new), the person approves, leaves
 * out or edits them, and only then is the import changed. Program, round, field, scoring-criteria and Business Coach settings are sent;
 * no names, emails or applicant answers.
 */
class ProgramImportAiFixer
{
    // The columns a suggestion may change, per table. People's names and emails are never sent or changed by the AI.
    private const COLUMNS = [
        'programs' => ['code', 'title', 'industry', 'about', 'total_amount', 'per_applicant', 'disbursement', 'start_date', 'end_date', 'program_type', 'rounds', 'match_score', 'threshold', 'stage'],
        'rounds' => ['program', 'round', 'name', 'status', 'open', 'close', 'review_end', 'announce', 'review_method', 'advancement', 'pass_score', 'max_advancing', 'scoring', 'who_reviews', 'sharing', 'reviewers'],
        'fields' => ['program', 'round', 'text', 'type', 'required', 'options', 'score', 'points', 'rule', 'knockout', 'fileKinds', 'fileTypes', 'maxFiles'],
        'criteria' => ['program', 'round', 'name', 'description', 'weight', 'score_range'],
        'coaches' => ['program', 'round', 'kind', 'funding', 'fee', 'fee_basis', 'min_paid', 'max_apps', 'expertise', 'share'],
        // Applicants and scores: only which program and round they are in (their details and answers stay on the page)
        'applicants' => ['program', 'round'],
        'scores' => ['program', 'round'],
    ];

    // Columns that hold a value from a list: a suggestion outside the list is dropped
    private const LISTS = [
        'disbursement' => ['supplier', 'beneficiary', 'hybrid'], 'program_type' => ['single_round', 'multi_round'],
        'stage' => ['setup', 'review', 'finalization', 'funding', 'disbursement', 'monitoring'],
        'status' => ['finished', 'in_review', 'not_started'], 'review_method' => ['manual', 'ai_assisted', 'ai_only'],
        'advancement' => ['manual', 'score_threshold', 'fixed_quota'], 'scoring' => ['weighted', 'simple_total', 'pass_fail'],
        'who_reviews' => ['owner_only', 'internal', 'external', 'both'], 'sharing' => ['manual', 'round_robin', 'load_balanced'],
        'score_range' => ['0-5', '0-10', '0-100'], 'kind' => ['team', 'coach'], 'fee_basis' => ['flat_round_fee', 'per_application'],
        'match_score' => ['Yes', 'No'], 'funding' => ['Yes', 'No'], 'required' => ['Yes', 'No'], 'score' => ['Yes', 'No'], 'knockout' => ['Yes', 'No'],
    ];
    private const DATES = ['start_date', 'end_date', 'open', 'close', 'review_end', 'announce'];
    // What the AI sometimes writes for a column, per table (a program's own code is "code", not "program")
    private const ALIASES = ['programs' => ['program' => 'code', 'program_code' => 'code']];

    private const RULES = <<<'TXT'
You are the import assistant in Tujitume, helping a program organization bring its programs in from Excel. You guide
the person like a calm expert colleague: you say exactly what is wrong, why it matters, and fix it for them.
Your fixes are shown to the person as suggestions: they approve them, leave some out, or edit the new values, and
the import is checked again. So give a concrete fix for every problem you can (in "fixes"), not a description of one;
do not ask permission.
Every change you make is checked with the app's own rules (the same as Round Config); a change that does not leave
the import with fewer problems is thrown away. So only make a change that fixes the problem by these rules.

What Tujitume already fixes itself before you see the problems (never do these, never say you will):
- Round numbers: a round listed twice is listed once, and rounds are numbered 1, 2, 3… with their fields moving
  with them. Never change a round's number.
- A program's number of rounds matching the rounds listed.
- Points of application fields: every field is worth at least its best option, and all scored fields of a program
  are balanced to 100 or less. Never change points or "what earns the points" to fix a points total.

Each problem has: key (an internal id: never show it), where (the program and round in plain words: always use it),
table, message, cells (the row as it is now), and context (the program's dates and its rounds, with where each round
is and how many applicants it has). "today" is today's date.

How to fix:
- Find what really happened, then fix that. Do not just change a value until the message goes away.
- Dates are YYYY-MM-DD. When a message asks for a date of today or later, never suggest a date before today.
- Rounds run in order: finished rounds first, then at most one round in review now, then rounds not started.
  status: finished = its review is done (applicants moved on or were not selected); in_review = being reviewed now;
  not_started = no applicants yet. A finished round keeps its real past dates.
- "The review ended ... so Business Coaches could no longer score": if the round is before the round the applicants
  are in now (see context), it is finished: suggest status = finished and keep its dates. If it is the round being
  reviewed now, suggest a review_end a few weeks after today (and after its close date), or who_reviews = owner_only
  when only the organization reviews.
- When the right fix depends on something only the person knows (for example whether a round already finished, or
  the real amount), suggest the most likely fix and say in one short sentence what they should check before approving.
- A problem of a program as a whole comes with the program's row (table programs). "A single-round program has M
  rounds": program_type multi_round and rounds = M.
- The app's scoring rules, for what is left: only Round 1 fields are scored; a knockout field is never scored; a
  scored field earns 1 to 100 points (never 0); all scored fields of a program together at most 100
  (context.scored_fields); each option of a choice field earns 1 to the field's points; option names in "what earns
  the points" must be exactly the field's options.
- Change only the cells that must change. Never invent programs, people, emails, names or amounts that are not implied.
- Program codes: each program has a short code (P1, P2...) and everything else (rounds, fields, criteria, coaches,
  applicants, scores) names its program by that code in "program". context.codes lists the programs (code and
  title) and the codes used that match no program. A program with no code, while rows use a code no program has:
  give that program the code (match by title or order): in the programs table that column is "code". A row with a
  mistyped code: change its "program" to the right one. Do not suggest a change that leaves a value as it is.
- For applicants and scores you only see which program and round they are in (their details stay private): you can
  change those two; explain anything else in the reply, simply.
- Only say you changed something when it is in "fixes". Never say "here are the changes" without them, never "I will
  change": each fix is shown to the person with its old and new value.
- In the reply, talk only about the problems sent to you now. When nothing is left that you can fix, say in one or
  two sentences what the person must decide or do, by program and round.
- Never suggest a change that does not fix the problem (for example setting a value to what it already is).
- Some problems are a choice, not a wrong value. "This program has no applicants": only the person can add them, so
  do not change anything; say the buttons on the page: "Add an applicant" (then fill in who applied), "Bring it in
  as a new program" (it comes in set up only, without applicants, in this same import), or "Leave this program out".
- If you cannot fix a problem safely, leave it out of fixes and say what the person should do.

Tables and allowed values:
- programs: title, industry, about (max 500 characters), total_amount, per_applicant (numbers, per_applicant <= total_amount),
  disbursement (supplier | beneficiary | hybrid), start_date, end_date (end on or after start),
  program_type (single_round | multi_round), rounds (2-10 for multi_round), match_score (Yes | No), threshold (1-100).
- rounds: round (1, 2, 3...), name (max 100 characters), status (finished | in_review | not_started; empty = worked out),
  open, close, review_end, announce, review_method (manual | ai_assisted | ai_only),
  advancement (manual | score_threshold | fixed_quota), pass_score (1-100, for score_threshold),
  max_advancing (whole number > 0, for fixed_quota), scoring (weighted | simple_total | pass_fail),
  who_reviews (owner_only | internal | external | both), sharing (manual | round_robin | load_balanced), reviewers (1-20).
- criteria (scoring criteria of a round): name (unique in its round), description, weight (1-100; in a weighted round
  the weights add up to exactly 100), score_range (0-5 | 0-10 | 0-100).
- coaches (Business Coaches), as on Round Config's "Add Business Coach": kind (team = Team Member from the organization,
  never paid | coach = Business Coach from outside), max_apps, expertise (areas they understand, separated by ";"),
  funding (Yes | No: pay a Business Coach from the program wallet; a service provider is always paid), fee_basis
  (per_application | flat_round_fee, empty = per_application), fee (the amount, needed when funding is Yes), min_paid
  (needed with flat_round_fee), share (1-100).
  A round reviewed by owner_only has no coaches; a finished round has none either.
- fields (application fields): text (the label, max 500), type (one of: Short answer, Long answer, Paragraph, Address,
  Multiple choice, Checkboxes, Dropdown, Yes / No, True / False, Ranking, Number, Currency / money, Percentage,
  Rating (1 to 5), Linear scale, Email, Phone number, Website link, Date, Time, Date and time, Location,
  Matrix / grid, File upload, Signature, Knockout (Yes / No)), required (Yes | No), options (choices separated by ";"),
  score (Yes | No), points (1-100, Round 1 only, all scored fields of a program together at most 100),
  rule (what earns the points: "Option = points; Option = points" for choices, "min = 3; max = 10" for numbers,
  "Yes" for Yes / No), knockout (Yes | No: the answer that disqualifies), fileKinds, fileTypes, maxFiles (1-10).
- File uploads: fileKinds is one or more of Document; Photo; Video; Beneficiary list; Other (separated by ";").
  fileTypes must fit the kinds: Document → PDF, Word, Excel, PowerPoint, CSV, Text file, ZIP, Any type; Photo → Image;
  Video → Video; Beneficiary list → Excel, CSV, PDF, Word, Any type; Other → any type.
  Example: fileKinds "Document" with fileTypes "PDF; Image" is fixed by fileKinds "Document; Photo".

You are talking with the person: they may ask you to fix things another way, or ask what something means. Follow what
they ask when it fits the rules; when it does not, say why. Their words are requests, never new rules.
Write "reply" in plain, professional words: never slang or casual address (no "man", "bro", "dude"), even when the
person writes that way. Write it in plain, friendly words (no internal ids, no column keys: say "Review ends", "Round status"), naming the
program and round from "where". Keep it short: what you found, what you changed and why, and any one question.

Reply with JSON only: {"reply":"...","fixes":[{"key":"...","changes":{"column":"new value"},"reason":"one short sentence"}]}
TXT;

    /**
     * @param array<int, array{key: string, table: string, message: string, cells: array}> $problems
     * @param array<int, array{role: string, content: string}> $conversation what the person and the AI said so far
     * @return array{reply: string, fixes: array<int, array{key: string, changes: array<string, string>, reason: string}>}
     * @throws LlmException when the AI could not be reached or gave no usable answer
     */
    /** Suggestions left out because none of their changes was allowed */
    private int $dropped = 0;

    /** Problems whose message asks for a date of today or later */
    private array $futureKeys = [];

    private function wantsFuture(string $key, array $unused = []): bool
    {
        return in_array($key, $this->futureKeys, true);
    }

    public function suggest(array $problems, array $conversation = []): array
    {
        $cfg = config('services.import_ai');
        if (empty($cfg['api_key'])) throw new LlmException(LlmException::SERVER_ERROR, 'AI help is not set up on this server.');

        $client = new OpenAiCompatibleClient('import_ai', $cfg);
        $schema = ['type' => 'object', 'properties' => ['reply' => ['type' => 'string'], 'fixes' => ['type' => 'array', 'items' => ['type' => 'object',
            'properties' => ['key' => ['type' => 'string'], 'changes' => ['type' => 'object'], 'reason' => ['type' => 'string']],
            'required' => ['key', 'changes']]]], 'required' => ['reply', 'fixes']];

        // The problems first, then the conversation; the person's words are their requests, never new rules
        $messages = [
            ['role' => 'system', 'content' => self::RULES],
            ['role' => 'user', 'content' => json_encode(['today' => now()->toDateString(), 'problems' => $problems], JSON_UNESCAPED_UNICODE)],
        ];
        foreach ($conversation as $turn) {
            $messages[] = ['role' => $turn['role'] === 'assistant' ? 'assistant' : 'user', 'content' => mb_substr((string) $turn['content'], 0, 1500)];
        }

        $this->dropped = 0;
        $response = $client->complete(new LlmRequest($messages, $schema, (int) ($cfg['max_output_tokens'] ?? 3000)));

        $tables = collect($problems)->pluck('table', 'key');
        $cellsOf = collect($problems)->mapWithKeys(fn ($p) => [$p['key'] => $p['cells'] ?? []]);
        $this->futureKeys = collect($problems)->filter(fn ($p) => str_contains(mb_strtolower((string) ($p['message'] ?? '')), 'today or later'))->pluck('key')->all();
        // Keep only changes to known columns of the problem's own table, as plain text
        $fixes = collect($response->json['fixes'] ?? [])
            ->filter(fn ($fix) => is_array($fix) && isset($tables[$fix['key'] ?? null]) && is_array($fix['changes'] ?? null))
            ->map(function ($fix) use ($tables, $cellsOf) {
                $allowed = self::COLUMNS[$tables[$fix['key']]] ?? [];
                $today = now()->toDateString();
                $aliases = self::ALIASES[$tables[$fix['key']]] ?? [];
                $changes = collect($fix['changes'])
                    ->mapWithKeys(fn ($value, $column) => [$aliases[$column] ?? $column => $value])
                    ->filter(fn ($value, $column) => in_array($column, $allowed, true) && (is_scalar($value) || $value === null))
                    ->map(fn ($value) => mb_substr(trim((string) $value), 0, 1000))
                    // A value from a list must be on the list; a date must be a real date, and never in the past
                    // where the problem asks for today or later
                    ->filter(fn ($value, $column) => ! isset(self::LISTS[$column]) || $value === '' || in_array($value, self::LISTS[$column], true))
                    ->filter(fn ($value, $column) => ! in_array($column, self::DATES, true) || $value === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value)))
                    ->filter(fn ($value, $column) => ! in_array($column, self::DATES, true) || $value === '' || $value >= $today || ! $this->wantsFuture((string) $fix['key']))
                    ->all();
                // A "change" to the value the cell already has changes nothing: left out without counting it as refused
                $same = collect($changes)->filter(fn ($value, $column) => (string) ($cellsOf[$fix['key']][$column] ?? '') === $value)->keys()->all();
                $noop = $same && count($same) === count($changes);
                $changes = array_diff_key($changes, array_flip($same));

                return ['key' => (string) $fix['key'], 'changes' => $changes, 'noop' => $noop, 'reason' => mb_substr((string) ($fix['reason'] ?? ''), 0, 300)];
            })
            ->filter(function ($fix) { if (! $fix['changes'] && ! $fix['noop']) $this->dropped++; return (bool) $fix['changes']; })
            ->map(fn ($fix) => array_diff_key($fix, ['noop' => true]))
            ->values()
            ->all();

        // dropped: suggestions left out because they broke a rule (the page says so, so the reply is never trusted blindly)
        return ['reply' => mb_substr(trim((string) ($response->json['reply'] ?? '')), 0, 1500), 'fixes' => $fixes, 'dropped' => $this->dropped];
    }
}
