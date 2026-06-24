<?php

namespace App\Models\Grants;

use App\Models\Auth\User;
use App\Traits\HasS3Files;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MilestoneVerification extends Model
{
    use HasFactory;

    use HasS3Files;

    protected function privateFileFields(): array
    {
        return [
            'document',
        ];
    }


    protected $guarded = [];

    protected $casts = [
        'conflict_of_interest_confirmed' => 'boolean',
        'funds_usage_confirmed' => 'boolean',
        'decided_at' => 'datetime',
        'audit_started_at' => 'datetime',
        'audit_completed_at' => 'datetime',
    ];

//    public function getDocumentUrlAttribute()
//    {
//        return $this->s3Url($this->document);
//    }

    // ─────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────

    public function milestone()
    {
        return $this->belongsTo(GrantMilestone::class, 'milestone_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function auditor()
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }
}
