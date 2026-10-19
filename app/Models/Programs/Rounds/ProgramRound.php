<?php

namespace App\Models\Programs\Rounds;

use App\Models\Auth\User;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramRound extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\ProgramRoundFactory::new();
    }

    protected $guarded = [];

    protected $casts = [
        'scoring_criteria' => 'array',
        'knockout_questions' => 'array',
        'required_documents' => 'array',
        'confirmed_steps' => 'array',
        'open_date' => 'date',
        'close_date' => 'date',
        'review_period_end' => 'date',
        'announcement_date' => 'date',
    ];

    /**
     * Why nothing can be answered, uploaded or submitted for this round yet, or null once it is open.
     * A round that is still a draft, or whose opening day has not come, can only be read.
     */
    public function notOpenReason(): ?string
    {
        $notOpen = $this->status === 'draft'
            || ($this->open_date && $this->open_date->copy()->startOfDay()->isFuture());

        if (! $notOpen) {
            return null;
        }

        return 'This round has not opened yet' . ($this->open_date ? ' (it opens on ' . $this->open_date->format('j M Y') . ')' : '')
            . '. You can read what it asks for now, and answer and submit once it opens.';
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function reviewers()
    {
        return $this->belongsToMany(User::class, 'round_reviewers', 'round_id', 'user_id')
            ->select('users.id', 'users.first_name', 'users.last_name', 'users.email', 'users.image')
            ->withPivot(['reviewer_type', 'max_apps_assigned', 'minimum_apps_to_get_paid', 'expertise_tags', 'reviewer_fee', 'fee_type', 'fee_currency'])
            ->withTimestamps();
    }

    public function reviewerAssignments()
    {
        return $this->hasMany(RoundReviewer::class, 'round_id');
    }

    public function questions()
    {
        return $this->hasMany(RoundCustomQuestion::class, 'round_id')
            ->where('question_type', '!=', 'knockout');
    }

    public function knockoutQuestions()
    {
        return $this->hasMany(RoundCustomQuestion::class, 'round_id')
            ->where('question_type', 'knockout');
    }

    public function answers()
    {
        return $this->hasMany(ApplicationRoundResponse::class, 'round_id');
    }

    public function scores()
    {
        return $this->hasMany(ApplicationScore::class, 'round_id');
    }

    public function applications()
    {
        return $this->hasMany(ProgramApplication::class, 'current_round_id');
    }

    public function roundDocuments()
    {
        return $this->hasMany(RoundRequiredDocument::class, 'round_id');
    }

    /**
     * Get all documents uploaded for this round
     */
    public function uploadedDocuments()
    {
        return $this->hasMany(RoundRequiredDocument::class, 'round_id');
    }

    /**
     * Get pending documents for this round
     */
    public function pendingDocuments()
    {
        return $this->uploadedDocuments()->where('verification_status', 'pending');
    }

    /**
     * Get verified documents for this round
     */
    public function verifiedDocuments()
    {
        return $this->uploadedDocuments()->where('verification_status', 'verified');
    }

    public function reviewerOrders()
    {
        return $this->hasMany(\App\Models\ReviewerOrder::class, 'round_id');
    }

    public function reviewerApplicationAssignments()
    {
        return $this->hasMany(ReviewerApplicationAssignment::class, 'round_id');
    }
}
