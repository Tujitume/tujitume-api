<?php

namespace App\Models\Programs;

use App\Models\Auth\User;
use App\Traits\HasS3Files;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Disbursement extends Model
{
    use HasFactory;

    use HasS3Files;

    protected function privateFileFields(): array
    {
        return [
            'receipt_file',
        ];
    }

    protected $fillable = [
        'milestone_id',
        'wallet_id',
        'supplier_id',
        'budget_item_id',
        'recipient_type',
        'recipient_user_id',
        'amount',
        'currency',
        'payment_method',
        'payment_reference',
        'receipt_file',
        'paid_at',
        'status',
        'failure_reason',
        'beneficiary_payment_justification',
        'authorized_by',
        'disbursed_at',
        'supplier_confirmed',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────

    public function milestone()
    {
        return $this->belongsTo(ProgramMilestone::class, 'milestone_id');
    }

    public function wallet()
    {
        return $this->belongsTo(ProgramWallet::class, 'wallet_id');
    }

    public function supplier()
    {
        return $this->belongsTo(MilestoneSupplier::class, 'supplier_id');
    }

    public function budgetItem()
    {
        return $this->belongsTo(MilestoneBudgetItem::class, 'budget_item_id');
    }

    public function recipientUser()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function authorizedBy()
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
