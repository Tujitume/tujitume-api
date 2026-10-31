<?php

namespace App\Models\Programs\Monitoring;

use App\Models\Programs\ProgramApplication;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MECheckpoint extends Model
{
    use HasFactory;
    protected static function newFactory() { return \Database\Factories\MECheckpointFactory::new(); }
    protected $fillable = [
        'app_id', 'program_id', 'checkpoint_name', 'type', 'due_date',
        'requirement', 'checkpoint_description', 'require_site_visit', 'kpis_to_track','meeting_required', 'meeting_id',
        'evidence_required', 'submission_fields', 'custom_submission_fields',
        'status', 'display_order', 'sent_at', 'respondent', 'should_notify_applicant', 'assigned_to',
    ];

    protected $casts = [
        'kpis_to_track'           => 'array',
        'evidence_required'       => 'array',
        'submission_fields'       => 'array',
        'custom_submission_fields'=> 'array',
        'require_site_visit'      => 'boolean',
        'due_date'                => 'date',
        'sent_at'                 => 'datetime',
    ];

    public function application() { return $this->belongsTo(ProgramApplication::class, 'app_id'); }
    public function assignedTo() { return $this->belongsTo(\App\Models\Auth\User::class, 'assigned_to'); }
    public function submission()  { return $this->hasOne(MESubmission::class, 'checkpoint_id'); }
    public function siteVisit()   { return $this->hasOne(MESiteVisit::class, 'checkpoint_id'); }
}
