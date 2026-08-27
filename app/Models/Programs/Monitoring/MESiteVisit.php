<?php

namespace App\Models\Programs\Monitoring;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MESiteVisit extends Model
{
    use HasFactory;
    protected static function newFactory() { return \Database\Factories\MESiteVisitFactory::new(); }

    protected $fillable = [
        'checkpoint_id', 'app_id', 'reviewer_id', 'inspector', 'start_date',
        'location', 'gps_lat', 'gps_lng', 'objective', 'kpi_targets', 'assign_type', 'email',
        'data_collection_fields', 'objectives_assessment', 'observed_actions',
        'evidence_found', 'risk_notes', 'recommendation_notes', 'visit_comments', 'status',
    ];

    protected $casts = [
        'kpi_targets'            => 'array',
        'data_collection_fields' => 'array',
        'start_date'             => 'date',
    ];

    public function checkpoint() { return $this->belongsTo(MECheckpoint::class, 'checkpoint_id'); }
    public function reviewer()   { return $this->belongsTo(User::class, 'reviewer_id'); }
    public function files()      { return $this->hasMany(MESiteVisitFile::class, 'site_visit_id'); }
}
