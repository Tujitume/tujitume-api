<?php

namespace App\Models;

use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ProgramRound;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationRoundHistory extends Model
{
    use HasFactory;

    protected $table = 'application_round_history';

    protected $fillable = [
        'application_id',
        'round_id',
        'round_number',
        'entered_at',
        'submitted_at',
        'exited_at',
        'average_score',
        'rank_in_round',
        'total_applicants_in_round',
        'outcome',
        'outcome_notes',
        'reviewer_feedback_summary',
    ];

    protected $casts = [
        'entered_at' => 'datetime',
        'submitted_at' => 'datetime',
        'exited_at' => 'datetime',
        'average_score' => 'decimal:2',
        'reviewer_feedback_summary' => 'array',
    ];

    /**
     * Get the application
     */
    public function application()
    {
        return $this->belongsTo(ProgramApplication::class, 'application_id');
    }

    public function roundAnswers()
    {
        return $this->hasMany(ApplicationRoundResponse::class, 'application_id')
            ->whereColumn('round_id', 'application_round_history.round_id');
    }

    /**
     * Get the round
     */
    public function round()
    {
        return $this->belongsTo(ProgramRound::class, 'round_id');
    }

    /**
     * Scope for completed rounds (not in progress)
     */
    public function scopeCompleted($query)
    {
        return $query->whereIn('outcome', ['advanced', 'not_selected', 'withdrawn', 'awarded']);
    }

    /**
     * Scope for rounds that advanced
     */
    public function scopeAdvanced($query)
    {
        return $query->where('outcome', 'advanced');
    }

    /**
     * Scope for in-progress rounds
     */
    public function scopeInProgress($query)
    {
        return $query->where('outcome', 'in_progress');
    }

    /**
     * Check if applicant advanced from this round
     */
    public function didAdvance(): bool
    {
        return $this->outcome === 'advanced';
    }

    /**
     * Check if this is the current round
     */
    public function isCurrent(): bool
    {
        return $this->outcome === 'in_progress';
    }

    /**
     * Get duration in round (in days)
     */
    public function getDurationInDays(): ?int
    {
        if (!$this->exited_at) {
            return $this->entered_at->diffInDays(now());
        }

        return $this->entered_at->diffInDays($this->exited_at);
    }

    /**
     * Get rank display (e.g., "15/150")
     */
    public function getRankDisplayAttribute(): ?string
    {
        if (!$this->rank_in_round || !$this->total_applicants_in_round) {
            return null;
        }

        return "{$this->rank_in_round}/{$this->total_applicants_in_round}";
    }

    /**
     * Get outcome label
     */
    public function getOutcomeLabelAttribute(): string
    {
        return match($this->outcome) {
            'in_progress' => 'In Progress',
            'advanced' => 'Advanced',
            'not_selected' => 'Not Selected',
            'withdrawn' => 'Withdrawn',
            'awarded' => 'Awarded Program',
            default => 'Unknown',
        };
    }
}
