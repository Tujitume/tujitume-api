<?php

namespace App\Models\Programs;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MilestonePreAgreement extends Model
{
    use HasFactory;

    protected $fillable = [
        'milestone_id',
        'verification_type',
        'status',
        'rejection_count',
        'submitted_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at'     => 'datetime',
        'rejection_count' => 'integer',
    ];

    // ─── Relationships ───────────────────────────────────────────────────

    public function milestone()
    {
        return $this->belongsTo(ProgramMilestone::class, 'milestone_id');
    }

    public function applicant()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }


    public function comments()
    {
        return $this->hasMany(MilestoneAgreementComment::class, 'agreement_id')->orderBy('created_at', 'asc');
    }

    public function latestComment()
    {
        return $this->hasOne(MilestoneAgreementComment::class, 'agreement_id')->latestOfMany();
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    public function isAgreed(): bool
    {
        return $this->status === 'agreed';
    }

    public function isFinalRejected(): bool
    {
        return $this->status === 'final_rejected';
    }

}
