<?php

namespace App\Service\Audits;

use App\Models\Auth\User;
use App\Models\Finance\Transactions;
use App\Models\Misc\Setting;
use App\Models\Programs\MilestoneVerification;
use App\Models\Services\ServiceBookingMilestone;
use App\Service\LiprMpesa\LiprCallback;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Program\OrganizationMainWallet;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DECISION-9: a project manager audit is paid. The project manager quotes the fee when they accept; whoever asked for the
 * audit pays it up front into escrow; it is released to the project manager when their report is in, and refunded if the
 * audit is cancelled before the report (a declined audit is never paid: the fee is only quoted on accepting).
 *
 * Where the money waits (audit_fee_status: held → released | refunded, or release_failed when the payout did not go):
 *   program check:  the organization wallet (DECISION-5/6). Holding lowers organizations.wallet_balance (so it cannot be
 *                   withdrawn or moved to a program); the payout is sent from the organization's own LIPR account.
 *   service booking: the customer pays from their LIPR wallet into Tujitume's wallet (as the booking itself); the payout
 *                   goes from there to the project manager, a refund back to the customer.
 *   capital milestone: the same as a service booking, paid by the capital provider (owner or super admin) who pays it.
 * Every payout is recorded as a pm_audit_fee transaction for both sides (the payer's debit, the project manager's credit).
 */
class AuditFee
{
    /** A business audit fee taken off the owner's release whose payout to the PM failed: Tujitume holds it for the retry. */
    public const DEDUCTED_UNPAID = 'deducted_unpaid';

    public static function label(float $fee): string
    {
        return 'KES '.number_format($fee, 2);
    }

    // ── program checks (MPRV / mid-milestone / final approval) ─────────────────

    public static function holdForProgram(MilestoneVerification $v, User $payer): void
    {
        $fee = (float) $v->audit_fee;
        if ($fee <= 0) return;
        $organization = OrganizationMainWallet::organizationOf($v->milestone->application->program);
        if (! $organization) ProjectManagerAudits::refuse(OrganizationMainWallet::MISSING);

        // Throws 422 with the free balance when the organization wallet does not cover the fee
        OrganizationMainWallet::adjust($organization, -$fee);
        $v->update(['audit_fee_status' => 'held', 'audit_fee_paid_by' => $payer->id]);
    }

    public static function refundProgram(MilestoneVerification $v): void
    {
        if ($v->audit_fee_status !== 'held') return;
        $organization = OrganizationMainWallet::organizationOf($v->milestone->application->program);
        if ($organization) OrganizationMainWallet::adjust($organization, (float) $v->audit_fee);
        $v->update(['audit_fee_status' => 'refunded']);
    }

    public static function releaseProgram(MilestoneVerification $v): void
    {
        if ($v->audit_fee_status !== 'held') return;
        $pm = User::find($v->auditor_id);
        $from = OrganizationMainWallet::account($v->milestone->application->program);
        $transfer = self::send((float) $v->audit_fee, $pm?->lipr_wallet, $from, 'mv-'.$v->id, 'Project manager audit fee');

        if (! $transfer['success']) {
            $v->update(['audit_fee_status' => 'release_failed']);
            Log::warning('Audit fee payout failed', ['verification' => $v->id, 'error' => $transfer['message']]);
            return;
        }
        $v->update(['audit_fee_status' => 'released', 'audit_fee_reference' => $transfer['reference']]);
        self::record((int) ($v->audit_fee_paid_by ?: $v->decided_by), (int) $v->auditor_id, (float) $v->audit_fee, $transfer['reference']);
    }

    // ── service booking milestones ─────────────────────────────────────────────

    public static function holdForService(ServiceBookingMilestone $m, User $customer): void
    {
        $fee = (float) $m->audit_fee;
        if ($fee <= 0) return;
        if (! $customer->lipr_wallet) ProjectManagerAudits::refuse('Set up your M-Pesa (LIPR) wallet first: the audit fee is paid from it.');

        $transfer = self::send($fee, self::platformWallet(), $customer->lipr_wallet, 'sa-'.$m->id, 'Project manager audit fee (held)');
        if (! $transfer['success']) ProjectManagerAudits::refuse('The audit fee could not be paid from your wallet. Nothing was charged; please try again.', 502);
        $m->update(['audit_fee_status' => 'held', 'audit_fee_reference' => $transfer['reference']]);
    }

    public static function refundService(ServiceBookingMilestone $m, User $customer): void
    {
        if ($m->audit_fee_status !== 'held') return;
        $transfer = self::send((float) $m->audit_fee, $customer->lipr_wallet, self::platformWallet(), 'sa-'.$m->id, 'Project manager audit fee refund');
        $m->update(['audit_fee_status' => $transfer['success'] ? 'refunded' : 'refund_failed']);
        if (! $transfer['success']) Log::warning('Audit fee refund failed', ['booking_milestone' => $m->id, 'error' => $transfer['message']]);
    }

    public static function releaseService(ServiceBookingMilestone $m, int $customerId): void
    {
        if ($m->audit_fee_status !== 'held') return;
        $pm = User::find($m->auditor_id);
        $transfer = self::send((float) $m->audit_fee, $pm?->lipr_wallet, self::platformWallet(), 'sa-'.$m->id, 'Project manager audit fee');
        if (! $transfer['success']) {
            $m->update(['audit_fee_status' => 'release_failed']);
            Log::warning('Audit fee payout failed', ['booking_milestone' => $m->id, 'error' => $transfer['message']]);
            return;
        }
        $m->update(['audit_fee_status' => 'released', 'audit_fee_reference' => $transfer['reference']]);
        self::record($customerId, (int) $m->auditor_id, (float) $m->audit_fee, $transfer['reference']);
    }

    // ── capital milestones (the capital provider asks before releasing) ────────
    // The provider (owner or capital super admin) who pays the fee pays it from their LIPR wallet into Tujitume's
    // wallet, as a service booking customer does; the payout goes from there to the project manager, a refund back to
    // whoever paid it.

    public static function holdForCapital(\App\Models\Capital\CapitalMilestone $m, User $payer): void
    {
        $fee = (float) $m->audit_fee;
        if ($fee <= 0) return;
        if (! $payer->lipr_wallet) ProjectManagerAudits::refuse('Set up your M-Pesa (LIPR) wallet first: the audit fee is paid from it.');

        $transfer = self::send($fee, self::platformWallet(), $payer->lipr_wallet, 'ca-'.$m->id, 'Project manager audit fee (held)');
        if (! $transfer['success']) ProjectManagerAudits::refuse('The audit fee could not be paid from your wallet. Nothing was charged; please try again.', 502);
        $m->update(['audit_fee_status' => 'held', 'audit_fee_reference' => $transfer['reference'], 'audit_fee_paid_by' => $payer->id]);
    }

    public static function refundCapital(\App\Models\Capital\CapitalMilestone $m): void
    {
        if ($m->audit_fee_status !== 'held') return;
        $payer = User::find($m->audit_fee_paid_by);
        $transfer = self::send((float) $m->audit_fee, $payer?->lipr_wallet, self::platformWallet(), 'ca-'.$m->id, 'Project manager audit fee refund');
        $m->update(['audit_fee_status' => $transfer['success'] ? 'refunded' : 'refund_failed']);
        if (! $transfer['success']) Log::warning('Capital audit fee refund failed', ['capital_milestone' => $m->id, 'error' => $transfer['message']]);
    }

    public static function releaseCapital(\App\Models\Capital\CapitalMilestone $m): void
    {
        if ($m->audit_fee_status !== 'held') return;
        $pm = User::find($m->auditor_id);
        $transfer = self::send((float) $m->audit_fee, $pm?->lipr_wallet, self::platformWallet(), 'ca-'.$m->id, 'Project manager audit fee');
        if (! $transfer['success']) {
            $m->update(['audit_fee_status' => 'release_failed']);
            Log::warning('Capital audit fee payout failed', ['capital_milestone' => $m->id, 'error' => $transfer['message']]);
            return;
        }
        $m->update(['audit_fee_status' => 'released', 'audit_fee_reference' => $transfer['reference']]);
        self::record((int) $m->audit_fee_paid_by, (int) $m->auditor_id, (float) $m->audit_fee, $transfer['reference']);
    }

    // ── business milestones (investors vote for the PM) ────────────────────────
    // The investors' money for the milestone is already held in Tujitume's wallet until it is released, so the PM's
    // fee is held from it: from the 75% at a pre-release audit, from the 25% at a mid-milestone audit. It is paid to the
    // PM when their report is in, and the next release to the business owner is that much smaller (the requesters, the
    // investors, pay it out of their own money). At final approval everything has been released, so no fee can be held.

    public static function holdForBusiness(\App\Models\Shared\PMAudit $audit): void
    {
        $fee = (float) $audit->fee;
        if ($fee <= 0) return;
        $milestone = \App\Models\Milestones\Milestones::find($audit->milestone_id);
        $portion = match ($audit->type) {
            'pre_release' => $milestone && !$milestone->fund_released_75 ? 0.75 : 0,
            'mid_milestone' => $milestone && !$milestone->fund_released ? 0.25 : 0,
            default => 0,
        };
        if ($portion <= 0) {
            ProjectManagerAudits::refuse('There is no milestone money left to pay an audit fee from at this stage. Accept without a fee.');
        }
        // A business's money (milestones) is in KSh, as the fee is
        $heldKes = (float) $milestone->amount * $portion;
        if ($fee > $heldKes * 0.2) { // a fee above 20% of the money it audits is refused
            ProjectManagerAudits::refuse('The fee is too high for this milestone (at most '.self::label(round($heldKes * 0.2)).').');
        }
        $audit->update(['fee_status' => 'held']);
    }

    public static function releaseBusiness(\App\Models\Shared\PMAudit $audit): void
    {
        if ($audit->fee_status !== 'held') return;
        $pm = User::find($audit->assigned_pm_id);
        $transfer = self::send((float) $audit->fee, $pm?->lipr_wallet, self::platformWallet(), 'pma-'.$audit->id, 'Project manager audit fee');
        if (! $transfer['success']) {
            $audit->update(['fee_status' => 'release_failed']);
            Log::warning('Business audit fee payout failed', ['pm_audit' => $audit->id, 'error' => $transfer['message']]);
            return;
        }
        $audit->update(['fee_status' => 'released']);
        $ownerId = (int) \App\Models\Milestones\Milestones::with('listing')->find($audit->milestone_id)?->listing?->user_id;
        self::record($ownerId, (int) $audit->assigned_pm_id, (float) $audit->fee, $transfer['reference']);
    }

    /** KSh to take off this milestone's next release (business money is KSh): audit fees paid to PMs and not yet deducted. */
    public static function businessDeductionUsd(int $milestoneId): float
    {
        // a fee whose payout failed is still owed to the PM: it comes off too and waits in Tujitume's wallet for the retry
        $kes = (float) \App\Models\Shared\PMAudit::where('milestone_id', $milestoneId)->whereIn('fee_status', ['released', 'release_failed'])->sum('fee');
        return $kes > 0 ? round($kes, 2) : 0.0;
    }

    public static function markBusinessDeducted(int $milestoneId): void
    {
        \App\Models\Shared\PMAudit::where('milestone_id', $milestoneId)->where('fee_status', 'released')->update(['fee_status' => 'deducted']);
        \App\Models\Shared\PMAudit::where('milestone_id', $milestoneId)->where('fee_status', 'release_failed')->update(['fee_status' => self::DEDUCTED_UNPAID]);
    }

    // ── retry a payout that failed ─────────────────────────────────────────────

    /**
     * The project manager asks again for a fee whose payout failed (release_failed), usually after setting up their LIPR
     * wallet. The failed state is claimed atomically (back to held) so a double click cannot pay twice; the normal
     * release then runs and either pays (released) or marks it release_failed again. Returns the new fee status.
     */
    public static function retry(string $source, int $id, User $pm): string
    {
        [$model, $column, $auditor] = match ($source) {
            'program' => [MilestoneVerification::find($id), 'audit_fee_status', 'auditor_id'],
            'service' => [ServiceBookingMilestone::with('booking')->find($id), 'audit_fee_status', 'auditor_id'],
            'capital' => [\App\Models\Capital\CapitalMilestone::find($id), 'audit_fee_status', 'auditor_id'],
            'business' => [\App\Models\Shared\PMAudit::find($id), 'fee_status', 'assigned_pm_id'],
            default => [null, null, null],
        };
        if (! $model) ProjectManagerAudits::refuse('Audit not found.', 404);
        if ((int) $model->{$auditor} !== (int) $pm->id) ProjectManagerAudits::refuse('Only the project manager who did this audit can ask for its fee again.', 403);
        $failedAs = $model->{$column};
        if (! in_array($failedAs, $source === 'business' ? ['release_failed', self::DEDUCTED_UNPAID] : ['release_failed'], true)) {
            ProjectManagerAudits::refuse('This audit fee is not waiting on a failed payout.', 409);
        }
        if (! $pm->lipr_wallet) ProjectManagerAudits::refuse('Set up your M-Pesa (LIPR) wallet first: the fee is paid into it.');

        $claimed = $model->newQuery()->whereKey($model->getKey())->where($column, $failedAs)->update([$column => 'held']);
        if (! $claimed) ProjectManagerAudits::refuse('This audit fee is already being paid.', 409);
        $model->refresh();

        match ($source) {
            'program' => self::releaseProgram($model),
            'service' => self::releaseService($model, (int) $model->booking?->booker_id),
            'capital' => self::releaseCapital($model),
            'business' => self::releaseBusiness($model),
        };
        $model->refresh();
        // already taken off the owner's release: paid now it is settled (deducted), failed again it stays deducted_unpaid
        if ($failedAs === self::DEDUCTED_UNPAID) {
            $model->update([$column => $model->{$column} === 'released' ? 'deducted' : self::DEDUCTED_UNPAID]);
        }

        return (string) $model->{$column};
    }

    /** LIPR failed a payout the app had counted as sent: the fee goes back to release_failed and its records are removed. */
    public static function payoutFailed(string $prefix, int $id, ?string $reference): bool
    {
        [$model, $column] = match ($prefix) {
            'mv' => [MilestoneVerification::find($id), 'audit_fee_status'],
            'sa' => [ServiceBookingMilestone::find($id), 'audit_fee_status'],
            'ca' => [\App\Models\Capital\CapitalMilestone::find($id), 'audit_fee_status'],
            'pma' => [\App\Models\Shared\PMAudit::find($id), 'fee_status'],
            default => [null, null],
        };
        if (! $model) return false;
        $stored = $prefix === 'pma' ? null : $model->audit_fee_reference;
        if ($reference && $stored && $stored !== $reference) return false;
        // a business fee already taken off the owner's release stays taken off: it waits as deducted_unpaid
        $claimed = $model->newQuery()->whereKey($model->getKey())->where($column, 'released')->update([$column => 'release_failed'])
            || ($prefix === 'pma' && $model->newQuery()->whereKey($model->getKey())->where($column, 'deducted')->update([$column => self::DEDUCTED_UNPAID]));
        if (! $claimed) return false;
        if ($reference ??= $stored) Transactions::where('type', 'pm_audit_fee')->where('reference_id', $reference)->delete();

        return true;
    }

    // ── shared ─────────────────────────────────────────────────────────────────

    /** @return array{success: bool, reference: ?string, message: ?string} */
    private static function send(float $amount, ?string $to, ?string $from, string $listing, string $narration): array
    {
        if (! $to) return ['success' => false, 'reference' => null, 'message' => 'The receiver has no LIPR wallet.'];
        if (! $from) return ['success' => false, 'reference' => null, 'message' => 'The payer has no LIPR wallet.'];
        try {
            $res = app(LiprW2W::class)->send((int) round($amount), $to, $from, null, [
                'result_url' => LiprCallback::url('/lipr-callback/audit-fee'),
                'listing_id' => $listing,
                'narration' => $narration,
            ]);
        } catch (Throwable $e) {
            $res = ['success' => false, 'message' => $e->getMessage()];
        }

        return [
            'success' => (bool) ($res['success'] ?? false),
            'reference' => $res['reference'] ?? null,
            'message' => $res['message'] ?? (is_array($res['errors'] ?? null) ? implode(' ', $res['errors']) : null),
        ];
    }

    private static function platformWallet(): ?string
    {
        return Setting::where('key', 'platform_lipr_wallet')->value('value');
    }

    /** The payer's debit and the project manager's credit, so both see the fee in their transactions. */
    private static function record(int $payerId, int $pmId, float $fee, ?string $reference): void
    {
        foreach ([[$payerId, 'debit'], [$pmId, 'credit']] as [$userId, $direction]) {
            if (! $userId) continue;
            Transactions::create([
                'user_id' => $userId, 'recipient_id' => $pmId ?: null, 'type' => 'pm_audit_fee', 'direction' => $direction,
                'method' => 'lipr', 'gross_amount' => $fee, 'fee_amount' => 0, 'net_amount' => $fee,
                'reference_id' => $reference, 'status' => 'settled', 'created_by' => $payerId ?: null,
            ]);
        }
    }
}
