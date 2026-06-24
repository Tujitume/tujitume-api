<?php

namespace App\Models\AMAP;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmapAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'trigger_id',
        'action_type',
        'details',
        'executed_at',
        'reversed_at',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────

    public function trigger()
    {
        return $this->belongsTo(AmapTrigger::class, 'trigger_id');
    }

    // ─────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────

    public function scopeNotReversed($query)
    {
        return $query->whereNull('reversed_at');
    }

    public function scopeReversed($query)
    {
        return $query->whereNotNull('reversed_at');
    }
}
