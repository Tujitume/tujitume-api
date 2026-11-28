<?php

namespace App\Service\Audits;

use App\Models\Auth\User;
use App\Models\Programs\MilestoneVerification;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Shared\PMAudit;
use App\Service\Services\ServiceBookingFlow;
use App\Service\Notification\ProgramNotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Project Manager Audits: every audit a project manager (a service provider, or a Business Coach) is asked to do, in
 * one place, by stage. Two sources today:
 *   business: p_m_audits, chosen by a business's investors. The PM accepts or declines, then decides (approve releases
 *             the milestone's funds, as MilestonePMController::audit_review always did).
 *   program:  milestone_verifications a program owner sent for an audit (decision = audit_requested, auditor_id). The
 *             PM accepts or declines, then sends a report with a recommendation; the program owner decides.
 *   service:  a "Done" service booking milestone whose customer asked for an audit before approving (DECISION-9). The PM
 *             accepts (quoting a fee) or declines, then reports; the customer decides.
 *   capital:  an unreleased capital milestone whose capital provider asked for an audit before releasing it
 *             (CapitalAudits). The PM accepts (quoting a fee) or declines, then reports; the provider decides.
 *
 * DECISION-9: audits are paid. Accepting quotes a fee; with a fee the audit is "quoted" until the requester pays it into
 * escrow (AuditFee), then "accepted". The report releases the fee to the PM; a cancel before the report refunds it.
 * DECISION-8 change: an "audit needed" check can be skipped by the owner team, and an open audit cancelled.
 *
 * Stages: pre_release (program: the MPRV), mid_milestone, final_approval.
 */
class ProjectManagerAudits
{
    public const STAGES = ['pre_release', 'mid_milestone', 'final_approval'];
    public const SOURCES = ['business', 'program', 'service', 'capital'];

    /** A program verification's type for a stage, and back. */
    private const PROGRAM_TYPE = ['pre_release' => 'mprv', 'mid_milestone' => 'mid_milestone', 'final_approval' => 'final_approval'];

    public static function refuse(string $message, int $status = 422): never
    {
        throw new RuntimeException($message, $status);
    }

    /** Every audit of one stage for this PM, business and program together, newest first. */
    public function list(User $pm, string $stage): Collection
    {
        if (! in_array($stage, self::STAGES, true)) self::refuse('Unknown audit stage.', 404);

        return $this->business($pm, $stage)->concat($this->program($pm, $stage))
            // a service booking audit checks delivered work before it is paid: a final-approval kind of audit
            ->concat($stage === 'final_approval' ? $this->service($pm) : collect())
            // a capital audit checks a milestone before the capital provider releases it (CapitalAudits)
            ->concat($stage === 'final_approval' ? app(CapitalAudits::class)->rows($pm) : collect())
            // a fee whose payout failed can be asked for again (AuditFee::retry)
            ->map(fn (array $row) => $row + ['can_retry_fee' => in_array($row['fee_status'] ?? null, ['release_failed', AuditFee::DEDUCTED_UNPAID], true)])
            ->sortByDesc('requested_at')->values();
    }

    /** For the overview: per stage, how many audits each source has and how many are waiting on the PM. */
    public function summary(User $pm): array
    {
        $out = [];
        foreach (self::STAGES as $stage) {
            $rows = $this->list($pm, $stage);
            $out[$stage] = [
                'total' => $rows->count(),
                'waiting' => $rows->where('needs_you', true)->count(),
                'by_source' => collect(self::SOURCES)->mapWithKeys(fn ($s) => [$s => $rows->where('source', $s)->count()])->all(),
            ];
        }

        return $out;
    }

    /**
     * Who a program owner can ask to audit: service providers, those offering project management first, then by
     * rating. `search` matches the name. Never the owner themselves.
     */
    public function candidates(User $owner, ?string $search = null, int $limit = 20): Collection
    {
        $pm = fn ($q) => $q->whereIn('category', ['0', 'project_management', 'Project Management'])->where('active', 1);

        return User::query()
            ->where('user_type_id', 3)->whereKeyNot($owner->id)
            // every word of the search matches the first or the last name ("jane wa" finds Jane Wanjiru)
            ->when(trim((string) $search) !== '', function ($q) use ($search) {
                foreach (preg_split('/\s+/', trim($search)) as $word) {
                    $like = '%'.addcslashes($word, '%_\\').'%';
                    $q->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
                }
            })
            ->withCount(['services as pm_services_count' => $pm])
            ->withAvg('services as rating', 'rating')
            ->orderByDesc('pm_services_count')->orderByDesc('rating')->orderBy('first_name')
            ->limit($limit)->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => trim($u->first_name.' '.$u->last_name),
                'image' => $u->image,
                'rating' => $u->rating !== null ? round((float) $u->rating, 1) : null,
                'offers_project_management' => $u->pm_services_count > 0,
            ]);
    }

    // ── business ───────────────────────────────────────────────────────────────

    private function business(User $pm, string $stage): Collection
    {
        $with = match ($stage) {
            'mid_milestone' => ['midMilestone.milestone.listing'],
            'pre_release' => ['preReleaseRequest.milestone.listing', 'preReleaseRequest.docs'],
            default => ['milestone.listing'],
        };

        return PMAudit::with($with)->where('assigned_pm_id', $pm->id)->where('type', $stage)->latest()->get()
            ->map(function (PMAudit $a) use ($stage) {
                $milestone = $a->midMilestone?->milestone ?? $a->preReleaseRequest?->milestone ?? $a->milestone;
                $status = $a->decision ?: $a->status; // approved / rejected once decided, else pending / accepted / rejected
                return [
                    'key' => 'business:'.$a->id,
                    'id' => $a->id,
                    'source' => 'business',
                    'stage' => $stage,
                    'status' => $a->status === 'rejected' && ! $a->decision ? 'declined' : $status,
                    'business_name' => $milestone?->listing?->name,
                    'context' => null,
                    'milestone_title' => $milestone?->title,
                    'amount' => $milestone?->amount !== null ? (float) $milestone->amount : null,
                    'requested_at' => $a->created_at?->toIso8601String(),
                    'decided_at' => $a->decided_at?->toIso8601String(),
                    'notes' => $a->notes,
                    'fee' => $a->fee !== null ? (float) $a->fee : null,
                    'fee_status' => $a->fee_status,
                    'request_notes' => null,
                    'recommendation' => null,
                    'needs_you' => in_array($a->status, ['pending', 'accepted'], true) && ! $a->decision,
                    'can_respond' => $a->status === 'pending',
                    'can_decide' => $a->status === 'accepted' && ! $a->decision,
                    'can_report' => false,
                    'decides' => 'you',
                ];
            });
    }

    /** The PM accepts or declines a business audit they were assigned. */
    public function businessRespond(PMAudit $audit, User $pm, string $action, ?float $fee = null): void
    {
        if ((int) $audit->assigned_pm_id !== (int) $pm->id) self::refuse('This audit is not assigned to you.', 403);
        if ($audit->status !== 'pending') self::refuse('You have already answered this audit.', 409);
        // DECISION-9: the fee is held from the investors' money still held for the milestone (AuditFee::holdForBusiness)
        \Illuminate\Support\Facades\DB::transaction(function () use ($audit, $action, $fee) {
            $audit->update(['status' => $action === 'accept' ? 'accepted' : 'rejected']
                + ($action === 'accept' && $fee !== null ? ['fee' => $fee, 'fee_status' => $fee > 0 ? 'quoted' : null] : []));
            if ($action === 'accept' && $fee > 0) {
                AuditFee::holdForBusiness($audit->refresh());
            }
        });
    }

    /** May the PM decide this business audit? (MilestonePMController::audit_review does the release.) */
    public static function assertBusinessDecision(PMAudit $audit, User $pm): void
    {
        if ((int) $audit->assigned_pm_id !== (int) $pm->id) self::refuse('This audit is not assigned to you.', 403);
        if ($audit->status !== 'accepted') self::refuse('Accept the audit before deciding it.');
        if ($audit->decision) self::refuse('This audit has already been decided.', 409);
    }

    // ── program ────────────────────────────────────────────────────────────────

    private function program(User $pm, string $stage): Collection
    {
        return MilestoneVerification::with('milestone.application.program')
            ->where('auditor_id', $pm->id)->where('verification_type', self::PROGRAM_TYPE[$stage])
            ->whereIn('decision', ['audit_requested', 'approved', 'rejected', 'modification_requested'])
            ->latest('audit_started_at')->get()
            ->map(function (MilestoneVerification $v) use ($stage) {
                $milestone = $v->milestone;
                $app = $milestone?->application;
                $audit = $v->audit_status ?: 'pending';
                // Once the owner has decided, that is the outcome shown
                $status = $v->decision === 'audit_requested' ? $audit : ($v->decision === 'approved' ? 'approved' : 'rejected');
                return [
                    'key' => 'program:'.$v->id,
                    'id' => $v->id,
                    'source' => 'program',
                    'stage' => $stage,
                    'status' => $status,
                    'business_name' => $app?->startup_name,
                    'context' => $app?->program?->program_title,
                    'milestone_title' => $milestone?->title ?? ($milestone?->sequence_order ? 'Milestone '.$milestone->sequence_order : null),
                    'amount' => $milestone?->amount !== null ? (float) $milestone->amount : null,
                    'requested_at' => ($v->audit_started_at ?? $v->decided_at ?? $v->created_at)?->toIso8601String(),
                    'decided_at' => $v->audit_completed_at?->toIso8601String(),
                    'notes' => $v->audit_notes,
                    'fee' => $v->audit_fee !== null ? (float) $v->audit_fee : null,
                    'fee_status' => $v->audit_fee_status,
                    'request_notes' => $v->decision_notes,
                    'recommendation' => $v->audit_recommendation,
                    'needs_you' => $v->decision === 'audit_requested' && in_array($audit, ['pending', 'accepted'], true),
                    'can_respond' => $v->decision === 'audit_requested' && $audit === 'pending',
                    'can_decide' => false,
                    'can_report' => $v->decision === 'audit_requested' && $audit === 'accepted',
                    'decides' => 'owner',
                ];
            });
    }

    /**
     * The auditor accepts (quoting their fee, DECISION-9) or declines a program audit. With a fee the audit waits for the
     * owner team to pay it ("quoted"); without one it starts at once. Declining hands the verification back to the
     * program owner (pending again), so they can decide it themselves or ask someone else.
     */
    public function programRespond(MilestoneVerification $v, User $pm, string $action, ?float $fee = null): void
    {
        $this->assertProgramAuditor($v, $pm);
        if (($v->audit_status ?: 'pending') !== 'pending') self::refuse('You have already answered this audit.', 409);
        $fee = round(max(0, (float) $fee), 2);

        DB::transaction(function () use ($v, $action, $fee) {
            if ($action === 'accept') {
                $v->update(['audit_status' => $fee > 0 ? 'quoted' : 'accepted', 'audit_fee' => $fee, 'audit_fee_status' => $fee > 0 ? 'awaiting_payment' : null]);
                return;
            }
            $v->update(['audit_status' => 'declined', 'decision' => 'pending', 'decided_by' => null, 'decided_at' => null]);
            if ($v->milestone?->status === 'audit_requested') $v->milestone->update(['status' => 'submitted']);
        });

        if ($action === 'accept' && $fee > 0) {
            $this->tellOwner($v, 'mprv.audit_quoted', [
                'fee_label' => AuditFee::label($fee),
                'email_title' => 'Pay the audit fee to start the audit',
                'email_message' => "{$pm->first_name} accepted the audit of {$this->milestoneLabel($v)} for a fee of ".AuditFee::label($fee).'. Pay it from the organization wallet in the deal room: it is held until the report is in, and refunded if you cancel the audit before then.',
            ]);
            return;
        }
        $this->tellOwner($v, $action === 'accept' ? 'mprv.audit_accepted' : 'mprv.audit_declined', [
            'email_title' => $action === 'accept' ? 'Audit Accepted' : 'Audit Declined',
            'email_message' => $action === 'accept'
                ? "{$pm->first_name} accepted the audit of {$this->milestoneLabel($v)}. You will be told when the report is in."
                : "{$pm->first_name} declined the audit of {$this->milestoneLabel($v)}. You can decide it yourself or ask another project manager.",
        ]);
    }

    /** The owner team pays the quoted fee from the organization wallet into escrow; the audit then starts. */
    public function programPay(MilestoneVerification $v, User $payer): void
    {
        $this->assertOwnerTeam($v);
        if ($v->decision !== 'audit_requested' || $v->audit_status !== 'quoted') self::refuse('There is no audit fee to pay for this check.', 409);

        // Claimed in one update, so a second click (or request) cannot take the fee twice
        DB::transaction(function () use ($v, $payer) {
            if (! \App\Models\Programs\MilestoneVerification::whereKey($v->id)->where('audit_status', 'quoted')->update(['audit_status' => 'accepted'])) {
                self::refuse('This audit fee was already paid.', 409);
            }
            AuditFee::holdForProgram($v, $payer);
            $v->refresh();
        });

        $this->tellAuditor($v, 'mprv.audit_paid', ['fee_label' => AuditFee::label((float) $v->audit_fee)]);
    }

    /** The auditor's report: what they recommend (approve / reject) and why. Releases the fee; the owner then decides. */
    public function programReport(MilestoneVerification $v, User $pm, string $recommendation, string $notes): void
    {
        $this->assertProgramAuditor($v, $pm);
        if ($v->audit_status !== 'accepted') {
            self::refuse(match ($v->audit_status) {
                'reported' => 'You have already sent your report.',
                'quoted' => 'The audit fee has not been paid yet. You can start once the program team pays it.',
                default => 'Accept the audit before sending a report.',
            }, 409);
        }

        if (! \App\Models\Programs\MilestoneVerification::whereKey($v->id)->where('audit_status', 'accepted')->update(['audit_status' => 'reported'])) {
            self::refuse('You have already sent your report.', 409);
        }
        $v->update([
            'audit_status' => 'reported',
            'audit_recommendation' => $recommendation,
            'audit_notes' => $notes,
            'audit_completed_at' => now(),
        ]);
        AuditFee::releaseProgram($v->refresh());

        $this->tellOwner($v, 'mprv.audit_completed', ['recommendation' => $recommendation]);
    }

    /**
     * DECISION-8 change: "Skip audit and review it myself". The audit a check needs after two send-backs is a suggestion:
     * the owner team may skip it and decide the check themselves (recorded in the organization audit log through the
     * verification's change, with the reason).
     */
    public function skip(MilestoneVerification $v, User $by, ?string $reason): void
    {
        $this->assertOwnerTeam($v);
        if (! \App\Service\Program\RepeatedRejectionAudit::waiting($v)) self::refuse('This check is not waiting for an audit.', 409);

        $v->update(['audit_status' => 'skipped', 'audit_skip_reason' => $reason, 'audit_skipped_by' => $by->id, 'audit_skipped_at' => now()]);
    }

    /**
     * The owner team cancels an audit before the report is in (the project manager has not answered, has quoted, or is
     * working on it). A held fee goes back to the organization wallet; the check is pending again for the owner.
     */
    public function programCancel(MilestoneVerification $v, User $by, ?string $reason): void
    {
        $this->assertOwnerTeam($v);
        if ($v->decision !== 'audit_requested' || ! in_array($v->audit_status ?: 'pending', ['pending', 'quoted', 'accepted'], true)) {
            self::refuse($v->audit_status === 'reported' ? 'The report is already in, so the audit cannot be cancelled. Decide the check.' : 'There is no open audit to cancel.', 409);
        }

        DB::transaction(function () use ($v, $by, $reason) {
            if (! \App\Models\Programs\MilestoneVerification::whereKey($v->id)->where('decision', 'audit_requested')->where(fn ($q) => $q->whereIn('audit_status', ['pending', 'quoted', 'accepted'])->orWhereNull('audit_status'))
                ->update(['audit_status' => 'cancelled'])) {
                self::refuse('There is no open audit to cancel.', 409);
            }
            AuditFee::refundProgram($v);
            $v->update([
                'decision' => 'pending', 'decided_by' => null, 'decided_at' => null,
                'audit_status' => 'cancelled', 'audit_skip_reason' => $reason, 'audit_skipped_by' => $by->id, 'audit_skipped_at' => now(),
            ]);
            if ($v->milestone?->status === 'audit_requested') $v->milestone->update(['status' => 'submitted']);
        });

        $this->tellAuditor($v, 'mprv.audit_cancelled', ['reason' => $reason]);
    }

    private function assertOwnerTeam(MilestoneVerification $v): void
    {
        if (! \App\Service\Program\ProgramAccess::owns($v->milestone->application->program)) self::refuse('Only the program team can do this.', 403);
    }

    private function assertProgramAuditor(MilestoneVerification $v, User $pm): void
    {
        if ((int) $v->auditor_id !== (int) $pm->id) self::refuse('This audit is not assigned to you.', 403);
        if ($v->decision !== 'audit_requested') self::refuse('The program owner has already decided this milestone.', 409);
    }

    private function milestoneLabel(MilestoneVerification $v): string
    {
        $m = $v->milestone;
        $name = $m?->application?->startup_name;

        return trim(($m?->title ?: 'milestone '.($m?->sequence_order ?? '')).($name ? " ({$name})" : ''));
    }

    private function tellOwner(MilestoneVerification $v, string $event, array $extra = []): void
    {
        $milestone = $v->milestone;
        $app = $milestone?->application;
        $owner = $app?->program_owner_id ? User::find($app->program_owner_id) : null;
        if (! $owner) return;
        try {
            app(ProgramNotificationService::class)->send($event, [$owner], $extra + [
                'business_name' => $app->startup_name,
                'milestone_number' => $milestone->sequence_order,
                'application_id' => $app->id,
                'program_id' => $app->program_id,
            ]);
        } catch (Throwable $e) {
            Log::warning('Audit notification failed', ['verification' => $v->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    private function tellAuditor(MilestoneVerification $v, string $event, array $extra = []): void
    {
        $pm = $v->auditor_id ? User::find($v->auditor_id) : null;
        $app = $v->milestone?->application;
        if (! $pm || ! $app) return;
        try {
            app(ProgramNotificationService::class)->send($event, [$pm], $extra + [
                'business_name' => $app->startup_name,
                'milestone_number' => $v->milestone->sequence_order,
                'application_id' => $app->id,
                'audit_stage' => $v->verification_type === 'mprv' ? 'pre_release' : $v->verification_type,
            ]);
        } catch (Throwable $e) {
            Log::warning('Audit notification failed', ['verification' => $v->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    // ── service bookings (DECISION-9) ──────────────────────────────────────────

    private function service(User $pm): Collection
    {
        return ServiceBookingMilestone::with('booking.service')->where('auditor_id', $pm->id)->whereNotNull('audit_status')
            ->latest('audit_requested_at')->get()
            ->map(function (ServiceBookingMilestone $m) {
                $status = $m->audit_status;
                return [
                    'key' => 'service:'.$m->id,
                    'id' => $m->id,
                    'source' => 'service',
                    'stage' => 'final_approval',
                    'status' => $status,
                    'business_name' => $m->booking?->service?->name,
                    'context' => 'Service booking #'.$m->booking_id,
                    'milestone_title' => $m->title,
                    'amount' => $m->amount !== null ? (float) $m->amount : null,
                    'requested_at' => $m->audit_requested_at ? \Illuminate\Support\Carbon::parse($m->audit_requested_at)->toIso8601String() : null,
                    'decided_at' => $m->audit_completed_at ? \Illuminate\Support\Carbon::parse($m->audit_completed_at)->toIso8601String() : null,
                    'notes' => $m->audit_notes,
                    'fee' => $m->audit_fee !== null ? (float) $m->audit_fee : null,
                    'fee_status' => $m->audit_fee_status,
                    'request_notes' => $m->audit_request_notes,
                    'recommendation' => $m->audit_recommendation,
                    'needs_you' => in_array($status, ['pending', 'accepted'], true),
                    'can_respond' => $status === 'pending',
                    'can_decide' => false,
                    'can_report' => $status === 'accepted',
                    'decides' => 'customer',
                ];
            });
    }

    /** The customer asks a project manager to audit a "Done" milestone before approving it. */
    public function serviceRequest(ServiceBooking $booking, ServiceBookingMilestone $m, User $customer, int $auditorId, ?string $notes): void
    {
        ServiceBookingFlow::assertCustomerStep($booking, $m, $customer, allowAudit: true);
        if (in_array($m->audit_status, ServiceBookingFlow::AUDIT_OPEN, true)) self::refuse('An audit of this milestone is already open.', 409);
        if ($auditorId === (int) $booking->service_owner_id) self::refuse('The provider of this service cannot audit their own work.');
        $pm = User::where('user_type_id', 3)->find($auditorId);
        if (! $pm) self::refuse('Pick a project manager.');

        $m->update([
            'auditor_id' => $pm->id, 'audit_status' => 'pending', 'audit_request_notes' => $notes, 'audit_requested_at' => now(),
            'audit_recommendation' => null, 'audit_notes' => null, 'audit_completed_at' => null,
            'audit_fee' => null, 'audit_fee_status' => null, 'audit_fee_reference' => null,
        ]);
        $this->tellService($pm->id, $customer->id, "{$customer->first_name} asked you to audit {$m->title} of {$booking->service?->name}. Open Project Manager Audits to accept (with your fee) or decline.", 'dashboard.serviceProvider.pmAudits.finalApproval');
    }

    public function serviceRespond(ServiceBookingMilestone $m, User $pm, string $action, ?float $fee = null): void
    {
        $this->assertServiceAuditor($m, $pm);
        if ($m->audit_status !== 'pending') self::refuse('You have already answered this audit.', 409);
        $fee = round(max(0, (float) $fee), 2);

        if ($action === 'accept') {
            $m->update(['audit_status' => $fee > 0 ? 'quoted' : 'accepted', 'audit_fee' => $fee, 'audit_fee_status' => $fee > 0 ? 'awaiting_payment' : null]);
        } else {
            $m->update(['audit_status' => 'declined']);
        }
        $customer = $m->booking?->booker_id;
        $text = match (true) {
            $action !== 'accept' => "{$pm->first_name} declined the audit of {$m->title}. You can approve it yourself or ask another project manager.",
            $fee > 0 => "{$pm->first_name} accepted the audit of {$m->title} for ".AuditFee::label($fee).'. Pay the fee to start the audit.',
            default => "{$pm->first_name} accepted the audit of {$m->title}. You will be told when the report is in.",
        };
        if ($customer) $this->tellService($customer, $pm->id, $text, ServiceBookingFlow::customerLinkKey($m->booking));
    }

    public function servicePay(ServiceBooking $booking, ServiceBookingMilestone $m, User $customer): void
    {
        if ((int) $booking->booker_id !== (int) $customer->id || (int) $m->booking_id !== (int) $booking->id) self::refuse('Only the person who booked can pay for this audit.', 403);
        if ($m->audit_status !== 'quoted') self::refuse('There is no audit fee to pay for this milestone.', 409);

        // Claimed first (one request only), then the fee is taken; if taking it fails the audit is back to quoted
        if (! \App\Models\Services\ServiceBookingMilestone::whereKey($m->id)->where('audit_status', 'quoted')->update(['audit_status' => 'paying'])) {
            self::refuse('This audit fee was already paid.', 409);
        }
        try {
            // Refreshed so the model knows it is "paying": rolling back to "quoted" below is then a real change and is saved
            // (it used to be skipped, leaving a failed payment stuck at "paying" with no way to pay or cancel)
            AuditFee::holdForService($m->refresh(), $customer);
        } catch (\Throwable $e) {
            $m->update(['audit_status' => 'quoted']);
            throw $e;
        }
        $m->update(['audit_status' => 'accepted']);
        $this->tellService((int) $m->auditor_id, $customer->id, "The audit fee of ".AuditFee::label((float) $m->audit_fee)." for {$m->title} is held for you. Start the audit and send your report.", 'dashboard.serviceProvider.pmAudits.finalApproval');
    }

    public function serviceReport(ServiceBookingMilestone $m, User $pm, string $recommendation, string $notes): void
    {
        $this->assertServiceAuditor($m, $pm);
        if ($m->audit_status !== 'accepted') self::refuse($m->audit_status === 'reported' ? 'You have already sent your report.' : ($m->audit_status === 'quoted' ? 'The audit fee has not been paid yet.' : 'Accept the audit before sending a report.'), 409);

        if (! \App\Models\Services\ServiceBookingMilestone::whereKey($m->id)->where('audit_status', 'accepted')->update(['audit_status' => 'reported'])) {
            self::refuse('You have already sent your report.', 409);
        }
        $m->update(['audit_status' => 'reported', 'audit_recommendation' => $recommendation, 'audit_notes' => $notes, 'audit_completed_at' => now()]);
        AuditFee::releaseService($m->refresh(), (int) $m->booking?->booker_id);
        if ($m->booking?->booker_id) {
            $this->tellService((int) $m->booking->booker_id, $pm->id, "{$pm->first_name} sent the audit report for {$m->title}: recommends to ".($recommendation === 'approve' ? 'approve' : 'ask for changes').'. You decide.', ServiceBookingFlow::customerLinkKey($m->booking));
        }
    }

    /** The customer cancels their audit before the report is in; a held fee is refunded to them. */
    public function serviceCancel(ServiceBooking $booking, ServiceBookingMilestone $m, User $customer): void
    {
        if ((int) $booking->booker_id !== (int) $customer->id || (int) $m->booking_id !== (int) $booking->id) self::refuse('Only the person who booked can cancel this audit.', 403);
        if (! in_array($m->audit_status, ['pending', 'quoted', 'accepted'], true)) self::refuse($m->audit_status === 'reported' ? 'The report is already in, so the audit cannot be cancelled.' : 'There is no open audit to cancel.', 409);

        if (! \App\Models\Services\ServiceBookingMilestone::whereKey($m->id)->whereIn('audit_status', ['pending', 'quoted', 'accepted'])->update(['audit_status' => 'cancelled'])) {
            self::refuse('There is no open audit to cancel.', 409);
        }
        AuditFee::refundService($m->refresh(), $customer);
        if ($m->auditor_id) $this->tellService((int) $m->auditor_id, $customer->id, "{$customer->first_name} cancelled the audit of {$m->title}. Any fee held is refunded.", 'dashboard.serviceProvider.pmAudits.finalApproval');
    }

    private function assertServiceAuditor(ServiceBookingMilestone $m, User $pm): void
    {
        if ((int) $m->auditor_id !== (int) $pm->id || ! $m->audit_status) self::refuse('This audit is not assigned to you.', 403);
        if ($m->released) self::refuse('The customer has already approved this milestone.', 409);
    }

    private function tellService(int $to, ?int $from, string $text, string $link): void
    {
        try {
            (new \App\Service\Notification\NotificationService())->create($to, $from, $text, $link, 'service');
        } catch (Throwable $e) {
            Log::warning('Service audit notification failed', ['error' => $e->getMessage()]);
        }
    }
}
