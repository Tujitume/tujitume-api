<?php

namespace App\Models\Grants\Monitoring;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MESiteVisitFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_visit_id',
        'file_path',
        'original_filename',
        'mime_type',
    ];

    public function siteVisit(): BelongsTo
    {
        return $this->belongsTo(MESiteVisit::class, 'site_visit_id');
    }
}
