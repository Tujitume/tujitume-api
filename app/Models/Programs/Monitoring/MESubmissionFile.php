<?php

namespace App\Models\Programs\Monitoring;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MESubmissionFile extends Model
{
    use HasFactory;

    /** File categories a submission accepts. 'photo_video' is the old combined category, kept for older clients and saved rows. */
    public const FILE_TYPES = ['document', 'photo', 'video', 'photo_video', 'beneficiary_list', 'other'];

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
