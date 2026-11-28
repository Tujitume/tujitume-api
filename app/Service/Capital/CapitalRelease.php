<?php

namespace App\Service\Capital;

use App\Models\Auth\User;
use App\Models\Capital\CapitalMilestone;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\StartupPitches;
use App\Service\Balance\CheckoutAmountCalculator;
use App\Service\Organization\OrganizationPermission;
use App\Service\Misc\TransactionRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Releasing capital to an entrepreneur, milestone by milestone (Stripe card or M-Pesa / LIPR).
 *
 * Rules
 *  - Only the capital owner or a capital super admin pays, for an application made to them that is accepted and whose
 *    newest terms agreement is accepted.
 *  - Milestones are released in order (an earlier unreleased one blocks a later one); percent=100 releases every
 *    milestone still unreleased, at once.
 *  - Fees (ISSUE-22 / DECISION-5, the program rule): the entrepreneur receives the full milestone amount; the platform and
 *    gateway fee is charged to the capital provider on top.
 *  - A milestone with an open project manager audit (CapitalAudits::OPEN) is not released (409) until the report is in
 *    or the provider cancels the audit; the report never releases by itself, the provider decides.
 *  - The release is recorded (release_status = processing, release_reference) BEFORE any money call. A failed money call
 *    puts it back (release_status = failed, nothing released); a second attempt while one is processing is refused, so
 *    the provider is never charged twice nor charged without a record.
 */
class CapitalRelease
{
    public const PROCESSING = 'processing';
    public const RELEASED = 'released';
    public const FAILED = 'failed';

    /** Why this person may not release this milestone now, or null. [message, http status] */
    public static function refusal(?User $user, CapitalMilestone $milestone, bool $all = false): ?array
    {
        $pitch = StartupPitches::with(['capital_offer', 'latestAgreement'])->find($milestone->app_id);
        if (! $pitch || ! $pitch->capital_offer || ! CapitalAccess::isProviderOf($user, $pitch)) {
            return ['Application not found.', 404];
        }
        if (! OrganizationPermission::isAdmin($user)) {
            return ['Only the capital owner or a super admin can release funds.', 403];
        }
        if ((int) $pitch->status !== StartupPitches::STATUS_ACCEPTED) {
            return ['Funds can only be released for an accepted application.', 422];
        }
        if (($pitch->latestAgreement?->status) !== 'accepted') {
            return ['The terms agreement must be accepted before funds are released.', 422];
        }
        if ($milestone->fund_released || $milestone->release_status === self::RELEASED || (int) $milestone->status === 1) {
            return ['This milestone has already been paid.', 409];
        }
        if ($milestone->release_status === self::PROCESSING) {
            return ['A release for this milestone is already under way.', 409];
        }
        // DECISION-9: an open project manager audit holds the release until its report is in or it is cancelled
        if (self::milestones($milestone, $all)->contains(fn ($m) => \App\Service\Audits\CapitalAudits::isOpen($m))) {
            return [\App\Service\Audits\CapitalAudits::BLOCKED_MESSAGE, 409];
        }
        if (! $all && self::unreleased($milestone->app_id)->first()?->id !== $milestone->id) {
            return ['Earlier milestones must be released first.', 422];
        }
        $amount = self::amount(self::milestones($milestone, $all));
        if ((float) $pitch->capital_offer->available_amount < $amount) {
            return ['Insufficient available funds on this capital offer.', 422];
        }

        return null;
    }

    /** Milestones of the application not yet released, oldest first */
    public static function unreleased(int $appId): Collection
    {
        return CapitalMilestone::where('app_id', $appId)
            ->where(fn ($q) => $q->whereNull('release_status')->orWhere('release_status', '!=', self::RELEASED))
            ->where('fund_released', false)
            ->orderBy('id')->get();
    }

    /** What this release pays: the milestone, or (percent=100) every unreleased milestone */
    public static function milestones(CapitalMilestone $milestone, bool $all): Collection
    {
        return $all ? self::unreleased($milestone->app_id) : collect([$milestone]);
    }

    public static function amount(Collection $milestones): float
    {
        return round((float) $milestones->sum(fn ($m) => (float) $m->amount), 2);
    }

    /** The fee charged to the provider on top of $amount: card (stripe) or M-Pesa */
    public static function fee(float $amount, string $channel): float
    {
        $calc = new CheckoutAmountCalculator();

        return $channel === 'stripe'
            ? round($calc->stripe($amount) - $amount, 2)
            : round($amount - $calc->mpesaGC($amount, 'mpesa'), 2);
    }

    /** Record the release before any money moves. Throws (409) when one is already processing or released. */
    public static function begin(Collection $milestones, string $reference, float $fee): void
    {
        DB::transaction(function () use ($milestones, $reference, $fee) {
            $rows = CapitalMilestone::whereIn('id', $milestones->pluck('id'))->lockForUpdate()->get();
            foreach ($rows as $row) {
                if ($row->fund_released || in_array($row->release_status, [self::PROCESSING, self::RELEASED], true)) {
                    throw new \RuntimeException('A release for this milestone is already under way.', 409);
                }
            }
            $share = $rows->count() ? round($fee / $rows->count(), 2) : 0;
            CapitalMilestone::whereIn('id', $rows->pluck('id'))->update([
                'release_status' => self::PROCESSING, 'release_reference' => $reference, 'fee_amount' => $share,
            ]);
        });
    }

    /** The money call failed: nothing was released */
    public static function fail(Collection $milestones): void
    {
        CapitalMilestone::whereIn('id', $milestones->pluck('id'))->where('release_status', self::PROCESSING)
            ->update(['release_status' => self::FAILED]);
    }

    /**
     * The money moved: mark the milestones released, take the amount off the offer and write both sides' transactions,
     * all in one database transaction.
     */
    public static function complete(StartupPitches $pitch, Collection $milestones, string $method, string $reference, float $fee, ?callable $extra = null): void
    {
        $amount = self::amount($milestones);
        DB::transaction(function () use ($pitch, $milestones, $method, $reference, $fee, $amount, $extra) {
            CapitalMilestone::whereIn('id', $milestones->pluck('id'))->update([
                'status' => 1, 'fund_released' => true, 'release_status' => self::RELEASED,
                'released_at' => now(), 'release_reference' => $reference,
            ]);
            CapitalOffer::whereKey($pitch->capital_id)->lockForUpdate()->first()?->decrement('available_amount', $amount);

            $tx = new TransactionRecord();
            // The provider pays the amount plus the fee; the entrepreneur receives the full amount
            // (the direction is set here: a super admin may be the one paying for the owner)
            // the debit says what of it was fees (gross = amount + fee, net = what the business received)
            $tx->create($pitch->capital_owner_id, 'capital_milestone', $method, round($amount + $fee, 2), $reference, $pitch->user_id)?->update(['direction' => 'debit', 'fee_amount' => $fee, 'net_amount' => $amount]);
            $tx->create($pitch->user_id, 'capital_milestone', $method, $amount, $reference, $pitch->capital_owner_id)?->update(['direction' => 'credit']);

            if ($extra) {
                $extra();
            }
        });
    }

    public static function notifyReleased(StartupPitches $pitch, Collection $milestones): void
    {
        $pitch->loadMissing('capital_offer');
        $title = $milestones->count() > 1 ? 'All remaining milestones' : (string) $milestones->first()?->title;
        $text = $title.' fund for '.$pitch->capital_offer?->offer_title.' has been released.';
        CapitalNotifier::send([$pitch->user_id], (int) $pitch->capital_owner_id, $text, CapitalNotifier::applicantDeal($pitch->id, 'funding'),
            'opportunities.capital_milestone', ['capital' => $pitch->capital_offer?->offer_title, 'amount' => number_format(self::amount($milestones), 2), 'milestone_title' => $title],
            'Capital Milestone Released');
        CapitalNotifier::send(CapitalAccess::providerRecipients((int) $pitch->capital_owner_id), (int) $pitch->user_id,
            'You released '.$title.' for '.$pitch->startup_name.'.', CapitalNotifier::providerDeal($pitch->id, 'funding'));
    }
}
