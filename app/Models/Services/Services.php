<?php

namespace App\Models\Services;

use App\Models\Auth\User;
use App\Models\Shared\Like;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Services extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'social_impact_areas' => 'array',
        'business_sector_focus' => 'array',
    ];

    public function liked(){
        return $this->hasMany(Like::class, 'listing_id')
            ->where('type', 'service');
    }

    public function owner(){
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

}
