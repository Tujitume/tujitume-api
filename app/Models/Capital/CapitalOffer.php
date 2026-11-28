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

    protected $appends = ['offer_brief_file_url'];

    public function getOfferBriefFileUrlAttribute(): ?string
    {
        return \App\Service\Capital\CapitalFiles::url($this->offer_brief_file);
    }

    /**
     * Offers people other than the owner may see: visible, and open today (start_date <= today <= end_date when set).
     * Dates are stored as text (Y-m-d or a longer date-time), so the first 10 characters are compared.
     */
    public function scopeOpenToPublic($query)
    {
        $today = now()->toDateString();

        return $query->where('visible', 1)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '')->orWhereRaw('substr(start_date, 1, 10) <= ?', [$today]))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '')->orWhereRaw('substr(end_date, 1, 10) >= ?', [$today]));
    }

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
