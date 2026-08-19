<?php

namespace App\Models\Programs;

use App\Models\Auth\User;
use App\Traits\HasS3Files;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealRoomDocument extends Model
{
    use HasFactory;

    use HasS3Files;

    protected function privateFileFields(): array
    {
        return [
            'file_path',
        ];
    }

    protected $fillable = [
        'milestone_id',
        'uploaded_by',
        'document_type',
        'file_name',
        'file_path',
        'mime_type',
        'file_size_bytes',
        'description',
        'visible_to_program_owner',
        'visible_to_business_owner',
        'visible_to_supplier',
    ];

    protected $casts = [
        'visible_to_program_owner' => 'boolean',
        'visible_to_business_owner' => 'boolean',
        'visible_to_supplier' => 'boolean',
    ];

    // ─────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────

    public function milestone()
    {
        return $this->belongsTo(ProgramMilestone::class, 'milestone_id');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
