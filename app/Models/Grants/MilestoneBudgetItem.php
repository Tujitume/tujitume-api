<?php

namespace App\Models\Grants;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MilestoneBudgetItem extends Model
{
    use HasFactory;
    protected $fillable = [
        'milestone_id',
        'supplier_id',
        'item_description',
        'unit_cost',
        'quantity',
        'total_cost',
        'purpose_type',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'quantity' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    // ─────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────

    public function milestone()
    {
        return $this->belongsTo(GrantMilestone::class, 'milestone_id');
    }

    public function supplier()
    {
        return $this->belongsTo(MilestoneSupplier::class, 'supplier_id');
    }

    public function disbursements()
    {
        return $this->hasMany(Disbursement::class, 'budget_item_id');
    }
}
