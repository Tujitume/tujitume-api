<?php

namespace App\Models\Business;

use App\Models\Auth\User;
use App\Models\Milestones\Milestones;
use App\Models\Shared\Like;
use App\Traits\HasS3Files;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Listing extends Model
{
    /**
     * investors_fee is a fixed amount in KSh an investor pays once to unlock the business. Anything below this was
     * almost always a percentage typed by mistake (5 meaning 5%), so it is refused; old rows were set to the default.
     */
    public const MIN_INVESTORS_FEE = 100;

    /** What an old percentage-like fee became (the amount the form suggests) */
    public const DEFAULT_INVESTORS_FEE = 500;

    use HasFactory;


    protected $guarded = [];

    protected $casts = [
        'social_impact_areas' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($listing) {
            if (empty($listing->public_id)) {
                $listing->public_id = (string) Str::ulid();
            }
        });

        static::deleting(function ($listing) {
            // Delete milestones
            $listing->milestones()->each(function ($milestone) {
                $milestone->delete(); // triggers cascade on accepted_bids if foreign key set
            });

            // Delete accepted bids directly linked to listing (if any)
            $listing->accepted_bids()->delete();
        });
    }

    public function liked(){
        return $this->hasMany(Like::class, 'listing_id')
            ->where('type', 'listing');
    }

    public function bids(){
        return $this->hasMany(BusinessBids::class, 'business_id');
    }

    public function accepted_bids(){
        return $this->hasMany(AcceptedBids::class, 'business_id');
    }

    /** "Investors" is people, not bids: an investor in two milestones (or with a cash and an asset bid) counts once.
     *  Money bids count from acceptance, assets once verified (as amount_collected does). */
    public function refreshInvestorCount(): void
    {
        $this->update(['invest_count' => $this->accepted_bids()
            ->where(fn ($q) => $q->where('type', 'Monetary')->orWhereIn('status', ['verified', 'equipment_released']))
            ->distinct()->count('investor_id')]);
    }

    public function conversations(){
        return $this->hasMany(Conversation::class, 'listing_id');
    }

    public function milestones(){
        return $this->hasMany(Milestones::class, 'listing_id');
    }

    public function active_milestone()
    {
        return $this->milestones()->where('active', true)->first();
    }

    public function owner(){
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function investors()
    {
        return $this->belongsToMany(
            User::class,
            'accepted_bids',
            'business_id',
            'investor_id'
        )->withPivot('amount','representation','date','ms_id');
//        return User::whereIn(
//            'id',
//            $this->accepted_bids()->pluck('investor_id')
//        )->get();
    }

    public function pending_investors()
    {
        return $this->belongsToMany(
            User::class,
            'business_bids',
            'business_id',
            'investor_id'
        )->withPivot('amount','representation','date','ms_id');
    }

    public function activeSanction()
    {
        return $this->hasOne(BusinessSanction::class, 'business_id')
            ->where('active', true);
    }
}
