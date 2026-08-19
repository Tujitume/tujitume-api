<?php

namespace App\Models\Programs;

use App\Models\AMAP\AmapFlag;
use App\Models\AMAP\AmapTrigger;
use App\Models\Auth\User;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Shared\Like;
use App\Traits\HasS3Files;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    use HasFactory;

    use HasS3Files;

    protected function privateFileFields(): array
    {
        return [
            'program_brief_file',
        ];
    }

    protected $guarded = [];

    protected $casts = [
        'startup_stage_focus' => 'array',
        'program_focus' => 'array',
        'regions' => 'array',
        'required_documents' => 'array',
        'social_impact_areas' => 'array',
        'bonus_points' => 'array',
    ];

    public function liked(){
        return $this->hasMany(Like::class, 'listing_id')
            ->where('type', 'program');
    }

    public function owner(){
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function applications(){
        return $this->hasMany(ProgramApplication::class, 'program_id');
    }

    public function activeApplications(){
        return $this->hasMany(ProgramApplication::class, 'program_id')->where('status', 'approved');
    }

    public function wallet()
    {
        return $this->hasOne(ProgramWallet::class);
    }

    // AMAP relationships
    public function amapTriggers()
    {
        return $this->morphMany(AmapTrigger::class, 'triggerable');
    }

    public function amapFlags()
    {
        return $this->morphMany(AmapFlag::class, 'flaggable');
    }

    public function rounds()
    {
        return $this->hasMany(ProgramRound::class, 'program_id');
    }

    public function supplierDirectory()
    {
        return $this->hasMany(SupplierDirectory::class, 'user_id', 'user_id');
    }
}
