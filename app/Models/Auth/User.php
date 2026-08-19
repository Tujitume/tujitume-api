<?php

namespace App\Models\Auth;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalProfile;
use App\Models\Communication\Notifications;
use App\Models\Finance\Transactions;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramProfile;
use App\Models\Misc\Event;
use App\Models\Organizations\Organization;
use App\Models\Organizations\workspace;
use App\Models\Services\Services;
use App\Models\Users\InvestorProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];
    // protected $fillable = [
    //     'name',
    //     'email',
    //     'password',
    // ];

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
        return $this->hasOne(UserBalance::class,'user_id', 'id');
    }
    
    public function investor_profile()
    {
        return $this->hasOne(InvestorProfile::class,'user_id', 'id');
    }
    
    public function program_profile()
    {
        return $this->hasOne(ProgramProfile::class,'user_id', 'id');
    }
    public function capital_profile()
    {
        return $this->hasOne(CapitalProfile::class,'user_id', 'id');
    }

    public function user_type()
    {
        return $this->belongsTo(UserType::class,'user_type_id', 'id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }

    public function workspaces()
    {
        return $this->hasManyThrough(workspace::class, Organization::class, 'id', 'organization_id', 'organization_id', 'id');
    }

    public function notifications()
    {
        return $this->hasMany(Notifications::class,'receiver_id', 'id');
    }

    public function transactions()
    {
        return $this->hasMany(Transactions::class,'user_id', 'id');
    }

    public function listings()
    {
        return $this->hasMany(Listing::class,'user_id', 'id');
    }

    public function services()
    {
        return $this->hasMany(Services::class,'user_id', 'id');
    }

    public function events()
    {
        return $this->hasMany(Event::class,'user_id', 'id');
    }

    // as sme
    public function myApplications(){
        return $this->hasMany(ProgramApplication::class,'user_id', 'id');
    }

    public function settings()
    {
        return $this->hasOne(UserSetting::class);
    }

    // Helper to get settings with defaults
    public function getSettings(): UserSetting
    {
        return $this->settings ?? new UserSetting([
            'theme'  => 'default',
            'mode'   => 'system',
            'accent_color' => '#14532d',
        ]);
    }

}
