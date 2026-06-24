<?php

namespace App\Models\Grants\Rounds;

use App\Models\Auth\User;
use App\Models\Grants\GrantApplication;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoundRequiredDocument extends Model
{
    protected $fillable = [
        'application_id',
        'round_id',
        'document_type',
        'file_path',
        'original_filename',
        'file_size',
        'mime_type',
        'verification_status',
        'verification_notes',
        'verified_at',
        'verified_by',
        'required_documents',
        'uploaded_at'
    ];

    protected $casts = [
        'file_size' => 'integer',
        'verified_at' => 'datetime',
        'required_documents' => 'array',
    ];

    /**
     * Get the application that owns the document
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(GrantApplication::class, 'application_id');
    }

    /**
     * Get the round this document belongs to
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(GrantRound::class, 'round_id');
    }

    /**
     * Get the user who verified the document
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Scope to get pending documents
     */
    public function scopePending($query)
    {
        return $query->where('verification_status', 'pending');
    }

    /**
     * Scope to get verified documents
     */
    public function scopeVerified($query)
    {
        return $query->where('verification_status', 'verified');
    }

    /**
     * Scope to get rejected documents
     */
    public function scopeRejected($query)
    {
        return $query->where('verification_status', 'rejected');
    }

    /**
     * Check if document is verified
     */
    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    /**
     * Mark document as verified
     */
    public function markAsVerified($userId = null, $notes = null): void
    {
        $this->update([
            'verification_status' => 'verified',
            'verified_at' => now(),
            'verified_by' => $userId ?? auth()->id(),
            'verification_notes' => $notes,
        ]);
    }

    /**
     * Mark document as rejected
     */
    public function markAsRejected($notes = null): void
    {
        $this->update([
            'verification_status' => 'rejected',
            'verification_notes' => $notes,
        ]);
    }
}
