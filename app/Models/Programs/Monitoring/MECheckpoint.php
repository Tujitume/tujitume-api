<?php

namespace App\Models\Programs\Monitoring;

use App\Models\Programs\ProgramApplication;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MECheckpoint extends Model
{
    use HasFactory;
    protected $fillable = [
        'app_id', 'program_id', 'checkpoint_name', 'type', 'due_date',
        'requirement', 'require_site_visit', 'kpis_to_track','meeting_required', 'meeting_id',
        'evidence_required', 'submission_fields', 'custom_submission_fields',
        'status', 'display_order',
    ];

    protected $casts = [
        'kpis_to_track'           => 'array',
        'evidence_required'       => 'array',
        'submission_fields'       => 'array',
        'custom_submission_fields'=> 'array',
        'require_site_visit'      => 'boolean',
        'due_date'                => 'date',
    ];

    public function application() { return $this->belongsTo(ProgramApplication::class, 'app_id'); }
    public function submission()  { return $this->hasOne(MESubmission::class, 'checkpoint_id'); }
    public function siteVisit()   { return $this->hasOne(MESiteVisit::class, 'checkpoint_id'); }
}
