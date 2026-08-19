<?php
namespace App\Service\Misc;
use App\Models\Finance\Transactions;
use App\Models\Misc\Setting;
use Illuminate\Support\Facades\Auth;

class TransactionRecord
{
    public function create($user_id, $type, $method, $gross, $reference_id = null, $recipient_id = null, $status = 'settled' )
    {
        $user = Auth::user();
        $fee = Setting::where('key', 'tujitume_fee')->first()?->value ?? 0;

        if($type == 'withdraw' && ( $method == 'lipr-mobile' || $method == 'lipr-paybill' || $method == 'lipr-till') ){
            $fee_amount = 0;
        }
        else if($type == 'deposit' && $method == 'lipr'){
            $fee_amount = 0;
        }
        else {
            //$fee_amount_stripe = ($gross * 0.029) + 0.30;
            $base = $gross / (1+ ($fee / 100) ); // base / 1.05
            $fee_amount = ((float) $fee / 100) * $base;
        }

        //direction
        $creditTypes = [
            'deposit',
            'refund',
            'service_milestone',
            'business_milestone',
        ];

        $debitTypes = [
            'withdraw',
            'investment',
            'investment_awaiting',
            'service_fee',
        ];

        if (in_array($type, $creditTypes)) {
            $direction = 'credit';
        }
        elseif (in_array($type, $debitTypes)) {
            $direction = 'debit';
        }
        // role-based exceptions
        elseif (in_array($type, ['capital_milestone', 'program_milestone', 'program_milestone_bulk'])) {
            if(!Auth::check()){
                $direction = ($user_id == $recipient_id) ? 'credit' : 'debit';
            }
            else{
                $direction = ($user_id == $user->id ?? null) ? 'debit' : 'credit';
            }
        }
        elseif ($type === 'unlock_business') {
            $direction = ($user_id !== $user->id) ? 'credit' : 'debit';
        }

        if (!$direction) {
            throw new \LogicException("Unhandled transaction type: {$type}");
        }

        $net_amount = $gross - $fee_amount;

        $trx = Transactions::create([
            'user_id' => $user_id,
            'recipient_id'  => $recipient_id,
            'type'          => $type,
            'direction'     => $direction,
            'method'        => $method,
            'gross_amount'  => $gross,
            'fee_amount'    => $fee_amount,
            'net_amount'    => $net_amount,
            'reference_id'  => $reference_id,
            'status'        => $status,
            'created_by'    => $user_id,
        ]);
        return $trx;

        //types
//        'deposit',
//        'withdraw',
//        'unlock_business',
//        'investment', // investor pays into a business
//        'investment_awaiting',
//        'service_fee',
        //'service_milestone',
        //'business_milestone',
//        'program_milestone',
//        'program_milestone_bulk',
//        'capital_milestone',
//        'refund'
          //'subscription'
    }

}
