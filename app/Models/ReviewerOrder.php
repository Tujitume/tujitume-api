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
        'order_type', 'round_id', 'site_visit_id',
        'fee_usd', 'fee_kes', 'currency',
        'work_status', 'delivery_note', 'modification_note', 'rejection_reason',
        'deadline', 'delivered_at', 'approved_at',
        'payment_status', 'leg1_reference', 'leg2_reference', 'paid_at',
    ];

    protected $casts = [
        'fee_usd'      => 'float',
        'fee_kes'      => 'float',
        'deadline'     => 'datetime',
        'delivered_at' => 'datetime',
        'approved_at'  => 'datetime',
        'paid_at'      => 'datetime',
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
}
