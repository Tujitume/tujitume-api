<?php

namespace App\Models\Auth;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalProfile;
use App\Models\Communication\Notifications;
use App\Models\Finance\Transactions;
use App\Models\Kyc\KycVerification;
use App\Models\Misc\Event;
use App\Models\Organizations\Organization;
use App\Models\Organizations\Workspace;
use App\Models\Programs\ProgramApplication;
use App\Models\Services\Services;
use App\Models\Users\InvestorProfile;
use App\Models\Users\ServiceProviderProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected static function newFactory()
    {
        return \Database\Factories\UserFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name', 'last_name', 'display_name', 'email', 'phone', 'image',
        'gender', 'dob', 'password', 'token', 'email_verified_at', 'user_type_id',
        'completed_onboarding', 'country', 'city', 'website',
        'lipr_wallet_account', 'stripe_connect_id', 'stripe_customer_id',
        'organization_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $with = ['user_type'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'stage' => 'array',
        'inv_range' => 'array',
        'turnover_range' => 'array',
        'interested_cats' => 'array',
        'regions_focus' => 'array',
        'social_impact_areas' => 'array',
    ];

    public function balance()
    {
        return $this->hasOne(UserBalance::class, 'user_id', 'id');
    }

    public function investor_profile()
    {
        return $this->hasOne(InvestorProfile::class, 'user_id', 'id');
    }

    public function service_provider_profile()
    {
        return $this->hasOne(ServiceProviderProfile::class, 'user_id', 'id');
    }

    public function capital_profile()
    {
        return $this->hasOne(CapitalProfile::class, 'user_id', 'id');
    }

    public function user_type()
    {
        return $this->belongsTo(UserType::class, 'user_type_id', 'id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }

    public function organizationRole()
    {
        return $this->hasOne(OrganizationUserRole::class);
    }

    public function organizationRoles()
    {
        return $this->hasMany(OrganizationUserRole::class);
    }

    /** Return the organization account that owns shared organization resources. */
    public function organizationOwnerId(): int
    {
        $this->loadMissing('organization');

        return $this->organization?->owner_user_id ?? $this->id;
    }

    public function workspaces()
    {
        return $this->hasManyThrough(Workspace::class, Organization::class, 'id', 'organization_id', 'organization_id', 'id');
    }

    public function notifications()
    {
        return $this->hasMany(Notifications::class, 'receiver_id', 'id');
    }

    public function transactions()
    {
        return $this->hasMany(Transactions::class, 'user_id', 'id');
    }

    public function listings()
    {
        return $this->hasMany(Listing::class, 'user_id', 'id');
    }

    public function services()
    {
        return $this->hasMany(Services::class, 'user_id', 'id');
    }

    public function events()
    {
        return $this->hasMany(Event::class, 'user_id', 'id');
    }

    // as sme
    public function myApplications()
    {
        return $this->hasMany(ProgramApplication::class, 'user_id', 'id');
    }

    public function settings()
    {
        return $this->hasOne(UserSetting::class);
    }

    public function kycVerification()
    {
        return $this->hasOne(KycVerification::class);
    }

    /** The most recently created KYC verification is the user's current KYC state. */
    public function currentKycVerification(): HasOne
    {
        return $this->hasOne(KycVerification::class)->latestOfMany();
    }

    // Helper to get settings with defaults
    public function getSettings(): UserSetting
    {
        return $this->settings ?? new UserSetting([
            'theme' => 'default',
            'mode' => 'system',
            'accent_color' => '#14532d',
        ]);
    }
}
