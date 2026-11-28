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

    /** The provider's private business details: only the provider's own service lists show them (makeVisible). */
    public const OWNER_ONLY = ['tax_pin', 'registration_number', 'physical_address', 'professional_licence'];

    protected $hidden = self::OWNER_ONLY;

    protected $casts = [
        'social_impact_areas' => 'array',
        'business_sector_focus' => 'array',
    ];

    public function liked(){
        return $this->hasMany(Like::class, 'listing_id')
            ->where('type', 'service');
    }

    /** The milestone templates of this service (smilestones.listing_id is the service id). */
    public function milestones()
    {
        return $this->hasMany(Smilestones::class, 'listing_id', 'id');
    }

    public function owner(){
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

}
