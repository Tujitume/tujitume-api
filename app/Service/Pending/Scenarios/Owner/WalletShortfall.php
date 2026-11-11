<?php

namespace App\Service\Pending\Scenarios\Owner;

use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

/** Reviewers accepted but the program wallet cannot cover their fees (RoundReviewerController::checkWalletFundsForReviewers); the owner must deposit. */
class WalletShortfall extends Scenario
{
    public function key(): string
    {
        return 'owner.wallet';
    }

    public function userTypes(): ?array
    {
        return [4];
    }

    public function collect(): array
    {
        // The same sum the reviewer-acceptance check uses, across the program's open rounds, against the wallet balance
        $rows = DB::table('reviewer_orders as o')->join('program_rounds as pr', 'pr.id', '=', 'o.round_id')->join('programs as p', 'p.id', '=', 'pr.program_id')->leftJoin('program_wallets as w', 'w.program_id', '=', 'p.id')
            ->where('p.user_id', $this->ownerId)->where('pr.status', '!=', 'finalized')->where('o.order_type', 'round_review')->where('o.acceptance_status', 'accepted')
            ->where('o.payment_status', '!=', 'completed')->where('o.work_status', '!=', 'rejected')
            ->groupBy('p.id', 'p.program_title', 'w.balance')->select('p.id', 'p.program_title', DB::raw('coalesce(w.balance, 0) as balance'), DB::raw('sum(o.fee_amount) as required'))->get()
            ->filter(fn ($r) => (float) $r->required > (float) $r->balance)->values();

        return [['group' => 'manage', 'count' => $rows->count(), 'items' => $rows->take(self::MAX_ITEMS)->map(fn ($r) => $this->item('owner.wallet_shortfall', 'Add money to the program wallet', $r->program_title.' · short by '.number_format((float) $r->required - (float) $r->balance, 2), 'dashboard.programOrg.programsManage::id='.$r->id.'?deposit=1#program-wallet', $r->id))->all()]];
    }
}
