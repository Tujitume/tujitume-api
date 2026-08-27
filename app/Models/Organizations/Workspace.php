<?php

namespace App\Models\Organizations;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\WorkspaceFactory::new();
    }
    
    // Allow mass assignment for all attributes
    protected $guarded = [];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }
}
