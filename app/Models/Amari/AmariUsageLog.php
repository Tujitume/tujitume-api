<?php

namespace App\Models\Amari;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use Illuminate\Database\Eloquent\Model;

/** One request through Amari's gateway (see the create_amari_usage_tables migration). Never the question or answer. */
class AmariUsageLog extends Model
{
    public const UPDATED_AT = null;

    /** Requests that reached the AI model: these count towards the daily limit */
    public const COUNTED = ['answered', 'stopped', 'error'];

    public const STATUSES = ['answered', 'stopped', 'limited', 'paused', 'blocked', 'error'];

    protected $guarded = [];

    protected $casts = ['tokens_estimated' => 'boolean', 'created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
