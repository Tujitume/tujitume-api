<?php

namespace App\Models\Programs;

use App\Models\AMAP\AmapFlag;
use App\Models\AMAP\AmapTrigger;
use App\Models\Auth\User;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Organizations\Organization;
use App\Models\Shared\Like;
use App\Traits\HasS3Files;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    use HasFactory;

    protected static function newFactory() { return \Database\Factories\ProgramFactory::new(); }

    use HasS3Files;

    protected function privateFileFields(): array
    {
        return [
            'program_brief_file',
        ];
    }

    protected $guarded = [];

    protected $casts = [
        'startup_stage_focus' => 'array',
        'program_focus' => 'array',
        'regions' => 'array',
        'required_documents' => 'array',
        'social_impact_areas' => 'array',
        'bonus_points' => 'array',
    ];

    public function liked(){
        return $this->hasMany(Like::class, 'listing_id')
            ->where('type', 'program');
    }

    public function owner(){
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function applications(){
        return $this->hasMany(ProgramApplication::class, 'program_id');
    }

    public function activeApplications(){
        return $this->hasMany(ProgramApplication::class, 'program_id')->where('status', 'approved');
    }

    public function wallet()
    {
        return $this->hasOne(ProgramWallet::class);
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

    /** The round the public applies to: always the first one. Later rounds only take applicants advanced from it. */
    public function applicationRound(): ?ProgramRound
    {
        $rounds = $this->relationLoaded('rounds') ? $this->rounds : $this->rounds()->get();

        return $rounds->sortBy('round_number')->first();
    }

    /**
     * Why new applications cannot be submitted right now, or null when they can.
     * Once the first round is over (closed / finalized) or applicants have moved on to a later
     * round, the program is no longer open to new applicants.
     */
    public function applicationsClosedReason(): ?string
    {
        if ($this->status !== 'published') return 'Program is not open for applications.';

        $round = $this->applicationRound();
        if (!$round) return 'No application round exists for this program.';

        $rounds = $this->relationLoaded('rounds') ? $this->rounds : $this->rounds()->get();
        $movedOn = $rounds->contains(fn ($r) => $r->round_number > $round->round_number && $r->status !== 'draft');

        if (in_array($round->status, ['closed', 'in_review', 'finalized'], true) || $movedOn) {
            return 'Applications are closed: the first round is complete and applicants have moved on to the next round.';
        }

        $closeDate = $round->close_date ?? ($this->application_deadline ? \Illuminate\Support\Carbon::parse($this->application_deadline) : null);
        if ($closeDate && $closeDate->copy()->endOfDay()->isPast()) return 'The application deadline has passed.';

        if ($round->open_date && $round->open_date->copy()->startOfDay()->isFuture()) return 'Applications have not opened yet.';

        return null;
    }

    /** Adds accepting_applications / applications_closed_reason to each program for the public lists. */
    public static function annotateApplicationState($programs)
    {
        $collection = collect($programs instanceof \Illuminate\Database\Eloquent\Model ? [$programs] : $programs);
        $collection->each->loadMissing('rounds:id,program_id,round_number,status,open_date,close_date');
        $collection->each(function ($program) {
            $reason = $program->applicationsClosedReason();
            $program->accepting_applications = $reason === null;
            $program->applications_closed_reason = $reason;
        });

        return $programs;
    }

    public function rounds()
    {
        return $this->hasMany(ProgramRound::class, 'program_id');
    }

    public function supplierDirectory()
    {
        return $this->hasMany(SupplierDirectory::class, 'user_id', 'user_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
