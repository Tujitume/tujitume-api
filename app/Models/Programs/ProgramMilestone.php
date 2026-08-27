<?php

namespace App\Models\Programs;

use App\Models\AMAP\AmapFlag;
use App\Models\AMAP\AmapTrigger;
use App\Models\Milestones\MilestoneCompletionSubmission;
use App\Traits\HasS3Files;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramMilestone extends Model
{
    use HasFactory;

    protected static function newFactory() { return \Database\Factories\ProgramMilestoneFactory::new(); }

    use HasS3Files;

    protected function privateFileFields(): array
    {
        return [
            'document',
        ];
    }

    protected $guarded = [];

    protected $casts = [
        'is_template' => 'boolean',
        'allowed_edits' => 'array',
    ];

    public function application()
    {
        return $this->belongsTo(ProgramApplication::class, 'app_id');
    }

    public function suppliers()
    {
        return $this->hasMany(MilestoneSupplier::class, 'milestone_id');
    }

    public function budgetItems()
    {
        return $this->hasMany(MilestoneBudgetItem::class, 'milestone_id');
    }

    public function verifications()
    {
        return $this->hasMany(MilestoneVerification::class, 'milestone_id');
    }

    public function latestVerification()
    {
        return $this->hasOne(MilestoneVerification::class, 'milestone_id')->latestOfMany();
    }

    public function disbursements()
    {
        return $this->hasMany(Disbursement::class, 'milestone_id');
    }

    public function dealRoomDocuments()
    {
        return $this->hasMany(DealRoomDocument::class, 'milestone_id');
    }

    public function completionSubmissions()
    {
        return $this->hasMany(MilestoneCompletionSubmission::class, 'milestone_id');
    }

    public function latestCompletionSubmission()
    {
        return $this->hasOne(MilestoneCompletionSubmission::class, 'milestone_id')->latestOfMany();
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

    // Funding Setup
    public function scopeTemplates($query)
    {
        return $query->where('is_template', true);
    }

    /**
     * Scope to get only active milestones
     */
    public function scopeActive($query)
    {
        return $query->where('is_template', false);
    }


    public function preAgreements()
    {
        return $this->hasMany(MilestonePreAgreement::class, 'milestone_id');
    }

    /**
     * Check if milestone is editable by applicant
     */
    public function isEditableByApplicant(): bool
    {
        return $this->is_template
            && $this->application?->planning_mode === 'hybrid'
            && !empty($this->allowed_edits);
    }

    /**
     * Check if specific field is editable by applicant
     */
    public function canApplicantEdit(string $field): bool
    {
        if (!$this->isEditableByApplicant()) {
            return false;
        }

        return in_array($field, $this->allowed_edits ?? []);
    }

    /**
     * Activate template (convert to active milestone)
     */
    public function activate(): void
    {
        $this->update(['is_template' => false]);
    }

}
