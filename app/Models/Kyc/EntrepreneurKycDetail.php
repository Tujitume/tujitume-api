<?php

namespace App\Models\Kyc;

use Illuminate\Database\Eloquent\Model;

class EntrepreneurKycDetail extends Model
{
    protected $guarded = [];

    protected $casts = ['id_expiry_date' => 'date', 'is_registered_business' => 'boolean'];

    public function verification()
    {
        return $this->belongsTo(KycVerification::class, 'kyc_verification_id');
    }
}
