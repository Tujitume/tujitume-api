<?php

namespace App\Models\Users;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvestorProfile extends Model
{
    use HasFactory;
    
    protected $guarded = [];

    protected $casts = [
        'inv_range' => 'array',
        'turnover_range' => 'array',
        'interested_sectors' => 'array',
        'stage' => 'array',
        'social_impact_areas' => 'array',
        'regions_focus' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
