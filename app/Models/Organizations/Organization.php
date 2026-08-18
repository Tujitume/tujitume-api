<?php

namespace App\Models\Organizations;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory;
    
    protected $guarded = [];
    
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
        return $this->hasMany(workspace::class, 'organization_id', 'id');
    }
}
