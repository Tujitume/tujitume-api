<?php

namespace App\Models\Grants\Monitoring;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MESubmissionFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'file_type',
        'file_path',
        'original_filename',
        'file_size',
        'mime_type',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(MESubmission::class, 'submission_id');
    }
}
