<?php

namespace App\Models\Milestones;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MidPMVote extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function pm()
    {
        return $this->belongsTo(User::class, 'voted_pm_id');
    }

    public function pm_audit()
    {
        return $this->belongsTo(User::class, 'pm_audit_id');
    }


    public function investor()
    {
        return $this->belongsTo(User::class, 'investor_id');
    }
}
