<?php

namespace App\Jobs;

use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramImport;
use App\Service\Notification\EmailBrand;
use App\Service\Notification\EmailService;
use App\Service\Notification\RecipientUrl;
use App\Service\Program\ApplicationTrackingLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Jobs\Concerns\RecordsImportSkips;
use App\Service\Program\ImportMail;
use Throwable;

/**
 * "Let applicants know" after an import: one email per person (however many programs or rounds they are in),
 * from the organization, so they can sign in and follow their application on their dashboard.
 *  - A quiet account made by the import (no password yet) gets a password to sign in with; they can change it.
 *  - An account that already has a password is only told to sign in. Its password is never touched.
 *  - An application with no account (the email belongs to a team member or coach) gets its tracking link.
 * Who was told is saved after each email, so nobody is told twice; after TIME_BUDGET seconds the rest is handed
 * to a fresh run, as the import job does.
 */
class NotifyImportedApplicantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, RecordsImportSkips;

    private const TIME_BUDGET = 45;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $importId)
    {
    }

    public function handle(EmailService $email): void
    {
        $lock = Cache::lock("program-import-notify:{$this->importId}", $this->timeout + 30);
        if (! $lock->get()) return;
        try {
            $more = $this->work($email);
        } finally {
            $lock->release();
        }
        if ($more) self::dispatch($this->importId);
    }

    /** @return bool whether people are left for a next run */
    private function work(EmailService $email): bool
    {
        $import = ProgramImport::find($this->importId);
        if (! $import || ($import->progress['notify']['status'] ?? null) !== 'sending') return false;

        $started = microtime(true);
        $people = ProgramApplication::whereIn('id', $import->applicationsToNotify())
            ->with(['user', 'program.owner'])->orderBy('id')->get()
            ->groupBy(fn ($a) => mb_strtolower((string) ($a->user?->email ?: $a->email)))
            ->filter(fn ($apps, $address) => $address !== '');

        foreach ($people as $address => $applications) {
            if (microtime(true) - $started > self::TIME_BUDGET) return true;
            // Only someone the email reached counts as told; the others stay to tell, so a retry reaches them
            $told = false;
            // An address that cannot receive mail is not sent to (it would bounce): recorded with the reason
            if ($why = ImportMail::problem($address)) { $this->skip($import, 'notify', $applications->pluck('id')->all(), $address, $why); continue; }
            try {
                $told = $this->tell($email, $address, $applications);
                ImportMail::pace();
            } catch (Throwable $e) {
                Log::error('Program import: an applicant could not be told', ['import' => $import->id, 'error' => $e->getMessage()]);
            }
            if ($told) $this->record($import, $applications->pluck('id')->all());
        }

        $progress = $import->progress;
        $progress['notify']['status'] = 'sent';
        $progress['notify']['sent_at'] = now()->toIso8601String();
        $import->forceFill(['progress' => $progress])->save();

        return false;
    }

    /** @return bool whether the email went out (a new password is only kept when it did) */
    private function tell(EmailService $email, string $address, $applications): bool
    {
        $first = $applications->first();
        $user = $first->user;
        $password = null;
        if ($user && empty($user->getAttributes()['password'] ?? null)) {
            // The quiet account made by the import: it gets its first password now (the hashed cast stores it hashed)
            $password = Str::password(12, true, true, false);
            $user->forceFill(['password' => $password])->save();
        }

        $programs = $applications->unique('program_id')->map(fn ($a) => [
            'title' => $a->program?->program_title,
            'tracking_url' => $user ? null : ApplicationTrackingLink::url($a),
        ])->values()->all();
        $organization = $first->program?->owner?->display_name ?: trim(($first->program?->owner?->first_name ?? '').' '.($first->program?->owner?->last_name ?? ''));

        $sent = $email->send(
            count($programs) > 1 ? 'Your applications are on Tujitume' : 'Your application to '.($programs[0]['title'] ?? 'a program').' is on Tujitume',
            'programs.imported_application_account',
            [
                'name' => $user?->first_name ?: $first->startup_name,
                'organization' => $organization ?: 'The program team',
                'programs' => $programs,
                'email' => $address,
                'password' => $password,
                'has_account' => (bool) $user,
                'login_url' => RecipientUrl::url('login', $address),
                'brand' => $first->program ? EmailBrand::forProgram($first->program) : EmailBrand::defaults(),
            ],
            $address,
        );
        // The email did not go out: the quiet account keeps no password nobody was told about
        if (! $sent && $password !== null) $user->forceFill(['password' => null])->save();

        return $sent;
    }

    /** Saves who was told, one person at a time, so a stop half way never tells anyone twice. */
    private function record(ProgramImport $import, array $applicationIds): void
    {
        $import->refresh();
        $progress = $import->progress;
        $progress['notify']['sent_ids'] = array_values(array_unique(array_merge($progress['notify']['sent_ids'] ?? [], $applicationIds)));
        $progress['notify']['sent'] = ($progress['notify']['sent'] ?? 0) + 1;
        $import->forceFill(['progress' => $progress])->save();
    }

    public function failed(Throwable $e): void
    {
        $import = ProgramImport::find($this->importId);
        if ($import && ($import->progress['notify']['status'] ?? null) === 'sending') {
            $progress = $import->progress;
            $progress['notify']['status'] = 'stopped';
            $import->forceFill(['progress' => $progress])->save();
        }
        Log::error('Program import notify job stopped', ['import' => $this->importId, 'error' => $e->getMessage()]);
    }
}
