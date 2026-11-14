<?php

namespace App\Service\AiReview;

use App\Models\Programs\Ai\ApplicationAiFinding;
use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use Illuminate\Support\Facades\Storage;

/** Shapes an AI review for the owner / assigned reviewer. Every item says whether it came from the AI or a person. */
class AiReviewResource
{
    public static function make(ApplicationAiReview $review, ?ProgramRound $round = null): array
    {
        $data = [
            'id' => $review->id, 'status' => $review->status, 'mode' => $review->mode, 'origin' => 'ai',
            'provider' => $review->provider, 'model' => $review->model, 'prompt_version' => $review->prompt_version, 'outdated' => $review->status === 'ready' && $review->prompt_version !== config('ai_review.prompt_version'),
            'summary' => $review->summary,
            'suggested_scores' => collect($review->suggested_scores ?? [])->map(fn ($s) => $s + ['origin' => 'ai'])->all(),
            'suggested_total' => $review->suggested_total,
            'deterministic_checks' => $review->deterministic_checks ?? [],
            'missing_items' => $review->missing_items ?? [],
            'extraction_notes' => $review->extraction_notes ?? [],
            'quality' => $review->quality ?? [],
            'error' => $review->error,
            'completed_at' => $review->completed_at,
            'findings' => $review->findings->map(fn ($f) => self::finding($f))->values()->all(),
        ];

        if ($round) {
            $questions = RoundCustomQuestion::where('round_id', $round->id)->where('question_type', '!=', 'knockout')
                ->orderBy('display_order')->get();
            $answers = ApplicationRoundResponse::where(['application_id' => $review->application_id, 'round_id' => $round->id])
                ->get()->keyBy('question_id');
            $questionDetails = $questions->map(fn ($q) => self::questionAnswer($q, $answers->get($q->id)))->values();
            $suggested = collect($review->suggested_scores ?? [])->keyBy('criterion');
            $calculator = app(\App\Service\Program\RubricCalculator::class);
            $data['questions_and_answers'] = $questionDetails->all();
            $data['criteria_reviews'] = collect($round->scoring_criteria ?? [])->map(function ($criterion) use ($suggested, $questions, $answers, $calculator, $round) {
                $score = $suggested->get($criterion['name'] ?? '');
                $question = self::questionForCriterion($criterion, $questions);
                $max = $calculator->maxScoreFor($criterion);
                $rawScore = isset($score['score']) ? (float) $score['score'] : null;
                $weight = isset($criterion['weight']) ? (float) $criterion['weight'] : null;
                $weightedPoints = $rawScore !== null && $weight !== null && ($round->rubric_mode ?? 'simple_total') === 'weighted'
                    ? round(($rawScore / $max) * $weight, 2)
                    : null;

                return [
                    'criterion' => $criterion['name'] ?? null,
                    'description' => $criterion['description'] ?? null,
                    'score' => $rawScore,
                    'max_score' => $max,
                    'weight_percent' => $weight,
                    'weighted_points' => $weightedPoints,
                    'rationale' => $score['rationale'] ?? $score['comment'] ?? null,
                    'question' => $question ? self::questionAnswer($question, $answers->get($question->id)) : null,
                    'origin' => 'ai',
                ];
            })->values()->all();
        }

        return $data;
    }

    private static function questionForCriterion(array $criterion, $questions): ?RoundCustomQuestion
    {
        $description = (string) ($criterion['description'] ?? '');
        if (preg_match('/\bQ\s*(\d+)\b/i', $description, $match)) {
            $question = $questions->firstWhere('display_order', (int) $match[1]);
            if ($question) return $question;
        }

        $normalized = mb_strtolower(preg_replace('/[^a-z0-9]+/i', ' ', ($criterion['name'] ?? '').' '.$description));
        foreach ($questions as $question) {
            $questionText = mb_strtolower(preg_replace('/[^a-z0-9]+/i', ' ', (string) $question->question_text));
            $words = array_values(array_filter(explode(' ', $questionText), fn ($word) => mb_strlen($word) > 3));
            if ($words && collect($words)->contains(fn ($word) => str_contains($normalized, $word))) return $question;
        }

        return null;
    }

    private static function questionAnswer(RoundCustomQuestion $question, ?ApplicationRoundResponse $response): array
    {
        $text = trim((string) ($response?->response ?? ''));
        if ($text !== '') {
            $decoded = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_scalar($decoded))) $text = $decoded;
        }
        $files = [];
        foreach ($response?->allFilePaths() ?? [] as $path) {
            try {
                $url = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(45), ['ResponseContentDisposition' => 'inline']);
            } catch (\Throwable $e) {
                $url = null;
            }
            $files[] = ['name' => basename($path), 'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)), 'url' => $url];
        }

        return [
            'id' => $question->id,
            'number' => $question->display_order,
            'text' => $question->question_text,
            'type' => $question->question_type,
            'required' => (bool) $question->is_required,
            'answer' => $text !== '' ? $text : null,
            'files' => $files,
            'answered' => $text !== '' || count($files) > 0,
        ];
    }

    public static function finding(ApplicationAiFinding $f): array
    {
        $ref = $f->source_ref ?? [];
        $source = null;
        if ($f->source_type) {
            $source = ['type' => $f->source_type, 'id' => $ref['id'] ?? null, 'label' => $ref['label'] ?? null, 'page' => $f->page, 'url' => null];
            if (! empty($ref['file_path'])) {
                try {
                    $source['url'] = Storage::disk('s3')->temporaryUrl($ref['file_path'], now()->addMinutes(45), ['ResponseContentDisposition' => 'inline']);
                } catch (\Throwable $e) {
                    $source['url'] = null;
                }
            }
            if (($ref['type'] ?? null) === 'answer' && ! empty($ref['response_id'])) {
                $source['question_id'] = ApplicationRoundResponse::where('id', $ref['response_id'])->value('question_id');
            }
        }

        return [
            'id' => $f->id, 'origin' => $f->author_type, 'dimension' => $f->dimension, 'polarity' => $f->polarity,
            'title' => $f->title, 'body' => $f->body, 'confidence' => $f->author_type === 'ai' ? $f->confidence : null,
            'status' => $f->status, 'quote' => $f->quote, 'quote_verified' => (bool) $f->quote_verified,
            'source' => $source, 'original_body' => $f->original_body,
            'reviewed_by' => $f->reviewed_by, 'reviewed_at' => $f->reviewed_at, 'reviewer_note' => $f->reviewer_note,
            'author_user_id' => $f->author_user_id,
        ];
    }
}
