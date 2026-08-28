<?php

namespace App\Models\Kyc;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KycVerification extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function entrepreneurDetails()
    {
        return $this->hasOne(EntrepreneurKycDetail::class);
    }

    public function serviceProviderDetails()
    {
        return $this->hasOne(ServiceProviderKycDetail::class);
    }

    public function organizationDetails()
    {
        return $this->hasOne(OrganizationKycDetail::class);
    }

    public function people()
    {
        return $this->hasMany(KycPerson::class);
    }

    public function documents()
    {
        return $this->hasMany(KycDocument::class);
    }
}
