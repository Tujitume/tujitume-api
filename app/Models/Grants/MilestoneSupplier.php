<?php

namespace App\Models\Grants;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MilestoneSupplier extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'quoted_amount' => 'decimal:2',
        'conflict_of_interest_declared' => 'boolean',
        'is_locked' => 'boolean',
    ];

    // ─────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────

    public function milestone()
    {
        return $this->belongsTo(GrantMilestone::class, 'milestone_id');
    }

    public function supplierDirectory()
    {
        return $this->belongsTo(SupplierDirectory::class, 'supplier_id');
    }


    public function budgetItems()
    {
        return $this->hasMany(MilestoneBudgetItem::class, 'supplier_id');
    }

    public function disbursements()
    {
        return $this->hasMany(Disbursement::class, 'supplier_id');
    }

    public function isPrimary(): bool
    {
        return $this->assignment_type === 'primary';
    }

    /**
     * Check if this is an approved supplier
     */
    public function isApproved(): bool
    {
        return $this->assignment_type === 'approved';
    }

    /**
     * Check if this is a preferred supplier
     */
    public function isPreferred(): bool
    {
        return $this->assignment_type === 'preferred';
    }

    /**
     * Check if supplier assignment can be changed
     */
    public function isChangeable(): bool
    {
        return !$this->is_locked;
    }

    /**
     * Get payment route label
     */
    public function getPaymentRouteLabel(): string
    {
        return match($this->payment_route) {
            'direct_to_supplier' => 'Direct to Supplier',
            'split' => 'Split Payment',
            'direct_to_applicant' => 'Direct to Applicant',
            default => 'Not Set',
        };
    }

}
