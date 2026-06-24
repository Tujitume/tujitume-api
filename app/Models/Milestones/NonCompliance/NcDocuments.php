<?php

namespace App\Models\Milestones\NonCompliance;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NcDocuments extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function nc()
    {
        return $this->belongsTo(MilestoneNonCompliance::class, 'nc_id');
    }
}
