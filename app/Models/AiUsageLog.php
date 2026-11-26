<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    protected $guarded = [];
    protected $casts = ['pricing_snapshot' => 'array', 'metadata' => 'array'];

    public function user(): BelongsTo { return $this->belongsTo(\App\Models\Auth\User::class); }
    public function workspace(): BelongsTo { return $this->belongsTo(\App\Models\Organizations\Workspace::class); }
    public function program(): BelongsTo { return $this->belongsTo(\App\Models\Programs\Program::class); }
    public function round(): BelongsTo { return $this->belongsTo(\App\Models\Programs\Rounds\ProgramRound::class, 'round_id'); }
    public function application(): BelongsTo { return $this->belongsTo(\App\Models\Programs\ProgramApplication::class, 'application_id'); }
    public function evaluationRun(): BelongsTo { return $this->belongsTo(\App\Models\Programs\Ai\ApplicationAiReview::class, 'evaluation_run_id'); }
}
