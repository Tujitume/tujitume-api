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
        'status',
        'invited_by_user_id',
        'invited_at',
        'accepted_at',
        'revoked_at',
        'invitation_token_hash',
        'invitation_expires_at',
    ];

    protected $casts = [
        'invited_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
        'invitation_expires_at' => 'datetime',
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

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
