<?php

namespace App\Models\AMAP;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmapTrigger extends Model
{
    use HasFactory;

    protected $fillable = [
        'triggerable_type',
        'triggerable_id',
        'trigger_type',
        'description',
        'reported_by',
        'detected_at',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────

    // Polymorphic — what triggered AMAP?
    // Can be: Program, ProgramApplication, ProgramMilestone, BusinessMilestone, User, etc.
    public function triggerable()
    {
        return $this->morphTo();
    }

    // Who reported the trigger (null = system-detected)
    public function reportedBy()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    // What actions were taken in response
    public function actions()
    {
        return $this->hasMany(AmapAction::class, 'trigger_id');
    }

    // Flags created from this trigger
    public function flags()
    {
        return $this->hasMany(AmapFlag::class, 'trigger_id');
    }

    // ─────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }

    public function scopeForType($query, $triggerType)
    {
        return $query->where('trigger_type', $triggerType);
    }
}
