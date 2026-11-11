<?php

namespace App\Models\Programs\Ai;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationAiFinding extends Model
{
    public const DIMENSIONS = ['business_viability', 'program_alignment', 'market_opportunity', 'operational_readiness', 'traction'];
    public const POLARITIES = ['strength', 'risk', 'gap', 'inconsistency', 'info'];

    protected $guarded = [];

    protected $casts = ['source_ref' => 'array', 'quote_verified' => 'boolean', 'reviewed_at' => 'datetime'];

    public function review(): BelongsTo { return $this->belongsTo(ApplicationAiReview::class, 'review_id'); }
}
