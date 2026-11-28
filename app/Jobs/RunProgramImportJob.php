<?php

namespace App\Jobs;

use App\Models\Programs\ProgramImport;
use App\Service\Program\ProgramImportRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Step 5 of an import: creates its programs one at a time, each in its own transaction (see ProgramImportRunner).
 * Progress is saved after every program, so the page can show it and nothing is ever created twice: a program
 * already created is skipped when the job runs again. A program that fails is recorded with its reason and the
 * others go on. After TIME_BUDGET seconds the job hands the rest to a fresh run of itself, so a big import never
 * hits a worker or request time limit and does not hold a worker for long.
 */
class RunProgramImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const TIME_BUDGET = 45;

    public int $tries = 1;
    public int $timeout = 120;

    /**
     * Seconds one run may take before it hands the rest to a fresh run: TIME_BUDGET, or less when PHP's time limit is
     * shorter (e.g. 30 s), so a run always pauses itself before PHP would stop it.
     */
    public static function budget(): int
    {
        $limit = (int) ini_get('max_execution_time');

        // Without a worker the run holds a web request, so it is kept short
        $budget = config('queue.default') === 'sync' ? min(self::TIME_BUDGET, 15) : self::TIME_BUDGET;

        return $limit > 0 ? max(5, min($budget, $limit - 10)) : $budget;
    }

        public function __construct(public int $importId)
    {
    }

    public function handle(ProgramImportRunner $runner): void
    {
        // Run after the response (sync queue) or by a worker: ask PHP for enough time for one run, so the request's
        // own limit (often 30 s) does not stop it half way; the budget below stays inside whatever limit applies
        @set_time_limit($this->timeout);
        // Only one run per import at a time; the lock is let go before the next run is started
        $lock = Cache::lock("program-import:{$this->importId}", $this->timeout + 30);
        if (! $lock->get()) return;
        try {
            $more = $this->work($runner);
        } finally {
            $lock->release();
        }
        // The rest, in a fresh run. Without a queue worker (sync), the page's next progress check runs it
        // (ProgramImportController::resumeIfStalled), so no single request runs long enough to be stopped
        if ($more && config('queue.default') !== 'sync') self::dispatch($this->importId);
    }

    /** @return bool whether programs are left for a next run */
    private function work(ProgramImportRunner $runner): bool
    {
        $import = ProgramImport::find($this->importId);
        if (! $import || $import->status !== 'importing') return false;

        $started = microtime(true);
        $plan = $import->plan ?? [];
        $progress = $import->progress ?? [];
        $results = $progress['results'] ?? [];

        foreach ($plan as $index => $item) {
            $key = (string) ($item['code'] ?? '') !== '' ? (string) $item['code'] : "#{$index}";
            if (($results[$key]['status'] ?? null) === 'created') continue;

            if (microtime(true) - $started > self::budget()) {
                $this->save($import, $plan, $results);

                return true;
            }

            // A program whose run was stopped part way (too big for the time a run has) is tried a few times, then
            // marked failed, so the import never stays on "importing"
            $attempts = (int) ($results[$key]['attempts'] ?? 0) + 1;
            if ($attempts > 3) {
                $results[$key] = ['status' => 'failed', 'message' => 'This program could not be created in the time an import run has. Split its applicants over several files and try again.', 'title' => $item['program']['program_title'] ?? null];
                $this->save($import, $plan, $results);
                continue;
            }
            $results[$key] = ['status' => 'running', 'attempts' => $attempts, 'title' => $item['program']['program_title'] ?? null];
            $this->save($import, $plan, $results);

            try {
                $programId = $runner->run($import, $item);
                $results[$key] = ['status' => 'created', 'program_id' => $programId, 'title' => $item['program']['program_title'] ?? null, 'applicants' => $this->applicantCount($item), 'application_ids' => $runner->applicationIds, 'reviewer_ids' => $runner->reviewerIds, 'supplier_link_ids' => $runner->supplierLinkIds, 'new_supplier_ids' => $runner->newSupplierIds, 'site_visit_ids' => $runner->siteVisitIds];
            } catch (RuntimeException $e) {
                $results[$key] = ['status' => 'failed', 'message' => $e->getMessage(), 'title' => $item['program']['program_title'] ?? null];
            } catch (Throwable $e) {
                Log::error('Program import: a program could not be created', ['import' => $import->id, 'program' => $key, 'error' => $e->getMessage()]);
                $results[$key] = ['status' => 'failed', 'message' => 'Something went wrong creating this program. Try again; if it keeps failing, check its details.', 'title' => $item['program']['program_title'] ?? null];
            }
            $this->save($import, $plan, $results);
        }

        $failed = collect($results)->where('status', 'failed')->count();
        $import->forceFill(['status' => $failed ? 'partial' : 'completed', 'finished_at' => now()])->save();
        $this->announce($import->fresh(), $failed);

        return false;
    }

    /**
     * Tells the person who started the import that it is done (a dashboard notification and an email), with how
     * many programs were created and how long it took. Once per import: a later retry of the failed ones is not
     * announced again. Never stops the import: a notification that fails is logged.
     */
    private function announce(ProgramImport $import, int $failed): void
    {
        if (! empty($import->progress['finished_notified'])) return;
        $progress = $import->progress;
        $progress['finished_notified'] = now()->toIso8601String();
        $import->forceFill(['progress' => $progress])->save();

        try {
            $owner = $import->user;
            if (! $owner || \App\Service\Program\ImportMail::problem($owner->email)) return;
            $seconds = $import->started_at ? max(1, (int) $import->started_at->diffInSeconds($import->finished_at ?? now())) : null;
            (new \App\Service\Notification\ProgramNotificationService())->send('import.finished', [$owner], [
                'import_id' => $import->id,
                'file_name' => $import->file_name,
                'created' => (int) ($import->progress['created'] ?? 0),
                'failed' => $failed,
                'total' => (int) ($import->progress['total'] ?? 0),
                'duration' => $seconds ? \Carbon\CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => false, 'parts' => 2]) : 'a moment',
                'actor_id' => $owner->id,
            ]);
        } catch (Throwable $e) {
            Log::error('Program import: the person could not be told it finished', ['import' => $import->id, 'error' => $e->getMessage()]);
        }
    }

    public function failed(Throwable $e): void
    {
        // The job itself stopped (a time limit, the server going down): keep what was done and let it be tried again
        ProgramImport::whereKey($this->importId)->where('status', 'importing')->update(['status' => 'partial', 'finished_at' => now()]);
        Log::error('Program import job stopped', ['import' => $this->importId, 'error' => $e->getMessage()]);
    }

    private function save(ProgramImport $import, array $plan, array $results): void
    {
        $list = collect($results);
        // Other parts of the progress (the emails sent after the import) are kept as they are now, not as they were
        // when this run started
        $import->refresh();
        $import->forceFill(['progress' => array_merge($import->progress ?? [], [
            'total' => count($plan),
            'done' => $list->whereIn('status', ['created', 'failed'])->count(),
            'created' => $list->where('status', 'created')->count(),
            'failed' => $list->where('status', 'failed')->count(),
            'results' => $results,
        ])])->save();
    }

    /** People applying to this program in the import (one each, however many rounds they are in). */
    private function applicantCount(array $item): int
    {
        return collect($item['rounds'] ?? [])->flatMap(fn ($r) => $r['applications'] ?? [])
            ->map(fn ($a) => mb_strtolower(trim((string) ($a['email'] ?? ''))))->filter()->unique()->count();
    }
}
