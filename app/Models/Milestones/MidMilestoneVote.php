<?php

namespace App\Models\Milestones;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MidMilestoneVote extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function midMilestone()
    {
        return $this->belongsTo(MidMilestone::class, 'mid_milestone_id');
    }

    public function investor()
    {
        return $this->belongsTo(User::class, 'investor_id');
    }
}
