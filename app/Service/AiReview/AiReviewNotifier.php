<?php

namespace App\Service\AiReview;

use App\Models\Programs\Rounds\ProgramRound;
use App\Service\Notification\ProgramNotificationService;
use Illuminate\Support\Facades\Cache;

/** The three AI-review events. Never reaches applicants or the system AI user. */
class AiReviewNotifier
{
    private function data(ProgramRound $round, array $extra = []): array
    {
        $round->loadMissing('program');

        return $extra + [
            'program_id' => $round->program_id, 'round_id' => $round->id,
            'program_title' => $round->program?->program_title, 'round_name' => $round->round_name,
        ];
    }

    /** At most one "N reports ready" per reviewer per round per 15 minutes; the rest waits for the hourly sweep. */
    public function reportReady(ProgramRound $round, int $reviewerId): void
    {
        $pending = "ai:ready:pending:{$round->id}:{$reviewerId}";
        Cache::put($pending, (int) Cache::get($pending, 0) + 1, now()->addDay());
        $this->flushReviewer($round, $reviewerId);
    }

    public function flushReviewer(ProgramRound $round, int $reviewerId): bool
    {
        $pending = "ai:ready:pending:{$round->id}:{$reviewerId}";
        $count = (int) Cache::get($pending, 0);
        if ($count < 1) return false;
        if (! Cache::add("ai:ready:sent:{$round->id}:{$reviewerId}", 1, now()->addMinutes((int) config('ai_review.notify_batch_minutes')))) return false;

        $user = \App\Models\Auth\User::find($reviewerId);
        if (! $user || AiSystemUser::is($user)) return false;

        Cache::forget($pending);
        (new ProgramNotificationService())->send('ai_review.reports_ready', [$user], $this->data($round, ['count' => $count]));

        return true;
    }

    public function owner(ProgramRound $round): ?\App\Models\Auth\User
    {
        $round->loadMissing('program.owner');

        return $round->program?->owner;
    }

    public function roundScored(ProgramRound $round): void
    {
        if ($owner = $this->owner($round)) {
            (new ProgramNotificationService())->send('ai_review.round_scored', [$owner], $this->data($round));
        }
    }

    public function attention(ProgramRound $round, string $issue): void
    {
        if ($owner = $this->owner($round)) {
            (new ProgramNotificationService())->send('ai_review.round_attention', [$owner], $this->data($round, ['issue' => $issue]));
        }
    }

    /** One auth-error notice per provider per day. */
    public function authError(ProgramRound $round, string $provider): void
    {
        if (Cache::add("ai:auth-notified:{$provider}:".now()->format('Ymd'), 1, now()->addDay())) {
            $this->attention($round, "The AI provider '{$provider}' rejected its API key. Ask your administrator to update it. Applications can still be scored manually.");
        }
    }
}
