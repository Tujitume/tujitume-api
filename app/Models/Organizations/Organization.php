<?php

namespace App\Models\Organizations;

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\ProgramIndustry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\OrganizationFactory::new();
    }

    protected $fillable = [
        'owner_user_id', 'name', 'display_name', 'legal_name', 'organization_type',
        'year_established', 'description', 'email', 'phone', 'website', 'country',
        'region', 'city', 'program_industry_id', 'focus_sectors',
        'operating_countries', 'target_regions', 'financial_year_start_month',
        'lipr_wallet', 'stripe_account_id', 'status',
    ];

    protected $casts = [
        'focus_sectors' => 'array',
        'operating_countries' => 'array',
        'target_regions' => 'array',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id', 'id');
    }

    public function workspaces()
    {
        return $this->hasMany(Workspace::class, 'organization_id', 'id');
    }

    public function userRoles()
    {
        return $this->hasMany(OrganizationUserRole::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'organization_user_roles')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function programIndustry()
    {
        return $this->belongsTo(ProgramIndustry::class, 'program_industry_id');
    }

    public function kycVerifications()
    {
        return $this->hasMany(KycVerification::class, 'organization_id');
    }
}
