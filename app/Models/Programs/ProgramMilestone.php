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

    // ISSUE-30: the earlier milestone number this one waits for (null when open), so the applicant's tabs can say so
    protected $appends = ['waiting_for_milestone'];

    protected $casts = [
        'is_template' => 'boolean',
        'allowed_edits' => 'array',
        'verification_questions' => 'array',
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

    /** True when every earlier active milestone has been completed and approved. */
    public function canReleaseInSequence(): bool
    {
        return !static::where('app_id', $this->app_id)
            ->where('is_template', false)
            ->where('sequence_order', '<', $this->sequence_order)
            ->where(function ($query) {
                $query->Where('fund_release_status', '!=', 'released');
            })
            ->exists();
    }

    /**
     * The one rule for "this milestone is finished" (used by the deal room progress, the next-milestone lock and the
     * application's completion): its funds are released and, when it has a final approval step, that is approved (as a
     * check, or as a completion report).
     */
    public function isFinished(): bool
    {
        // A status may be decorated for the API ({value, color}); read the plain value
        $plain = fn ($v) => is_object($v) ? (string) ($v->value ?? '') : (is_array($v) ? (string) ($v['value'] ?? '') : (string) $v);
        if ($plain($this->fund_release_status) !== 'released') return false;
        if (! $this->final_approval_required) return true;

        return $plain($this->status) === 'completion_approved'
            || MilestoneVerification::where('milestone_id', $this->id)->where('verification_type', 'final_approval')->where('decision', 'approved')->exists();
    }

    /**
     * ISSUE-30: the earlier milestone this one waits for, or null when it is open. A milestone takes its checks (MPRV,
     * mid-milestone, final approval) only once every earlier milestone is finished: its funds released and, when it has
     * a final approval step, that approved (as a check or as a completion report). This mirrors ISSUE-21's unlock.
     */
    public function waitingFor(): ?self
    {
        $earlier = static::where('app_id', $this->app_id)->where('is_template', false)
            ->where('sequence_order', '<', $this->sequence_order)->orderBy('sequence_order')->get();

        foreach ($earlier as $m) {
            if (! $m->isFinished()) return $m;
        }

        return null;
    }

    /**
     * A milestone with no final approval step is finished as soon as its money lands: open the next one then (the
     * final approval / completion approval opens it otherwise). Locked → approved unless it still needs its MPRV.
     */
    public function unlockNextWhenFinished(?int $userId = null): ?self
    {
        if ($this->final_approval_required || ! $this->fresh()?->isFinished()) return null;

        $next = static::where('app_id', $this->app_id)->where('is_template', false)
            ->where('sequence_order', '>', $this->sequence_order)->orderBy('sequence_order')->first();
        if (! $next || $next->fund_release_status !== 'locked' || ($next->mprv_required && ! $next->mprv_ready)) return null;

        $next->update([
            'fund_release_status' => 'approved',
            'status' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
            'verification_notes' => $next->verification_notes ?: 'Unlocked: Previous milestone completed',
        ]);

        return $next;
    }

    public function getWaitingForMilestoneAttribute(): ?int
    {
        if ($this->is_template || ! $this->app_id || ! $this->exists) return null;

        return $this->waitingFor()?->sequence_order;
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
