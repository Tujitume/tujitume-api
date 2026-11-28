<?php

namespace App\Models\Milestones;

use App\Models\Shared\Vote;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MilestoneExecutionDocuments extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'voted_ids' => 'array',
    ];

    public function milestone(){
        return $this->belongsTo(Milestones::class, 'milestone_id', 'id');
    }

    public function documents(){
        return $this->hasMany(FinalApprovalDocuments::class, 'milestone_execution_id', 'id');
    }

    public function votes()
    {
        return $this->hasMany(FinalApprovalVote::class, 'final_approval_id', 'id');
    }

    public function votingWindow()
    {
        // The vote window points at this record (votes.reference_id + its type); it was read the other way round, so it
        // was never found and every vote that checks it answered "Voting window is closed"
        return $this->hasOne(Vote::class, 'reference_id')->where('type', 'final_approval')->latest('id');
    }
}
