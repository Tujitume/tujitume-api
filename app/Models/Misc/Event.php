<?php

namespace App\Models\Misc;

use App\Models\Auth\User;
use App\Support\UserDateFormatter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'tags' => 'array',
    ];

    /**
     * start_date / end_date are the clock times the organiser typed in the event's own `timezone`. starts_at / ends_at
     * are those exact moments (ISO 8601 with offset), so each person can see the event in their own time zone.
     */
    protected $appends = ['starts_at', 'ends_at'];

    public function getStartsAtAttribute(): ?string
    {
        return $this->momentOf($this->getRawOriginal('start_date') ?? $this->attributes['start_date'] ?? null);
    }

    public function getEndsAtAttribute(): ?string
    {
        return $this->momentOf($this->getRawOriginal('end_date') ?? $this->attributes['end_date'] ?? null);
    }

    private function momentOf($value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse((string) $value, UserDateFormatter::zone($this->attributes['timezone'] ?? null))->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class,'user_id', 'id');
    }
}
