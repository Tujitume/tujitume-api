<?php

namespace App\Models\Milestones\NonCompliance;

use App\Models\Milestones\Milestones;
use App\Models\Shared\Vote;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MilestoneNonCompliance extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'ipm_started_at' => 'datetime',
    ];

    public function milestone()
    {
        return $this->belongsTo(Milestones::class, 'milestone_id', 'id');
    }

    public function votes()
    {
        return $this->hasMany(MilestoneNoncomplianceVotes::class, 'non_compliance_id', 'id');
    }

    public function documents()
    {
        return $this->hasMany(NcDocuments::class, 'nc_id');
    }

    public function activeVote()
    {
        return $this->hasOne(Vote::class, 'reference_id')
            ->where('type', 'non_compliance')
            ->where('status', 'open');
    }
}
