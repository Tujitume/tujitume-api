<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One version of the proposal terms for a demo request (never edited; a change is a new row). */
class DemoRequestQuote extends Model
{
    protected $guarded = [];

    protected $casts = [
        'implementation_fee' => 'float',
        'subscription_monthly' => 'float',
        'discount_percent' => 'float',
        'discount_months' => 'integer',
        'custom_development_fee' => 'float',
        'review_after_days' => 'integer',
    ];

    public function demoRequest(): BelongsTo
    {
        return $this->belongsTo(DemoRequest::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
