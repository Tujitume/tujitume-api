<?php

namespace App\Console\Commands;

use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\ProgramApplication;
use App\Service\AiReview\AiReviewRunner;
use App\Service\AiReview\ReviewInputBuilder;
use Illuminate\Console\Command;

class AiReviewSmoke extends Command
{
    protected $signature = 'ai-review:smoke {application : Application id}';
    protected $description = 'Run the whole AI review pipeline once on an application with the active provider and print the result.';

    public function handle(ReviewInputBuilder $inputs, AiReviewRunner $runner): int
    {
        $application = ProgramApplication::with('currentRound')->find($this->argument('application'));
        $round = $application?->currentRound;
        if (! $round || ! $round->usesAi()) { $this->error('The application is not in a round that uses AI review.'); return self::FAILURE; }
        if (! config('ai_review.enabled')) { $this->error('AI_REVIEW_ENABLED is false.'); return self::FAILURE; }

        $review = ApplicationAiReview::updateOrCreate(
            ['application_id' => $application->id, 'round_id' => $round->id, 'input_hash' => $inputs->quickHash($application, $round)],
            ['program_id' => $round->program_id, 'mode' => $round->review_method, 'status' => 'queued', 'attempts' => 0, 'error' => null]
        );
        $result = $runner->run($review);
        $review->refresh();

        $this->table(['Field', 'Value'], [
            ['status', $review->status], ['provider / model', "{$review->provider} / {$review->model}"],
            ['schema valid', in_array($review->status, ['ready'], true) ? 'yes' : 'no'],
            ['verified_quote_ratio', $review->quality['verified_quote_ratio'] ?? '-'],
            ['findings', $review->findings()->count()],
            ['usage', json_encode($review->usage)], ['error', $review->error ?? '-'],
            ['wait before retry', $result['release'] === null ? '-' : $result['release'].' s'],
        ]);

        return $review->status === 'ready' ? self::SUCCESS : self::FAILURE;
    }
}
