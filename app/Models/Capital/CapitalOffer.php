<?php

namespace App\Models\Capital;

use App\Models\Auth\User;
use App\Models\Shared\Like;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CapitalOffer extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'startup_stage' => 'array',
        'sectors' => 'array',
        'regions' => 'array',
    ];

    public function liked(){
        return $this->hasMany(Like::class, 'listing_id')
            ->where('type', 'capital');
    }

    public function agreements()
    {
        return $this->hasMany(CapitalTermsAgreement::class, 'capital_id');
    }

    public function owner(){
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
