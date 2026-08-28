<?php

namespace App\Models\Kyc;

use Illuminate\Database\Eloquent\Model;

class KycDocument extends Model
{
    protected $guarded = [];

    protected $casts = ['uploaded_at' => 'datetime'];

    public function verification()
    {
        return $this->belongsTo(KycVerification::class, 'kyc_verification_id');
    }

    public function person()
    {
        return $this->belongsTo(KycPerson::class, 'kyc_person_id');
    }
}
