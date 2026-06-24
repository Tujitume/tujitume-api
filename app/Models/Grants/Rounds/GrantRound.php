<?php

namespace App\Models\Grants\Rounds;

use App\Models\Auth\User;
use App\Models\Grants\Grant;
use App\Models\Grants\GrantApplication;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GrantRound extends Model
{
    use HasFactory;
    protected  $guarded = [];

    protected $casts = [
        'scoring_criteria' => 'array',
        'knockout_questions' => 'array',
        'required_documents' => 'array',
        'open_date' => 'date',
        'close_date' => 'date',
        'review_period_end' => 'date',
        'announcement_date' => 'date',
    ];

    public function grant()
    {
        return $this->belongsTo(Grant::class);
    }

    public function reviewers()
    {
        return $this->belongsToMany(User::class, 'round_reviewers', 'round_id', 'user_id')
            ->select('users.id', 'users.fname', 'users.lname', 'users.email', 'users.image')
            ->withPivot(['reviewer_type', 'max_apps_assigned', 'expertise_tags'])
            ->withTimestamps();
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
        return $this->hasMany(GrantApplication::class, 'current_round_id');
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

}
