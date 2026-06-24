<?php

namespace App\Models\Shared;

use App\Models\Auth\User;
use App\Models\Milestones\FinalPMVote;
use App\Models\Milestones\MidMilestone;
use App\Models\Milestones\MidPMVote;
use App\Models\Milestones\MilestonePreReleaseRequest;
use App\Models\Milestones\Milestones;
use App\Models\Milestones\PrPMVote;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PMAudit extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'candidate_pm_ids' => 'array',
    ];

    public function midMilestone()
    {
        return $this->belongsTo(MidMilestone::class, 'mid_milestone_id');
    }

    public function preReleaseRequest()
    {
        return $this->belongsTo(MilestonePreReleaseRequest::class, 'pr_request_id');
    }

    public function milestone()
    {
        return $this->belongsTo(Milestones::class, 'milestone_id');
    }

    public function thisMilestone()
    {
        return $this->belongsTo(Milestones::class, 'milestone_id');
    }

    public function assignedPM()
    {
        return $this->belongsTo(User::class, 'assigned_pm_id');
    }

    public function getCandidatesAttribute()
    {
        return User::whereIn('id', $this->candidate_pm_ids ?? [])
            ->select('id', 'fname', 'lname', 'email', 'user_type_id', 'dob', 'gender', 'image', 'phone', 'website')
            ->get();
    }

    public function midVotes()
    {
        return $this->hasMany(MidPMVote::class, 'pm_audit_id');
    }

    public function prVotes()
    {
        return $this->hasMany(PrPMVote::class, 'pm_audit_id');
    }

    public function finalVotes()
    {
        return $this->hasMany(FinalPMVote::class, 'pm_audit_id');
    }
}
