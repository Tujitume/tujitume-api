<?php

namespace App\Models\Organizations;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    use HasFactory;
    
    protected $guarded = [];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }
}
