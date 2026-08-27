<?php

namespace App\Models\Programs\Monitoring;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MESubmission extends Model
{
    use HasFactory;
    protected static function newFactory() { return \Database\Factories\MESubmissionFactory::new(); }

    protected $fillable = [
        'checkpoint_id', 'app_id', 'submitted_by', 'written_report',
        'kpi_actual_values', 'beneficiary_list', 'custom_field_values',
        'status', 'reviewer_note', 'reviewed_by', 'reviewed_at', 'submitted_at',
    ];

    protected $casts = [
        'kpi_actual_values'  => 'array',
        'beneficiary_list'   => 'array',
        'custom_field_values'=> 'array',
        'reviewed_at'        => 'datetime',
        'submitted_at'       => 'datetime',
    ];

    public function checkpoint() { return $this->belongsTo(MECheckpoint::class, 'checkpoint_id'); }
    public function files()      { return $this->hasMany(MESubmissionFile::class, 'submission_id'); }
    public function reviewer()   { return $this->belongsTo(User::class, 'reviewed_by'); }
}
