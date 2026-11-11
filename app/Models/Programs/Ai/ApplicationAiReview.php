<?php

namespace App\Models\Programs\Ai;

use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicationAiReview extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_EXTRACTING = 'extracting';
    public const STATUS_ANALYZING = 'analyzing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NEEDS_ATTENTION = 'needs_attention';
    public const STATUS_STALE = 'stale';

    protected $guarded = [];

    protected $casts = [
        'suggested_scores' => 'array', 'deterministic_checks' => 'array', 'missing_items' => 'array',
        'extraction_notes' => 'array', 'quality' => 'array', 'usage' => 'array',
        'suggested_total' => 'float', 'started_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function application(): BelongsTo { return $this->belongsTo(ProgramApplication::class, 'application_id'); }
    public function round(): BelongsTo { return $this->belongsTo(ProgramRound::class, 'round_id'); }
    public function program(): BelongsTo { return $this->belongsTo(Program::class, 'program_id'); }
    public function findings(): HasMany { return $this->hasMany(ApplicationAiFinding::class, 'review_id'); }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_READY, self::STATUS_NEEDS_ATTENTION, self::STATUS_FAILED], true);
    }
}
