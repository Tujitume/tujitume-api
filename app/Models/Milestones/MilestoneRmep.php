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
        return $this->belongsTo(Vote::class, 'reference_id');
    }

    public function milestone()
    {
        return $this->belongsTo(Milestones::class, 'milestone_id');
    }
}
