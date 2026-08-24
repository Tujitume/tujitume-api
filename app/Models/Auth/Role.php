<?php

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'access_types',
    ];

    protected $casts = [
        'access_types' => 'array',
    ];

    public function organizationUserRoles()
    {
        return $this->hasMany(OrganizationUserRole::class);
    }
}
