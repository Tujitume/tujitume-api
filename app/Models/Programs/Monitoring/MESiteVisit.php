<?php

namespace App\Models\Programs\Monitoring;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MESiteVisit extends Model
{
    use HasFactory;
    protected static function newFactory() { return \Database\Factories\MESiteVisitFactory::new(); }

    /**
     * A new visit always starts with its checkpoint's copy (KPIs, fields to collect, objective) and the business's
     * location, whichever code path creates it (the M&E tab, a program import...). Values given explicitly win.
     */
    protected static function booted(): void
    {
        static::creating(function (MESiteVisit $visit) {
            $checkpoint = $visit->checkpoint_id ? MECheckpoint::find($visit->checkpoint_id) : null;
            if (! $checkpoint) return;
            if (empty($visit->kpi_targets) && ! empty($checkpoint->kpis_to_track)) {
                $visit->kpi_targets = $checkpoint->kpis_to_track;
            }
            if (empty($visit->data_collection_fields)) {
                $fields = array_merge($checkpoint->submission_fields ?? [], $checkpoint->evidence_required ?? []);
                if ($fields) $visit->data_collection_fields = $fields;
            }
            if (blank($visit->objective) && filled($checkpoint->requirement)) {
                $visit->objective = $checkpoint->requirement;
            }
            if (blank($visit->location) || $visit->gps_lat === null || $visit->gps_lng === null) {
                $business = $checkpoint->application?->business;
                $visit->location = filled($visit->location) ? $visit->location : $business?->location;
                $visit->gps_lat ??= $business?->lat;
                $visit->gps_lng ??= $business?->lng;
            }
        });
    }

    protected $fillable = [
        'checkpoint_id', 'app_id', 'reviewer_id', 'inspector', 'start_date',
        'location', 'gps_lat', 'gps_lng', 'confirm_location', 'objective', 'kpi_targets', 'assign_type', 'email',
        'data_collection_fields', 'objectives_assessment', 'observed_actions',
        'evidence_found', 'risk_notes', 'recommendation_notes', 'visit_comments', 'status',
    ];

    protected $casts = [
        'kpi_targets'            => 'array',
        'data_collection_fields' => 'array',
        'start_date'             => 'date',
        'confirm_location'       => 'boolean',
    ];

    public function checkpoint() { return $this->belongsTo(MECheckpoint::class, 'checkpoint_id'); }

    /**
     * Only visits whose checkpoint the program owner has sent. Assigning an officer is set-up work; the officer,
     * the applicant and the lists they see only get it once the checkpoint is sent.
     */
    public function scopeSent($query)
    {
        return $query->whereHas('checkpoint', fn ($checkpoint) => $checkpoint->whereNotNull('sent_at'));
    }
    /** The application the visit is for (app_id), for searching by its business name. */
    public function application() { return $this->belongsTo(\App\Models\Programs\ProgramApplication::class, 'app_id'); }
    public function reviewer()   { return $this->belongsTo(User::class, 'reviewer_id'); }
    public function files()      { return $this->hasMany(MESiteVisitFile::class, 'site_visit_id'); }

    public function reviewerOrder()
    {
        return $this->hasOne(\App\Models\ReviewerOrder::class, 'site_visit_id');
    }
}
