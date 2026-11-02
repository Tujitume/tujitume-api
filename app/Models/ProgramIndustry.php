<?php

namespace App\Models;

use App\Models\Organizations\Organization;
use App\Models\Programs\ProgramMetadata;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramIndustry extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function organizations()
    {
        return $this->hasMany(Organization::class, 'program_industry_id');
    }

    public function metadata()
    {
        return $this->hasMany(ProgramMetadata::class, 'program_industry_id');
    }
}
