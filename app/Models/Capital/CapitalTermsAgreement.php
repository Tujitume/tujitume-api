<?php

namespace App\Models\Capital;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CapitalTermsAgreement extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function capital()
    {
        return $this->belongsTo(CapitalOffer::class, 'capital_id');
    }

    public function pitch()
    {
        return $this->belongsTo(StartupPitches::class, 'pitch_id');
    }

    public function business_owner()
    {
        return $this->belongsTo(User::class, 'business_owner_id');
    }

    public function capital_owner()
    {
        return $this->belongsTo(User::class, 'capital_owner_id');
    }
}
