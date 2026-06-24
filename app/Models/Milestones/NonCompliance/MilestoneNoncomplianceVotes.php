<?php

namespace App\Models\Milestones\NonCompliance;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MilestoneNoncomplianceVotes extends Model
{
    use HasFactory;
    protected $guarded = [];


    public function investor()
    {
        return $this->belongsTo(User::class, 'investor_id');
    }
}
