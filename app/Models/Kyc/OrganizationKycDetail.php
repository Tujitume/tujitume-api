<?php

namespace App\Models\Kyc;

use Illuminate\Database\Eloquent\Model;

class OrganizationKycDetail extends Model
{
    protected $guarded = [];

    protected $casts = ['authorization_confirmation' => 'boolean'];

    public function verification()
    {
        return $this->belongsTo(KycVerification::class, 'kyc_verification_id');
    }
}
