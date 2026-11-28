<?php

namespace App\Jobs;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\Programs\ProgramImport;
use App\Models\ReviewerOrder;
use App\Service\Notification\ProgramNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Jobs\Concerns\RecordsImportSkips;
use App\Service\Program\ImportMail;
use Throwable;

/**
 * "Tell site visit officers" after a Start at monitoring & evaluation import, sent only when the person chooses to.
 * Someone the import made new to Tujitume first gets their invitation (the same as for Business Coaches); everyone
 * else is told about each scheduled visit, as assigning a visit in the M&E tab tells them (reviewer.site_visit_assigned).
 * Who was told is saved after each person, so nobody is told twice; a long list is handed to a fresh run.
 */
class NotifyImportedInspectorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, RecordsImportSkips;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $importId)
    {
    }

    public function handle(ProgramNotificationService $notifications): void
    {
        @set_time_limit($this->timeout);
        $lock = Cache::lock("program-import-inspectors:{$this->importId}", $this->timeout + 30);
        if (! $lock->get()) return;
        try {
            $more = $this->work($notifications);
        } finally {
            $lock->release();
        }
        if ($more && config('queue.default') !== 'sync') self::dispatch($this->importId);
    }

    /** @return bool whether officers are left for a next run */
    private function work(ProgramNotificationService $notifications): bool
    {
        $import = ProgramImport::find($this->importId);
        if (! $import || ($import->progress['inspectors']['status'] ?? null) !== 'sending') return false;

        $started = microtime(true);
        $budget = RunProgramImportJob::budget();
        $organization = Organization::find($import->organization_id);
        $inviter = User::find($import->progress['inspectors']['by'] ?? $import->user_id);
        $people = MESiteVisit::whereIn('id', $import->siteVisitsToTell())->with('checkpoint')->orderBy('id')->get()->groupBy('reviewer_id');

        foreach ($people as $userId => $visits) {
            if (microtime(true) - $started > $budget) return true;
            try {
                $user = User::find($userId);
                // An address that cannot receive mail is not sent to (it would bounce): recorded with the reason
                if ($user && ($why = ImportMail::problem($user->email))) { $this->skip($import, 'inspectors', $visits->pluck('id')->all(), mb_strtolower($user->email), $why); continue; }
                if ($user && ! InviteImportedCoachesJob::sendInvitation($user, $organization, $inviter)) {
                    foreach ($visits as $visit) $this->tell($notifications, $user, $visit);
                }
                $this->record($import, $visits->pluck('id')->all());
                ImportMail::pace();
            } catch (Throwable $e) {
                // Not counted as told: a retry reaches them
                Log::error('Program import: a site visit officer could not be told', ['import' => $import->id, 'user' => $userId, 'error' => $e->getMessage()]);
            }
        }

        $import->refresh();
        $progress = $import->progress;
        $progress['inspectors']['status'] = 'sent';
        $progress['inspectors']['sent_at'] = now()->toIso8601String();
        $import->forceFill(['progress' => $progress])->save();

        return false;
    }

    private function tell(ProgramNotificationService $notifications, User $user, MESiteVisit $visit): void
    {
        $order = ReviewerOrder::where('site_visit_id', $visit->id)->with('program')->first();
        if (! $order || $order->acceptance_status === 'accepted') return;
        $notifications->send('reviewer.site_visit_assigned', [$user], [
            'program_id' => $order->program_id,
            'program_title' => $order->program?->program_title,
            'order_id' => $order->id,
            'site_visit_id' => $visit->id,
            'checkpoint_name' => $visit->checkpoint?->checkpoint_name ?? ('Checkpoint '.$visit->checkpoint_id),
            'fee' => $order->fee_amount,
            'currency' => $order->currency,
        ]);
    }

    /** Saves who was told, one person at a time, so a stop half way never tells anyone twice. */
    private function record(ProgramImport $import, array $visitIds): void
    {
        $import->refresh();
        $progress = $import->progress;
        $progress['inspectors']['sent_ids'] = array_values(array_unique(array_merge($progress['inspectors']['sent_ids'] ?? [], $visitIds)));
        $progress['inspectors']['sent'] = ($progress['inspectors']['sent'] ?? 0) + 1;
        $import->forceFill(['progress' => $progress])->save();
    }
}
