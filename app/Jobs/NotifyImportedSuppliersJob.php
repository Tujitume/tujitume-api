<?php

namespace App\Jobs;

use App\Models\Auth\User;
use App\Models\Programs\MilestoneSupplier;
use App\Models\Programs\ProgramImport;
use App\Service\Notification\EmailBrand;
use App\Service\Notification\EmailService;
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
 * "Tell suppliers" after a Start at disbursement import, sent only when the person chooses to: the same emails the
 * app sends when a supplier is added by hand (SupplierDirectoryController). A supplier new to the organization's list
 * gets the welcome ("<organization> has added you as a supplier"); every supplier is told about each milestone they
 * were added to (as a notification when they have a Tujitume account, else by email). Who was told is saved after
 * each supplier, so nobody is told twice; a long list is handed to a fresh run, as the import job does.
 */
class NotifyImportedSuppliersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, RecordsImportSkips;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $importId)
    {
    }

    public function handle(EmailService $email, ProgramNotificationService $notifications): void
    {
        @set_time_limit($this->timeout);
        $lock = Cache::lock("program-import-suppliers:{$this->importId}", $this->timeout + 30);
        if (! $lock->get()) return;
        try {
            $more = $this->work($email, $notifications);
        } finally {
            $lock->release();
        }
        if ($more && config('queue.default') !== 'sync') self::dispatch($this->importId);
    }

    /** @return bool whether suppliers are left for a next run */
    private function work(EmailService $email, ProgramNotificationService $notifications): bool
    {
        $import = ProgramImport::find($this->importId);
        if (! $import || ($import->progress['suppliers']['status'] ?? null) !== 'sending') return false;

        $started = microtime(true);
        $budget = RunProgramImportJob::budget();
        $welcome = $import->newSupplierIds();
        $links = MilestoneSupplier::whereIn('id', $import->supplierLinksToTell())
            ->with(['supplierDirectory', 'milestone.application.program.owner'])->orderBy('id')->get()->groupBy('supplier_id');

        foreach ($links as $supplierId => $mine) {
            if (microtime(true) - $started > $budget) return true;
            // Only a supplier every email reached counts as told; the others stay to tell, so a retry reaches them
            $told = false;
            // An address that cannot receive mail is not sent to (it would bounce): recorded with the reason
            $address = mb_strtolower((string) $mine->first()->supplierDirectory?->email);
            if ($why = ImportMail::problem($address)) { $this->skip($import, 'suppliers', $mine->pluck('id')->all(), $address, $why); continue; }
            try {
                $told = $this->tell($email, $notifications, $mine, in_array((int) $supplierId, $welcome, true));
                ImportMail::pace();
            } catch (Throwable $e) {
                Log::error('Program import: a supplier could not be told', ['import' => $import->id, 'supplier' => $supplierId, 'error' => $e->getMessage()]);
            }
            if ($told) $this->record($import, $mine->pluck('id')->all());
        }

        $import->refresh();
        $progress = $import->progress;
        $progress['suppliers']['status'] = 'sent';
        $progress['suppliers']['sent_at'] = now()->toIso8601String();
        $import->forceFill(['progress' => $progress])->save();

        return false;
    }

    /** @return bool whether every email went out */
    private function tell(EmailService $email, ProgramNotificationService $notifications, $links, bool $isNew): bool
    {
        $supplier = $links->first()->supplierDirectory;
        if (! $supplier || ! $supplier->email) return true;
        $ok = true;
        $program = $links->first()->milestone?->application?->program;
        $owner = $program?->owner;
        $organization = $owner?->display_name ?: trim(($owner?->first_name ?? '').' '.($owner?->last_name ?? '')) ?: 'The program team';
        $brand = $program ? EmailBrand::forProgram($program) : EmailBrand::defaults();
        $account = User::whereRaw('LOWER(email) = ?', [mb_strtolower($supplier->email)])->first();

        if ($isNew) {
            $ok = $email->send("{$organization} has added you as a supplier", 'programs.supplier_welcome', [
                'brand' => $brand,
                'recipientName' => $supplier->contact_person ?: $supplier->legal_name,
                'added_by' => trim(($owner?->first_name ?? '').' '.($owner?->last_name ?? '')),
                'org_name' => $organization,
                'supplier_name' => $supplier->legal_name,
                'supplier_type' => $supplier->supplier_type,
                'onboarded' => (bool) $account,
            ], $supplier->email);
        }

        foreach ($links as $link) {
            $milestone = $link->milestone;
            $data = [
                'program_id' => $program?->id,
                'application_id' => $milestone?->app_id,
                'program_title' => $program?->program_title ?? 'the program',
                'milestone_id' => $milestone?->id,
                'milestone_number' => $milestone?->sequence_order,
                'supplier_name' => $supplier->legal_name,
                'recipientName' => $supplier->contact_person ?: $supplier->legal_name,
                'org_name' => $organization,
                'quoted_amount' => $link->quoted_amount,
            ];
            if ($account) {
                $notifications->send('supplier.added', [$account], $data);
            } else {
                $ok = $email->send('You Have Been Added to a Milestone', 'programs.supplier_added', $data + ['brand' => EmailBrand::forData($data)], $supplier->email) && $ok;
            }
        }

        return $ok;
    }

    /** Saves who was told, one supplier at a time, so a stop half way never tells anyone twice. */
    private function record(ProgramImport $import, array $linkIds): void
    {
        $import->refresh();
        $progress = $import->progress;
        $progress['suppliers']['sent_ids'] = array_values(array_unique(array_merge($progress['suppliers']['sent_ids'] ?? [], $linkIds)));
        $progress['suppliers']['sent'] = ($progress['suppliers']['sent'] ?? 0) + 1;
        $import->forceFill(['progress' => $progress])->save();
    }
}
