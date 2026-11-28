<?php

namespace App\Models\Capital;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CapitalProfile extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'regions' => 'array',
        'startup_stage' => 'array',
        'eng_prefer' => 'array',
        'inv_range' => 'array',
        'turnover_range' => 'array',
        'sectors' => 'array',
        'social_impact_areas' => 'array',
    ];

    protected $appends = ['investment_guidelines_url'];

    /** A link to open the investment guidelines file (signed s3 link) */
    public function getInvestmentGuidelinesUrlAttribute(): ?string
    {
        return \App\Service\Capital\CapitalFiles::url($this->investment_guidelines);
    }

    public function user(){
        return $this->belongsTo(User::class,'user_id', 'id');
    }

    public function role(){
        return $this->belongsTo(Role::class,'role_id', 'id');
    }
}
