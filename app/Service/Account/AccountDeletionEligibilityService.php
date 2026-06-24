<?php
namespace App\Service\Account;
use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Capital\StartupPitches;
use App\Models\Grants\GrantApplication;
use App\Models\Services\serviceBook;

class AccountDeletionEligibilityService
{
    protected User $user;
    protected array $reasons = [];

    public function __construct(User $user)
    {
        $this->user = $user;
    }
    public function isDeletable(): bool
    {
        $this->reasons = [];

        $this->checkActiveInvestments();
        $this->checkActiveServiceBookings();
        $this->checkUserReceivedFundingGrant();
        $this->checkUserReceivedFundingCapital();

        if (empty($this->reasons)) {
            return true;
        }

        return false;
    }

    public function preventingReason(): array
    {
        return $this->reasons;
    }

    // H E L P E R S

    protected function checkActiveInvestments(): void
    {
        $hasActiveInvestment = AcceptedBids::where(function ($q) {
            $q->where('investor_id', $this->user->id)
                ->orWhere('owner_id', $this->user->id);
        })
            ->whereHas('listing', function ($q) {
                $q->whereColumn('amount_collected', '<', 'investment_needed');
            })
            ->exists();

        if ($hasActiveInvestment) {
            $this->reasons[] = [
                'code'   => 'active_investment',
                'message' => 'You have active investments that are still ongoing.',
            ];
        }
    }

    /**
     * User has an active service booking
     */
    protected function checkActiveServiceBookings(): void
    {
        $ActiveBookings = serviceBook::where(function ($q) {
            $q->where('booker_id', $this->user->id)
                ->orWhere('service_owner_id', $this->user->id);
            })
            ->where('status', 'Confirmed')
            ->exists();


        if ($ActiveBookings) {
            $this->reasons[] = [
                'code'    => 'active_service_booking',
                'message' => 'You have an active service booking currently running.',
            ];
        }
    }

    /**
     * User personally received grant or capital funding
     */
    protected function checkUserReceivedFundingGrant(): void
    {
        $hasActiveApplications = GrantApplication::where( function ($q) {
            $q->where('user_id', $this->user->id)
                ->orWhere('grant_owner_id', $this->user->id);
            })
            ->whereHas('grant_milestones', function ($q) {
                $q->where('fund_released', 1);
            })
            ->exists();

        if ($hasActiveApplications) {
            $this->reasons[] = [
                'code'    => 'grant_active_funding',
                'message' => 'You have active grant milestone funds released to a business.',
            ];
        }
    }

    protected function checkUserReceivedFundingCapital(): void
    {
        $hasActiveApplications = StartupPitches::where( function ($q) {
            $q->where('user_id', $this->user->id)
                ->orWhere('capital_owner_id', $this->user->id);
            })
            ->whereHas('capital_milestones', function ($q) {
                $q->where('fund_released', 1);
            })
            ->exists();

        if ($hasActiveApplications) {
            $this->reasons[] = [
                'code'    => 'capital_active_funding',
                'message' => 'You have active capital milestone funds released to a business.',
            ];
        }
    }

}
