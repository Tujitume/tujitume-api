<?php

namespace App\Models;

use App\Models\Organizations\Organization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramIndustry extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function organizations()
    {
        return $this->hasMany(Organization::class, 'primary_industry_id');
    }
}
