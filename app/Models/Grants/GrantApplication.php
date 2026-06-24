<?php

namespace App\Models\Grants;

use App\Models\AMAP\AmapFlag;
use App\Models\AMAP\AmapTrigger;
use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Grants\Rounds\ApplicationRoundResponse;
use App\Models\Grants\Rounds\ApplicationScore;
use App\Models\Grants\Rounds\GrantRound;
use App\Models\Grants\Rounds\RoundRequiredDocument;
use App\Traits\HasS3Files;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GrantApplication extends Model
{
    use HasFactory;

    use HasS3Files;

    protected function privateFileFields(): array
    {
        return [
            'business_plan_file',
            'pitch_deck_file',
        ];
    }

    protected $guarded = [];

    protected $casts = [
	'social_impact_areas' => 'array',
	'score_breakdown' => 'array',
    ];

    protected $hidden = [
        'reviewer_notes',
        'revision_requested_by',
        'revision_notes',
        'revision_checklist',
        'revision_requested_at',
        //'rejection_reason',
        'score_breakdown',
    ];

    public function grant()
    {
        return $this->belongsTo(Grant::class, 'grant_id', 'id');
    }

    public function business()
    {
        return $this->belongsTo(Listing::class,'business_id','id');
    }

    public function sme()
    {
        return $this->belongsTo(User::class,'user_id','id');
    }

    public function user()
    {
        return $this->belongsTo(User::class,'user_id','id');
    }

    public function grant_milestones()
    {
        return $this->hasMany(GrantMilestone::class,'app_id','id')
            ->orderBy('sequence_order');
    }

    public function wallet()
    {
        return $this->hasOne(GrantWallet::class, 'application_id');
    }

    // AMAP relationships
    public function amapTriggers()
    {
        return $this->morphMany(AmapTrigger::class, 'triggerable');
    }

    public function amapFlags()
    {
        return $this->morphMany(AmapFlag::class, 'flaggable');
    }

    public function currentRound()
    {
        return $this->belongsTo(GrantRound::class, 'current_round_id');
    }

    public function scores()
    {
        return $this->hasMany(ApplicationScore::class, 'application_id');
    }

    public function roundAnswers()
    {
        return $this->hasMany(ApplicationRoundResponse::class, 'application_id');
    }

    /**
     * Get all required documents uploaded for this application
     */
    public function roundDocuments()
    {
        return $this->hasMany(RoundRequiredDocument::class, 'application_id');
    }

    /**
     * Get documents for a specific round
     */
    public function documentsForRound($roundId)
    {
        //return $this->roundDocuments()->where('round_id', $roundId)->get();
        return $this->roundDocuments()->where('round_id', $roundId);
    }

//    public function currentRoundDocuments()
//    {
//        return $this->hasMany(RoundRequiredDocument::class, 'application_id')
//            ->whereColumn('round_id', 'current_round_id');
//    }

    /**
     * Check if all required documents are uploaded for current round
     */
    public function hasAllRequiredDocuments()
    {
        if (!$this->current_round_id) {
            return true;
        }

        $round = $this->currentRound;
        $requiredDocs = $round->required_documents ?? [];

        if (empty($requiredDocs)) {
            return true;
        }

        $uploadedDocs = $this->roundDocuments()
            ->where('round_id', $this->current_round_id)
            ->pluck('document_type')
            ->toArray();

        return count(array_diff($requiredDocs, $uploadedDocs)) === 0;
    }

    /**
     * Check if all required documents are verified for current round
     */
    public function hasAllDocumentsVerified()
    {
        if (!$this->current_round_id) {
            return true;
        }

        $round = $this->currentRound;
        $requiredDocs = $round->required_documents ?? [];

        if (empty($requiredDocs)) {
            return true;
        }

        $verifiedCount = $this->roundDocuments()
            ->where('round_id', $this->current_round_id)
            ->where('verification_status', 'verified')
            ->count();

        return $verifiedCount === count($requiredDocs);
    }

    // Funding Setup

    public function templateMilestones()
    {
        return $this->hasMany(GrantMilestone::class, 'app_id')
            ->where('is_template', true)
            ->orderBy('sequence_order');
    }

    /**
     * Get active (non-template) milestones
     */
    public function activeMilestones()
    {
        return $this->hasMany(GrantMilestone::class, 'app_id')
            ->where('is_template', false)
            ->orderBy('sequence_order');
    }

    /**
     * Check if funding setup is complete
     */
    public function isFundingSetupComplete(): bool
    {
        return $this->funding_setup_status === 'completed';
    }

    /**
     * Check if all template milestones sum to approved amount
     */
    public function hasValidMilestoneAllocation(): bool
    {
        $totalAllocated = $this->templateMilestones()->sum('amount');
        $approvedAmount = $this->total_amount_requested;

        return abs($totalAllocated - $approvedAmount) < 0.01; // Account for floating point
    }

}
