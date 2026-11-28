<?php

namespace App\Models\Milestones;

use App\Models\Shared\Vote;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MilestoneRmep extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'eligible_voters' => 'array',
    ];
    public function votes()
    {
        return $this->hasMany(RmepVotes::class, 'rmep_id');
    }

    public function votingWindow()
    {
        // The vote window points at this record (votes.reference_id + its type); it was read the other way round, so it
        // was never found and every vote that checks it answered "Voting window is closed"
        return $this->hasOne(Vote::class, 'reference_id')->where('type', 'rmep')->latest('id');
    }

    public function milestone()
    {
        return $this->belongsTo(Milestones::class, 'milestone_id');
    }
}
