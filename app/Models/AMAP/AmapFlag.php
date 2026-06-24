<?php

namespace App\Models\AMAP;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmapFlag extends Model
{
    use HasFactory;

    protected $fillable = [
        'flaggable_type',
        'flaggable_id',
        'trigger_id',
        'flag_type',
        'status',
        'flagged_at',
        'expires_at',
        'lifted_at',
        'lifted_by',
    ];

    protected $casts = [
        'flagged_at' => 'datetime',
        'expires_at' => 'datetime',
        'lifted_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────

    // Polymorphic — what is flagged?
    // Can be: User, Grant, GrantMilestone, BusinessMilestone, etc.
    public function flaggable()
    {
        return $this->morphTo();
    }

    // Originating trigger (optional)
    public function trigger()
    {
        return $this->belongsTo(AmapTrigger::class, 'trigger_id');
    }

    // Who manually lifted the flag
    public function liftedBy()
    {
        return $this->belongsTo(User::class, 'lifted_by');
    }

    // ─────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopeLifted($query)
    {
        return $query->where('status', 'lifted');
    }

    public function scopeOfType($query, $flagType)
    {
        return $query->where('flag_type', $flagType);
    }

    // Check if flag is currently in effect
    public function scopeInEffect($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
