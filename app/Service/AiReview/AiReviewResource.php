<?php

namespace App\Service\AiReview;

use App\Models\Programs\Ai\ApplicationAiFinding;
use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use Illuminate\Support\Facades\Storage;

/** Shapes an AI review for the owner / assigned reviewer. Every item says whether it came from the AI or a person. */
class AiReviewResource
{
    public static function make(ApplicationAiReview $review): array
    {
        return [
            'id' => $review->id, 'status' => $review->status, 'mode' => $review->mode, 'origin' => 'ai',
            'provider' => $review->provider, 'model' => $review->model, 'prompt_version' => $review->prompt_version,
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
