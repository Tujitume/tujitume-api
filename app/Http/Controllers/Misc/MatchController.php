<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Service\AiScore\MatchScore;
use App\Models\Programs\Program;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Service\Program\RoundMatchScore;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    // A guest works out the score before applying: same calculation, the program is found by its public id
    public function scorePublic(Request $request, string $publicId)
    {
        $program = Program::where('public_id', $publicId)->where('status', 'published')->firstOrFail();

        return $this->score($request, $program->id);
    }

    public function score(Request $request, $program_id)
    {
        $customProgram = Program::find($program_id);
        $fieldValues = app(\App\Service\Program\CustomFields\CustomFieldValueService::class);
        $customFields = $customProgram ? $fieldValues->applicationFields($customProgram) : collect();
        if ($customFields->isNotEmpty() && $request->has('custom_answers')) {
            // Preview from the program's own fields: nothing is saved, and the applicant sees matched / partial / not matched only
            $rules = collect($fieldValues->rules($customFields))->map(fn ($r) => array_values(array_diff($r, ['required'])) ?: ['nullable'])->all();
            $answers = $request->validate($rules)['custom_answers'] ?? [];
            $typed = [];
            foreach ($customFields as $f) {
                $v = $request->hasFile("custom_answers.{$f->key}") ? 'file' : ($answers[$f->key] ?? null);
                $typed[$f->key] = match ($f->type) { 'number' => $v === null ? null : (float) $v, 'boolean' => $v === null ? null : filter_var($v, FILTER_VALIDATE_BOOLEAN), default => $v };
            }
            $engine = app(\App\Service\Program\MatchScore\MatchScoreEngine::class);
            $computed = $engine->compute($customProgram, $typed);
            $limit = $customProgram->match_score_limit;

            return response()->json(['match_score' => $computed['score'], 'below_threshold' => $limit !== null && $computed['score'] !== null && $computed['score'] < $limit, 'breakdown_for_applicant' => $engine->applicantView($computed)], 200);
        }
        $validated = $request->validate([
            'round_answers' => 'nullable|array',
            'round_answers.*.question_id' => 'required_with:round_answers|integer',
            'round_answers.*.response' => 'nullable',
            'round_answers.*.file' => 'nullable|file|max:10240',
            'round_answers.*.files' => 'nullable|array|max:'.RoundMatchScore::MAX_FILES_PER_QUESTION,
            'round_answers.*.files.*' => 'file|max:10240',
        ]);
        $program = Program::findOrFail($program_id);
        $answers = $validated['round_answers'] ?? [];
        $round = $program->rounds()->where('round_number', 1)->first();

        if ($round) {
            foreach ($answers as $index => &$answer) {
                $question = RoundCustomQuestion::where('round_id', $round->id)
                    ->findOrFail($answer['question_id']);
                $files = array_values(array_filter([
                    $request->file("round_answers.{$index}.response") ?? $request->file("round_answers.{$index}.file"),
                    ...(array) $request->file("round_answers.{$index}.files"),
                ]));
                if ($files) {
                    if ($question->question_type !== 'file_upload') {
                        abort(422, 'The uploaded file is not allowed for this question.');
                    }
                    app(RoundMatchScore::class)->validateFileSet($files, $question, 'round_answers');
                    $answer['response'] = $files[0];
                }
            }
            unset($answer);
        }

        $computed = app(RoundMatchScore::class)->scoreRequest($program, $answers);

        $applicantBreakdown = collect($computed['breakdown'] ?? [])
            ->map(function ($item) {
                $status = in_array($item['status'] ?? null, ['matched', 'partial'], true) ? $item['status'] : 'not matched';
                return [
                    'question_id' => $item['question_id'] ?? null,
                    'question_text' => $item['question_text'] ?? null,
                    'status' => $status,
                ];
            })->values()->all();

        $payload = [
            'match_score' => $computed['match_score'],
            'below_threshold' => $computed['below_threshold'],
            'breakdown_for_applicant' => $applicantBreakdown,
        ];

        return response()->json($payload, 200);
    }

    public function score_capital(MatchScore $match, Request $request, $capital_id)
    {
        $score = $match->capital($request, $capital_id);
        return response()->json($score,200);
    }
}
