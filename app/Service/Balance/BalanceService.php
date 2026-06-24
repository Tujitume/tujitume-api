<?php
namespace App\Service\Balance;

use App\Models\Auth\User;
use App\Models\Finance\BalanceLog;
use Illuminate\Support\Facades\Auth;

class BalanceService
{
    public function updateBalance(int $userId, float $amount, string $method = null)
    {
        $user = User::find($userId);
        $user->load('balance');

        $oldBalance = $user->balance?->balance ?? 0;
        $newBalance = $oldBalance + $amount;

        // Update balance
        $balance = $user->balance()->firstOrCreate(
            ['user_id' => $userId],
            ['balance' => 0]
        );
        $balance->increment('balance', $amount);

        // Log change
        BalanceLog::create([
            'user_id'       => $userId,
            'old_balance'   => $oldBalance ?? 0,
            'new_balance'   => $newBalance,
            'change_amount' => $amount,
            'type'          => 'deposit',
            'status'        => 'settled',
            'method'        =>  $method,
            'changed_by'    => Auth::id(), // null if system
        ]);

        return $newBalance;
    }

    public function updateBalanceMinus(int $userId, float $amount, string $method = null, float $unsettled_amount = 0)
    {
        $user = User::find($userId);
        $user->load('balance');

        $oldBalance = $user->balance?->balance ?? 0;
        $newBalance = $oldBalance - $amount;

        // Update balance
        $balance = $user->balance()->firstOrCreate(
            ['user_id' => $userId],
            ['balance' => 0]
        );

        if ($balance->balance < $amount) {
            throw new \Exception('Insufficient balance');
        }
        $balance->decrement('balance', $amount);
        $status = 'settled';

        // Log change
        BalanceLog::create([
            'user_id'       => $userId,
            'old_balance'   => $oldBalance ?? 0,
            'new_balance'   => $newBalance,
            'change_amount' => $amount,
            'unsettled_amount' => $unsettled_amount,
            'type'          => 'withdraw',
            'status'        => $status,
            'method'        => $method,
            'changed_by'    => Auth::id(), // null if system
        ]);

        return $newBalance;
    }

}

