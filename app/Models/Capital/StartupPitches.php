<?php

namespace App\Models\Capital;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StartupPitches extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'social_impact_areas' => 'array'
    ];
    public function capital_offer()
    {
        return $this->belongsTo(CapitalOffer::class,'capital_id','id');
    }

    public function capital_milestones()
    {
        return $this->hasMany(CapitalMilestone::class,'app_id','id');
    }

    public function sme()
    {
        return $this->belongsTo(User::class,'user_id','id');
    }
}
