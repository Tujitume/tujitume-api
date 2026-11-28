<?php

namespace App\Models\Capital;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CapitalMilestone extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'fund_released' => 'boolean',
        'released_at' => 'datetime',
        'audit_requested_at' => 'datetime',
        'audit_completed_at' => 'datetime',
    ];

    protected $appends = ['document_url'];

    /**
     * The project manager audit's money and private notes (DECISION-9) are not sent with the milestone: the deal pages
     * get them through CapitalAudits::attach (the provider side sees the fee, the business owner the outcome).
     */
    protected $hidden = [
        'audit_fee', 'audit_fee_status', 'audit_fee_reference', 'audit_fee_paid_by', 'audit_evidence', 'audit_request_notes',
        'audit_cancel_reason', 'audit_requested_by',
    ];

    public function getDocumentUrlAttribute(): ?string
    {
        return \App\Service\Capital\CapitalFiles::url($this->document);
    }

    public function capital()
    {
        return $this->belongsTo(CapitalOffer::class, 'capital_id');
    }
    public function application()
    {
        return $this->belongsTo(StartupPitches::class, 'capital_id');
    }
}
