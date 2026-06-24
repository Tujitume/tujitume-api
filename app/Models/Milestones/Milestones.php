<?php

namespace App\Models\Milestones;

use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Listing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Milestones extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function listing(){
        return $this->belongsTo(Listing::class, 'listing_id');
    }

    public function accepted_bids()
    {
        return $this->hasMany(AcceptedBids::class, 'ms_id', 'id');
    }

    public function pending_bids()
    {
        return $this->hasMany(BusinessBids::class, 'ms_id', 'id');
    }


    public function pre_release_requests()
    {
        return $this->hasMany(MilestonePreReleaseRequest::class, 'milestone_id', 'id');
    }

    public function rmeps()
    {
        return $this->hasMany(MilestoneRmep::class, 'milestone_id', 'id');
    }

    public function midMilestones()
    {
        return $this->hasMany(MidMilestone::class, 'milestone_id');
    }

    public function getUsesThresholdAttribute(): bool
    {
        return (bool) $this->listing?->threshold_met;
    }

    protected function bidModel(): string
    {
        return $this->uses_threshold
            ? AcceptedBids::class
            : BusinessBids::class;
    }
    public function active_investors()
    {
        return $this->hasManyThrough(
            User::class,          // Related model
            $this->bidModel(),          // Intermediate (pivot) model
            'ms_id',              // Foreign key on AcceptedBids pointing to this milestone
            'id',                 // Foreign key on Users table
            'id',                 // Local key on Milestone
            'investor_id'         // Local key on AcceptedBids pointing to User
        );
    }

    public function investors()
    {
        return $this->active_investors()
            ->when($this->listing, function ($q) {
                $q->where('users.id', '!=', $this->listing->user_id); // Exclude the business owner
            });
    }

    public function investor_weights()
    {
        return ($this->uses_threshold
            ? $this->accepted_bids()
            : $this->pending_bids()
            )
            ->selectRaw('investor_id, SUM(amount) as total')
            ->groupBy('investor_id');
    }


    public function pending_investors()
    {
        return $this->hasManyThrough(
            User::class,          // Related model
            BusinessBids::class,  // Intermediate (pivot) model
            'ms_id',              // Foreign key on AcceptedBids pointing to this milestone
            'id',                 // Foreign key on Users table
            'id',                 // Local key on Milestone
            'investor_id'         // Local key on AcceptedBids pointing to User
        );
    }

}
