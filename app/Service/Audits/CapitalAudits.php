<?php

namespace App\Service\Audits;

use App\Models\Auth\User;
use App\Models\Capital\CapitalMilestone;
use App\Models\Capital\StartupPitches;
use App\Service\Capital\CapitalAccess;
use App\Service\Capital\CapitalFiles;
use App\Service\Capital\CapitalNotifier;
use App\Service\Capital\CapitalRelease;
use App\Service\Organization\OrganizationPermission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DECISION-9 for capital: an optional, paid project manager audit of a capital milestone BEFORE it is released. The
 * "capital" source of Project Manager Audits (ProjectManagerAudits::list, final-approval stage).
 *
 *  1. The capital provider (owner or capital super admin) asks a project manager (a service provider, type 3) to check
 *     an unreleased milestone (pending). The business owner is told the milestone is being audited.
 *  2. The PM accepts, quoting a fee (quoted; free = accepted at once), or declines (declined).
 *  3. The provider who asked pays the fee into escrow (AuditFee::holdForCapital): the audit is accepted (work under way).
 *  4. The PM reports: recommends release (approve) or changes (reject), notes, optional evidence files. The fee is
 *     released to the PM (AuditFee::releaseCapital).
 *  5. The provider decides: the audit never releases or blocks money by itself once it is closed.
 *
 * While an audit is open (OPEN) the milestone cannot be released (CapitalRelease::refusal, 409). The provider may cancel
 * an open audit at any time (a held fee is refunded) and decide themselves.
 */
class CapitalAudits
{
    /** Statuses in which the audit is still running: release waits for it */
    public const OPEN = ['pending', 'quoted', 'paying', 'accepted'];

    public const CANCELLABLE = ['pending', 'quoted', 'accepted'];

    public const BLOCKED_MESSAGE = 'A project manager is auditing this milestone. Wait for their report, or cancel the audit, then release the funds.';

    public static function isOpen(?CapitalMilestone $m): bool
    {
        return $m !== null && in_array($m->audit_status, self::OPEN, true);
    }

    // ── the capital provider ───────────────────────────────────────────────────

    /** Why this person may not ask for an audit of this milestone now, or null */
    public static function requestRefusal(?User $user, CapitalMilestone $m): ?array
    {
        $pitch = self::pitchOf($m);
        if ($refusal = self::providerRefusal($user, $pitch)) return $refusal;
        if ((int) $pitch->status !== StartupPitches::STATUS_ACCEPTED) return ['Only a milestone of an accepted application can be audited.', 422];
        if ($m->fund_released || $m->release_status === CapitalRelease::RELEASED || (int) $m->status === 1) return ['This milestone has already been released.', 409];
        if ($m->release_status === CapitalRelease::PROCESSING) return ['A release for this milestone is already under way.', 409];
        if (self::isOpen($m)) return ['An audit of this milestone is already open.', 409];
        if ($m->audit_status === 'reported') return ['The project manager\'s report is in. Decide the release yourself.', 409];

        return null;
    }

    public function request(CapitalMilestone $m, User $by, int $auditorId, ?string $notes): void
    {
        if ($refusal = self::requestRefusal($by, $m)) ProjectManagerAudits::refuse(...$refusal);
        $pitch = self::pitchOf($m);
        $pm = User::where('user_type_id', 3)->find($auditorId);
        if (! $pm) ProjectManagerAudits::refuse('Pick a project manager.');
        if (in_array((int) $pm->id, [(int) $pitch->user_id, (int) $pitch->capital_owner_id], true)) ProjectManagerAudits::refuse('Pick a project manager who is not part of this deal.');

        $claimed = CapitalMilestone::whereKey($m->id)
            ->where(fn ($q) => $q->whereNull('audit_status')->orWhereNotIn('audit_status', [...self::OPEN, 'reported']))
            ->update([
                'auditor_id' => $pm->id, 'audit_status' => 'pending', 'audit_request_notes' => $notes, 'audit_requested_by' => $by->id,
                'audit_requested_at' => now(), 'audit_recommendation' => null, 'audit_notes' => null, 'audit_evidence' => null,
                'audit_completed_at' => null, 'audit_fee' => null, 'audit_fee_status' => null, 'audit_fee_reference' => null,
                'audit_fee_paid_by' => null, 'audit_cancel_reason' => null,
            ]);
        if (! $claimed) ProjectManagerAudits::refuse('An audit of this milestone is already open.', 409);
        $m->refresh();

        $label = self::label($m, $pitch);
        $this->tellPm($pm->id, (int) $by->id, "{$this->orgName($pitch)} asked you to audit {$label} before releasing its capital. Open Project Manager Audits to accept (with your fee) or decline.");
        $this->tellApplicant($pitch, "A project manager will check {$m->title} before the capital provider releases its funds. You will see the outcome here.");
        $this->tellProvider($pitch, (int) $pm->id, "{$by->first_name} asked {$pm->first_name} {$pm->last_name} to audit {$label}. Release waits for the report, or until the audit is cancelled.", $by->id);
    }

    /** The provider who pays (owner or super admin) pays the quoted fee into escrow; the audit then starts */
    public function pay(CapitalMilestone $m, User $by): void
    {
        $pitch = self::pitchOf($m);
        if ($refusal = self::providerRefusal($by, $pitch)) ProjectManagerAudits::refuse(...$refusal);
        if ($m->audit_status !== 'quoted') ProjectManagerAudits::refuse('There is no audit fee to pay for this milestone.', 409);

        // Claimed first (one request only), then the fee is taken; if taking it fails the audit is back to quoted
        if (! CapitalMilestone::whereKey($m->id)->where('audit_status', 'quoted')->update(['audit_status' => 'paying'])) {
            ProjectManagerAudits::refuse('This audit fee was already paid.', 409);
        }
        try {
            AuditFee::holdForCapital($m->refresh(), $by);
        } catch (Throwable $e) {
            $m->update(['audit_status' => 'quoted']);
            throw $e;
        }
        $m->update(['audit_status' => 'accepted']);

        $this->tellPm((int) $m->auditor_id, (int) $by->id, 'The audit fee of '.AuditFee::label((float) $m->audit_fee)." for {$m->title} ({$pitch->startup_name}) is held for you. Start the audit and send your report.");
        $this->tellProvider($pitch, (int) $m->auditor_id, 'The audit fee for '.$m->title.' was paid and is held until the report is in.', $by->id);
    }

    /** Before the report: the audit stops, a held fee is refunded, and the provider decides the release themselves */
    public function cancel(CapitalMilestone $m, User $by, ?string $reason): void
    {
        $pitch = self::pitchOf($m);
        if ($refusal = self::providerRefusal($by, $pitch)) ProjectManagerAudits::refuse(...$refusal);
        if (! in_array($m->audit_status, self::CANCELLABLE, true)) {
            ProjectManagerAudits::refuse($m->audit_status === 'reported' ? 'The report is already in, so the audit cannot be cancelled.' : 'There is no open audit to cancel.', 409);
        }
        if (! CapitalMilestone::whereKey($m->id)->whereIn('audit_status', self::CANCELLABLE)->update(['audit_status' => 'cancelled', 'audit_cancel_reason' => $reason])) {
            ProjectManagerAudits::refuse('There is no open audit to cancel.', 409);
        }
        AuditFee::refundCapital($m->refresh());

        $why = $reason ? " Reason: {$reason}" : '';
        if ($m->auditor_id) $this->tellPm((int) $m->auditor_id, (int) $by->id, "{$this->orgName($pitch)} cancelled the audit of {$m->title} ({$pitch->startup_name}). Any fee held is refunded.{$why}");
        $this->tellApplicant($pitch, "The audit of {$m->title} was cancelled. The capital provider decides the release.");
        $this->tellProvider($pitch, (int) $by->id, "{$by->first_name} cancelled the audit of {$m->title}. You can release it when ready.", $by->id);
    }

    // ── the project manager ────────────────────────────────────────────────────

    public function respond(CapitalMilestone $m, User $pm, string $action, float $fee = 0): void
    {
        $this->assertAuditor($m, $pm);
        if ($m->audit_status !== 'pending') ProjectManagerAudits::refuse('You have already answered this audit.', 409);
        $fee = round(max(0, $fee), 2);
        $accept = $action === 'accept';

        $next = $accept
            ? ['audit_status' => $fee > 0 ? 'quoted' : 'accepted', 'audit_fee' => $fee, 'audit_fee_status' => $fee > 0 ? 'awaiting_payment' : null]
            : ['audit_status' => 'declined'];
        if (! CapitalMilestone::whereKey($m->id)->where('audit_status', 'pending')->update($next)) {
            ProjectManagerAudits::refuse('You have already answered this audit.', 409);
        }
        $m->refresh();

        $pitch = self::pitchOf($m);
        $text = match (true) {
            ! $accept => "{$pm->first_name} declined the audit of {$m->title} ({$pitch->startup_name}). You can release it yourself or ask another project manager.",
            $fee > 0 => "{$pm->first_name} accepted the audit of {$m->title} ({$pitch->startup_name}) for ".AuditFee::label($fee).'. Pay the fee to start the audit.',
            default => "{$pm->first_name} accepted the audit of {$m->title} ({$pitch->startup_name}). You will be told when the report is in.",
        };
        $this->tellProvider($pitch, (int) $pm->id, $text);
        if (! $accept) $this->tellApplicant($pitch, "The project manager will not audit {$m->title}. The capital provider decides the release.");
    }

    /**
     * The report: approve (recommend release) or reject (ask for changes), notes, and up to five evidence files. The
     * fee is paid to the PM; the provider decides the release.
     *
     * @param  UploadedFile[]  $files
     */
    public function report(CapitalMilestone $m, User $pm, string $recommendation, string $notes, array $files = []): void
    {
        $this->assertAuditor($m, $pm);
        if ($m->audit_status !== 'accepted') {
            ProjectManagerAudits::refuse(match ($m->audit_status) {
                'reported' => 'You have already sent your report.',
                'quoted', 'paying' => 'The audit fee has not been paid yet. You can start once the capital provider pays it.',
                default => 'Accept the audit before sending a report.',
            }, 409);
        }
        if (! CapitalMilestone::whereKey($m->id)->where('audit_status', 'accepted')->update(['audit_status' => 'reported'])) {
            ProjectManagerAudits::refuse('You have already sent your report.', 409);
        }

        $evidence = [];
        foreach (array_slice($files, 0, 5) as $file) {
            try {
                $path = CapitalFiles::store($file, 'files/capitalAudits/'.$m->id);
                if ($path) $evidence[] = ['name' => $file->getClientOriginalName(), 'path' => $path];
            } catch (Throwable $e) {
                Log::warning('Capital audit evidence upload failed', ['capital_milestone' => $m->id, 'error' => $e->getMessage()]);
            }
        }
        $m->update([
            'audit_status' => 'reported', 'audit_recommendation' => $recommendation, 'audit_notes' => $notes,
            'audit_evidence' => $evidence ? json_encode($evidence) : null, 'audit_completed_at' => now(),
        ]);
        AuditFee::releaseCapital($m->refresh());

        $pitch = self::pitchOf($m);
        $advice = $recommendation === 'approve' ? 'recommends releasing it' : 'recommends asking for changes first';
        $this->tellProvider($pitch, (int) $pm->id, "{$pm->first_name} sent the audit report for {$m->title} ({$pitch->startup_name}): {$advice}. You decide the release.");
        $this->tellApplicant($pitch, "The audit of {$m->title} is done: the project manager {$advice}. The capital provider decides the release.");
    }

    /** Every capital audit assigned to this PM, as Project Manager Audits rows (final-approval stage) */
    public function rows(User $pm): Collection
    {
        $milestones = CapitalMilestone::where('auditor_id', $pm->id)->whereNotNull('audit_status')->latest('audit_requested_at')->get();
        if ($milestones->isEmpty()) return collect();
        $pitches = StartupPitches::with('capital_offer:id,offer_title,user_id')->whereIn('id', $milestones->pluck('app_id')->unique())->get()->keyBy('id');

        return $milestones->map(function (CapitalMilestone $m) use ($pitches) {
            $pitch = $pitches->get($m->app_id);
            $status = $m->audit_status;
            $live = ! ($m->fund_released || $m->release_status === CapitalRelease::RELEASED);

            return [
                'key' => 'capital:'.$m->id,
                'id' => $m->id,
                'source' => 'capital',
                'stage' => 'final_approval',
                'status' => $status,
                'business_name' => $pitch?->startup_name,
                'context' => $pitch?->capital_offer?->offer_title,
                'milestone_title' => $m->title,
                'amount' => $m->amount !== null ? (float) $m->amount : null,
                'requested_at' => self::iso($m->audit_requested_at),
                'decided_at' => self::iso($m->audit_completed_at),
                'notes' => $m->audit_notes,
                'fee' => $m->audit_fee !== null ? (float) $m->audit_fee : null,
                'fee_status' => $m->audit_fee_status,
                'request_notes' => $m->audit_request_notes,
                'recommendation' => $m->audit_recommendation,
                'evidence' => self::evidence($m),
                'needs_you' => $live && in_array($status, ['pending', 'accepted'], true),
                'can_respond' => $live && $status === 'pending',
                'can_decide' => false,
                'can_report' => $live && $status === 'accepted',
                'decides' => 'provider',
            ];
        });
    }

    // ── what the deal pages show ───────────────────────────────────────────────

    /**
     * Each milestone's `audit` (null when it never had one) and, for the provider, `can_request_audit`. The business owner
     * sees that it is audited and the outcome, not the fee.
     */
    public static function attach(StartupPitches $pitch, bool $provider, ?User $viewer): void
    {
        $milestones = $pitch->capital_milestones;
        $auditors = User::whereIn('id', $milestones->pluck('auditor_id')->filter()->unique())->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $admin = $provider && OrganizationPermission::isAdmin($viewer);

        foreach ($milestones as $m) {
            $a = $auditors->get($m->auditor_id);
            $audit = $m->audit_status ? [
                'status' => $m->audit_status,
                'open' => self::isOpen($m),
                'auditor' => $a ? ['id' => $a->id, 'name' => trim($a->first_name.' '.$a->last_name)] : null,
                'requested_at' => self::iso($m->audit_requested_at),
                'recommendation' => $m->audit_recommendation,
                'notes' => $m->audit_notes,
                'evidence' => self::evidence($m),
                'completed_at' => self::iso($m->audit_completed_at),
            ] : null;
            if ($audit && $provider) {
                $audit += [
                    'request_notes' => $m->audit_request_notes,
                    'fee' => $m->audit_fee !== null ? (float) $m->audit_fee : null,
                    'fee_status' => $m->audit_fee_status,
                    'cancel_reason' => $m->audit_cancel_reason,
                    'can_pay' => $admin && $m->audit_status === 'quoted',
                    'can_cancel' => $admin && in_array($m->audit_status, self::CANCELLABLE, true),
                ];
            }
            $m->setAttribute('audit', $audit);
            if ($provider) $m->setAttribute('can_request_audit', $admin && self::requestRefusal($viewer, $m) === null);
        }
    }

    public static function evidence(CapitalMilestone $m): array
    {
        $raw = $m->audit_evidence;
        $list = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

        return array_values(array_map(fn ($e) => ['name' => $e['name'] ?? 'Evidence', 'url' => CapitalFiles::url($e['path'] ?? null)], array_filter($list, 'is_array')));
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private static function pitchOf(CapitalMilestone $m): ?StartupPitches
    {
        return StartupPitches::with(['capital_offer', 'latestAgreement'])->find($m->app_id);
    }

    /** Only the capital owner or a capital super admin of this deal runs its audits */
    private static function providerRefusal(?User $user, ?StartupPitches $pitch): ?array
    {
        if (! $pitch || ! CapitalAccess::isProviderOf($user, $pitch)) return ['Application not found.', 404];
        if (! OrganizationPermission::isAdmin($user)) return ['Only the capital owner or a super admin can manage audits.', 403];

        return null;
    }

    private function assertAuditor(CapitalMilestone $m, User $pm): void
    {
        if ((int) $m->auditor_id !== (int) $pm->id || ! $m->audit_status) ProjectManagerAudits::refuse('This audit is not assigned to you.', 403);
        if (in_array($m->audit_status, ['cancelled', 'declined'], true)) ProjectManagerAudits::refuse('This audit is no longer open.', 409);
        if ($m->fund_released || $m->release_status === CapitalRelease::RELEASED) ProjectManagerAudits::refuse('The capital provider has already released this milestone.', 409);
    }

    private static function label(CapitalMilestone $m, StartupPitches $pitch): string
    {
        return trim(($m->title ?: 'a milestone').' ('.$pitch->startup_name.')');
    }

    private function orgName(StartupPitches $pitch): string
    {
        $profile = \App\Models\Capital\CapitalProfile::where('user_id', $pitch->capital_owner_id)->value('org_name');

        return $profile ?: 'A capital provider';
    }

    private static function iso($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }

    private function tellPm(int $to, int $from, string $text): void
    {
        try {
            (new \App\Service\Notification\NotificationService())->create($to, $from, $text, 'dashboard.serviceProvider.pmAudits.finalApproval?source=capital', 'capital');
        } catch (Throwable $e) {
            Log::warning('Capital audit notification failed', ['error' => $e->getMessage()]);
        }
    }

    private function tellProvider(StartupPitches $pitch, int $from, string $text, ?int $except = null): void
    {
        $to = array_values(array_filter(CapitalAccess::providerRecipients((int) $pitch->capital_owner_id), fn ($id) => $id !== (int) $except));
        if ($to) CapitalNotifier::send($to, $from, $text, CapitalNotifier::providerDeal($pitch->id, 'funding'));
    }

    private function tellApplicant(StartupPitches $pitch, string $text): void
    {
        CapitalNotifier::send([(int) $pitch->user_id], (int) $pitch->capital_owner_id, $text, CapitalNotifier::applicantDeal($pitch->id, 'funding'));
    }
}
