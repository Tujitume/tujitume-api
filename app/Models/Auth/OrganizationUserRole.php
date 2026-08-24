<?php

namespace App\Models\Auth;

use App\Models\Organizations\Organization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrganizationUserRole extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'role_id',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
