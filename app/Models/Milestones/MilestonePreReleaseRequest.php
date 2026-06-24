<?php

namespace App\Models\Milestones;

use App\Models\Auth\User;
use App\Models\Shared\Vote;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MilestonePreReleaseRequest extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'proof_of_procurement' => 'array',
        'financial_reasonableness' => 'array',
        'risk_flags' => 'array',
        'media_proof' => 'array',
    ];

    public function investor(){
        return $this->belongsTo(User::class, 'investor_id', 'id');
    }

    public function milestone(){
        return $this->belongsTo(Milestones::class, 'milestone_id', 'id');
    }

    public function docs(){
        return $this->hasMany(MilestonePreReleaseDocs::class, 'request_id', 'id');
    }

    public function votingWindow()
    {
        return $this->belongsTo(Vote::class, 'reference_id');
    }

}
