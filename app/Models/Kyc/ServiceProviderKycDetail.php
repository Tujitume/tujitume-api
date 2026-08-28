<?php

namespace App\Models\Kyc;

use Illuminate\Database\Eloquent\Model;

class ServiceProviderKycDetail extends Model
{
    protected $guarded = [];

    protected $casts = ['operates_through_business' => 'boolean', 'requires_professional_licence' => 'boolean'];

    public function verification()
    {
        return $this->belongsTo(KycVerification::class, 'kyc_verification_id');
    }
}
