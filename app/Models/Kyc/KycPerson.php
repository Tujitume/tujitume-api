<?php

namespace App\Models\Kyc;

use Illuminate\Database\Eloquent\Model;

class KycPerson extends Model
{
    protected $guarded = [];

    protected $casts = ['is_beneficial_owner' => 'boolean', 'requires_identity_verification' => 'boolean', 'ownership_percentage' => 'decimal:2'];

    public function verification()
    {
        return $this->belongsTo(KycVerification::class, 'kyc_verification_id');
    }

    public function documents()
    {
        return $this->hasMany(KycDocument::class);
    }
}
