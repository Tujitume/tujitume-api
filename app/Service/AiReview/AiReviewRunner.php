<?php

namespace App\Service\AiReview;

use App\Models\Programs\Ai\ApplicationAiFinding;
use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Service\Program\RubricCalculator;

/** The pipeline for one review: extract -> deterministic checks -> AI analysis -> findings -> (score | notify). */
class AiReviewRunner
{
    public function __construct(
        private ReviewInputBuilder $inputs,
        private DeterministicChecker $checker,
        private AiReviewAnalyzer $analyzer,
        private AiScoringService $scoring,
        private AiReviewNotifier $notifier,
        private RubricCalculator $rubric,
        private CriterionAnswerScorer $criterionAnswerScorer,
    ) {
    }

    /** @return array{release:?int} seconds to wait before running again, or null when finished */
    public function run(ApplicationAiReview $review): array
    {
        $review->loadMissing(['application', 'round.program.owner']);
        $application = $review->application;
        $round = $review->round;
        $program = $round?->program;

        // Nothing to do when the review no longer applies
        if (! $application || ! $round || ! $program || ! $round->usesAi() || $review->status === ApplicationAiReview::STATUS_STALE
            || $round->status === 'finalized' || (int) $application->current_round_id !== (int) $round->id) {
            return ['release' => null];
        }
        if ($review->input_hash !== $this->inputs->quickHash($application, $round)) {
            $review->update(['status' => ApplicationAiReview::STATUS_STALE]);

            return ['release' => null];
        }

        $review->update(['status' => ApplicationAiReview::STATUS_EXTRACTING, 'started_at' => $review->started_at ?? now(), 'attempts' => $review->attempts + 1]);

        // 1. Extract documents locally, 2. deterministic checks (always saved, even if AI fails)
        try {
            $input = $this->inputs->build($application, $round);
        } catch (\Throwable $e) {
            return $this->fail($review, 'Application content could not be prepared.');
        }
        $deterministic = $this->checker->check($round, $input);
        $notes = ['notes' => $input['notes'], 'documents' => array_map(fn ($d) => ['label' => $d['label'], 'status' => $d['status'], 'method' => $d['method'], 'pages' => $d['page_count']], $input['documents']), 'issues' => $input['extraction_issues']];
        $review->update([
            'status' => ApplicationAiReview::STATUS_ANALYZING, 'deterministic_checks' => $deterministic['checks'],
            'missing_items' => $deterministic['missing_items'], 'extraction_notes' => $notes, 'prompt_version' => config('ai_review.prompt_version'),
        ]);

        // 3. AI analysis
        $withScores = true;
        try {
            $out = $this->analyzer->analyze($review, $round, $program, $input, $withScores);
        } catch (AiUnavailableException $e) {
            foreach ($this->analyzer->authFailures as $provider) $this->notifier->authError($round, $provider);
            if ($e->wait !== null && ! $e->authError) {
                // Quota or rate limit: wait, never fail the application
                $review->update(['status' => ApplicationAiReview::STATUS_QUEUED, 'attempts' => max(0, $review->attempts - 1)]);

                return ['release' => max(30, $e->wait + random_int(0, 20))];
            }
            // No provider will become usable by itself: keep the deterministic report and flag it
            $review->update([
                'status' => ApplicationAiReview::STATUS_NEEDS_ATTENTION,
                'error' => $e->authError ? 'The AI provider rejected its credentials.' : 'No AI provider is available ('.$e->reason.'). Only the automatic checks ran.',
                'completed_at' => now(),
            ]);

            return ['release' => null];
        } catch (\UnexpectedValueException $e) {
            return $this->fail($review, 'The AI answer could not be used. '.$e->getMessage());
        } catch (\Throwable $e) {
            \Log::warning('AI review failed', ['review_id' => $review->id, 'class' => get_class($e)]);

            return $this->fail($review, 'The AI review hit an unexpected error.');
        }

        $out['data']['scores'] = $this->criterionAnswerScorer->apply(
            $out['data']['scores'],
            $round->scoring_criteria ?? [],
            $input,
        );
        $this->store($review, $out, $input);

        // 4. After ready
        if ($round->isAiOnly()) {
            $result = $this->scoring->scoreApplication($review->fresh(), $application->fresh(), $round);
            if (! $result['scored']) {
                $review->update(['status' => ApplicationAiReview::STATUS_NEEDS_ATTENTION, 'error' => $result['reason']]);
            }
        } else {
            $reviewerId = ReviewerApplicationAssignment::where(['round_id' => $round->id, 'application_id' => $application->id])->value('reviewer_id');
            if ($reviewerId) $this->notifier->reportReady($round, (int) $reviewerId);
        }

        return ['release' => null];
    }

    private function store(ApplicationAiReview $review, array $out, array $input): void
    {
        $review->findings()->where('author_type', 'ai')->where('status', 'open')->delete();
        foreach ($out['findings'] as $f) {
            ApplicationAiFinding::create([
                'review_id' => $review->id, 'author_type' => 'ai', 'dimension' => $f['dimension'], 'polarity' => $f['polarity'],
                'title' => $f['title'], 'body' => $f['body'], 'confidence' => $f['confidence'], 'status' => 'open',
                'source_type' => $f['source_type'], 'source_ref' => $f['source_ref'], 'page' => $f['page'], 'quote' => $f['quote'],
                'quote_verified' => $f['quote_verified'],
            ]);
        }

        $mapped = $this->scoring->map($review->round, $out['data']['scores'], $review->round->isAiOnly());
        $missing = array_values(array_unique(array_merge($review->missing_items ?? [], $out['data']['missing_items'])));

        $review->update([
            'status' => ApplicationAiReview::STATUS_READY, 'provider' => $out['provider'], 'model' => $out['model'],
            'summary' => $out['data']['summary'], 'suggested_scores' => $out['data']['scores'],
            'suggested_total' => $mapped['ok'] ? $mapped['total'] : null, 'missing_items' => $missing,
            'quality' => $out['quality'], 'usage' => $out['usage'], 'error' => null, 'completed_at' => now(),
            'extraction_notes' => array_merge($review->extraction_notes ?? [], ['notes' => array_values(array_unique(array_merge($input['notes'], $out['notes'])))]),
        ]);
    }

    private function fail(ApplicationAiReview $review, string $message): array
    {
        $max = (int) config('ai_review.retries');
        if ($review->attempts < $max) {
            $review->update(['status' => ApplicationAiReview::STATUS_QUEUED, 'error' => $message]);
            $backoff = config('ai_review.backoff_seconds');

            return ['release' => ($backoff[min($review->attempts - 1, count($backoff) - 1)] ?? 600) + random_int(0, 15)];
        }
        $review->update(['status' => ApplicationAiReview::STATUS_FAILED, 'error' => $message, 'completed_at' => now()]);

        return ['release' => null];
    }
}
