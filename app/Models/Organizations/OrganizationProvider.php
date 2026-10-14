<?php

namespace App\Models\Organizations;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** A Tujitume service provider an organization has added as a third-party reviewer/auditor. */
class OrganizationProvider extends Model
{
    protected $guarded = [];

    public function provider()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /** False until the migration has run, so callers can skip this feature instead of failing. */
    public static function available(): bool
    {
        return Schema::hasTable('organization_providers');
    }

    /** Is this provider in the organization's list? */
    public static function has(int $organizationId, int $userId): bool
    {
        return static::available()
            && static::where('organization_id', $organizationId)->where('user_id', $userId)->exists();
    }
}
