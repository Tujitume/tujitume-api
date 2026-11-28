<?php

namespace App\Models\Organizations;

use Illuminate\Database\Eloquent\Model;

/** One create / update / delete made by an organization member (see App\Service\Organization\OrganizationAudit). */
class OrganizationAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];
}
