<?php

namespace App\Models;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Programs\Program;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Monitoring\MESiteVisit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReviewerOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'reviewer_id', 'program_id',
        'order_type', 'round_id', 'round_reviewer_id', 'site_visit_id',
        'fee_amount', 'fee_type', 'fee_kes', 'currency', 'minimum_apps_to_get_paid',
        'work_status', 'acceptance_status', 'accepted_at', 'delivery_note', 'modification_note', 'rejection_reason',
        'deadline', 'delivered_at', 'approved_at',
        'payment_status', 'leg1_reference', 'leg2_reference', 'paid_at', 'flagged_items',
        'proposed_fee', 'fee_status', 'fee_proposal_note', 'fee_proposed_at',
    ];

    protected $casts = [
        'fee_amount'   => 'float',
        'fee_kes'      => 'float',
        'deadline'     => 'datetime',
        'accepted_at'  => 'datetime',
        'delivered_at' => 'datetime',
        'approved_at'  => 'datetime',
        'paid_at'      => 'datetime',
        'minimum_apps_to_get_paid' => 'integer',
        'flagged_items' => 'array',
        'proposed_fee' => 'float',
        'fee_proposed_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function round()
    {
        return $this->belongsTo(ProgramRound::class, 'round_id');
    }

    public function roundReviewer()
    {
        return $this->belongsTo(\App\Models\Programs\Rounds\RoundReviewer::class, 'round_reviewer_id');
    }

    public function applicationAssignments()
    {
        return $this->hasMany(\App\Models\Programs\Rounds\ReviewerApplicationAssignment::class, 'reviewer_order_id');
    }

    /** Recalculate the payable total for a per-application round review order. */
    public function refreshRoundReviewFee(): void
    {
        if ($this->order_type !== 'round_review' || $this->isPaid()) {
            return;
        }

        $roundReviewer = $this->roundReviewer;
        if (!$roundReviewer) {
            return;
        }

        $rate = (float) ($roundReviewer->reviewer_fee ?? 0);
        $feeType = $roundReviewer->fee_type ?? 'per_application';
        $this->update([
            'fee_type' => $feeType,
            'fee_amount' => $feeType === 'per_application'
                ? $rate * $this->applicationAssignments()->count()
                : $rate,
        ]);
    }

    /** Calculate per-application compensation from completed scoring work, not merely assigned work. */
    public function refreshCompletedRoundReviewFee(): void
    {
        if ($this->order_type !== 'round_review' || $this->fee_type !== 'per_application' || $this->isPaid()) {
            return;
        }

        $roundReviewer = $this->roundReviewer;
        if (!$roundReviewer) {
            return;
        }

        $completed = $this->applicationAssignments()->where('status', 'completed')->count();
        $this->update(['fee_amount' => (float) ($roundReviewer->reviewer_fee ?? 0) * $completed]);
    }

    public function siteVisit()
    {
        return $this->belongsTo(MESiteVisit::class, 'site_visit_id');
    }

    // Helpers
    public function isApproved(): bool
    {
        return $this->work_status === 'approved';
    }

    public function isDelivered(): bool
    {
        return $this->work_status === 'delivered';
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'completed';
    }

    /** Legacy paid round orders had no stored threshold; require all assigned applications for those orders. */
    public function minimumPaidApplications(): int
    {
        $assignedCount = $this->relationLoaded('applicationAssignments')
            ? $this->applicationAssignments->count()
            : (isset($this->application_assignments_count)
                ? (int) $this->application_assignments_count
                : $this->applicationAssignments()->count());

        return (int) ($this->minimum_apps_to_get_paid ?? $assignedCount);
    }
}
