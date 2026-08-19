<?php

namespace App\Models\Milestones;

use App\Models\Auth\User;
use App\Models\Programs\DealRoomDocument;
use App\Models\Programs\ProgramMilestone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MilestoneCompletionSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'milestone_id',
        'submitted_by',
        'attempt_number',
        'completion_report',
        'delivery_notes',
        'proof_files',
        'decision',
        'decided_by',
        'decided_at',
        'decision_notes',
    ];

    protected $casts = [
        'proof_files' => 'array',
        'decided_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────

    public function milestone()
    {
        return $this->belongsTo(ProgramMilestone::class, 'milestone_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    // Helper to get actual DealRoomDocument instances from proof_files array
    public function proofDocuments()
    {
        if (!$this->proof_files) {
            return collect();
        }

        return DealRoomDocument::whereIn('id', $this->proof_files)->get();
    }
}
