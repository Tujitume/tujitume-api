<?php

namespace App\Models\Milestones;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MilestonePreReleaseDocs extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function preReleaseRequest(){
        return $this->belongsTo(MilestonePreReleaseRequest::class, 'request_id', 'id');
    }
}
