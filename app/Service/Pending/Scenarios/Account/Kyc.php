<?php

namespace App\Service\Pending\Scenarios\Account;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class Kyc extends Scenario
{
    public function key(): string
    {
        return 'account.kyc';
    }

    public function userTypes(): ?array
    {
        return [1, 2, 3, 4, 5, 6, 7];
    }

    public function collect(): array
    {
        // A team member's identity checks belong to the organization owner
        if ($this->type === 4 && (int) $this->user->id !== $this->ownerId) return [];

        $row = DB::table('kyc_verifications')->where('user_id', $this->user->id)->latest('id')->first();
        $status = $row->status ?? null;
        if ($row === null && ! in_array($this->type, [1, 3, 4, 5], true)) return [];
        if (! in_array($status, [null, 'draft', 'rejected'], true)) return [];

        $link = match ($this->type) {
            1 => 'dashboard.entrepreneur.settings.verify', 3 => 'dashboard.serviceProvider.kyc', 4 => 'dashboard.grantOrg.settings',
            2 => 'dashboard.investor.settings', 5 => 'dashboard.capitalOrg.settings', default => 'dashboard.reviewerGrantOrg.settings',
        };
        $rejected = $status === 'rejected';

        return [[
            'group' => 'account', 'count' => 1,
            'items' => [$this->item($rejected ? 'kyc.rejected' : 'kyc.incomplete', $rejected ? 'Your verification was not approved' : 'Finish verifying your account',
                $rejected ? ($row->rejection_reason ?: 'Fix the details and submit again.') : 'Some actions stay locked until you are verified.', $link, 'kyc')],
        ]];
    }
}
