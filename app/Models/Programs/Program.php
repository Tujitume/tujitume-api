<?php

namespace App\Models\Programs;

use App\Models\AMAP\AmapFlag;
use App\Models\AMAP\AmapTrigger;
use App\Models\Auth\User;
use App\Models\Auth\UserSetting;
use App\Models\Programs\Concerns\HasOpeningWindow;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Organizations\Organization;
use App\Models\Shared\Like;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Program extends Model
{
    use HasFactory, HasOpeningWindow;

    protected static function newFactory() { return \Database\Factories\ProgramFactory::new(); }

    /** Applications open at application_opens_at and close after application_closes_at (UTC); the dates follow them. */
    protected const OPENING_WINDOW = [
        'opens' => ['application_start_date', 'application_opens_at'],
        'closes' => ['application_deadline', 'application_closes_at'],
    ];

    /** The zone the owner set the times in: the program's calendar dates are this zone's dates. */
    public function scheduleTimezone(): string
    {
        return \App\Support\ProgramSchedule::timezone($this->attributes['timezone'] ?? null);
    }

    protected static function booted(): void
    {
        static::creating(function (Program $program): void {
            if (empty($program->public_id)) {
                $program->public_id = (string) Str::ulid();
            }
        });

        // A 7-day trial account (demo request) can build and publish programs to try the workflow, but they never
        // reach real applicants: a trial organization's programs always stay hidden from discovery and applying.
        static::saving(function (Program $program): void {
            // getAttribute: inside the model, $program->visible is Eloquent's own $visible property, not the column
            if ($program->getAttribute('visible') && $program->organization_id
                && Organization::whereKey($program->organization_id)->where('is_trial', true)->exists()) {
                $program->setAttribute('visible', false);
            }
        });
    }

    protected $guarded = [];

    // The organization's logo goes out with every program, so cards and pages can show it instead of the owner's photo.
    // The cover image goes out as a ready-to-use URL (the column keeps the key on the s3 disk).
    protected $appends = ['organization_logo', 'cover_image_url'];

    /** Where program cover images are kept on the s3 disk. */
    public const COVER_DIRECTORY = 'images/programs/covers';

    public function getCoverImageUrlAttribute(): ?string
    {
        $path = $this->attributes['cover_image'] ?? null;
        if (! is_string($path) || $path === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return \Illuminate\Support\Facades\Storage::disk('s3')->url($path);
    }

    /** Accounts' logos already looked up in this request (null when they have none). */
    private static array $organizationLogos = [];

    /** An organization's owner account, already looked up in this request. */
    private static array $organizationOwners = [];

    /**
     * The organization's logo: the one saved in the appearance settings of the account that made the program, or, when that
     * account has none (a team member made it), of the organization's owner. Only a hosted image is sent; an inline data:
     * image would bloat every list response.
     */
    public function getOrganizationLogoAttribute(): ?string
    {
        $accounts = array_filter([$this->user_id, $this->organizationOwnerId()]);

        foreach (array_unique($accounts) as $accountId) {
            if (! array_key_exists($accountId, self::$organizationLogos)) {
                $logo = UserSetting::where('user_id', $accountId)->value('logo');
                self::$organizationLogos[$accountId] = is_string($logo) && preg_match('#^https?://#i', $logo) ? $logo : null;
            }
            if (self::$organizationLogos[$accountId]) {
                return self::$organizationLogos[$accountId];
            }
        }

        return null;
    }

    /** Forget the logos remembered for this process (tests, and long-running workers after a logo change). */
    public static function forgetLogoCache(): void
    {
        self::$organizationLogos = [];
        self::$organizationOwners = [];
    }

    private function organizationOwnerId(): ?int
    {
        $organizationId = $this->organization_id ?? null;
        if (! $organizationId) {
            return null;
        }
        if (! array_key_exists($organizationId, self::$organizationOwners)) {
            self::$organizationOwners[$organizationId] = Organization::where('id', $organizationId)->value('owner_user_id');
        }

        return self::$organizationOwners[$organizationId];
    }

    protected $casts = [
        'visible' => 'boolean',
        'require_kyc_verification_to_apply' => 'boolean',
        'use_match_score' => 'boolean',
        'application_start_date' => 'date',
        'application_deadline' => 'date',
    ];

    /** applicationsClosedReason() while the first round is published but has not opened yet (the program is upcoming). */
    public const NOT_OPEN_YET = 'Applications have not opened yet.';

    /** Statuses in which applicants may see a program (a draft, closed or finished one is never shown to them). */
    public const APPLICANT_STATUSES = ['published', 'open'];

    /** Programs applicants may see: published or open, and visible. */
    public function scopeListedForApplicants($query)
    {
        return $query->whereIn('status', self::APPLICANT_STATUSES)->where('visible', 1);
    }

    public function isListedForApplicants(): bool
    {
        return in_array((string) $this->status, self::APPLICANT_STATUSES, true) && (bool) $this->getAttribute('visible'); // $this->visible is Eloquent's serialization list, not the column
    }

    public function liked(){
        return $this->hasMany(Like::class, 'listing_id')
            ->where('type', 'program');
    }

    /**
     * ISSUE-38: the programs of a person's organization, whoever on the team created them (programs.organization_id,
     * or a program created by any member when the column is empty). Someone outside an organization sees their own.
     */
    public function scopeOfOrganization($query, $user)
    {
        if (! $user) return $query->whereRaw('1 = 0');
        $orgId = $user->organization_id ?: \App\Models\Organizations\Organization::where('owner_user_id', $user->id)->value('id');
        if (! $orgId) return $query->where('programs.user_id', $user->id);

        $members = \App\Models\Auth\User::where('organization_id', $orgId)->pluck('id')
            ->push(\App\Models\Organizations\Organization::whereKey($orgId)->value('owner_user_id'))->filter()->unique()->values();

        return $query->where(fn ($q) => $q->where('programs.organization_id', $orgId)
            ->orWhere(fn ($n) => $n->whereNull('programs.organization_id')->whereIn('programs.user_id', $members)));
    }

    /** The organization a program belongs to (its own column, else its creator's organization). */
    public function organizationId(): ?int
    {
        if ($this->organization_id) return (int) $this->organization_id;
        $owner = $this->owner;

        return $owner?->organization_id ? (int) $owner->organization_id
            : \App\Models\Organizations\Organization::where('owner_user_id', $this->user_id)->value('id');
    }

    public function owner(){
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /** Bookmarks (saved programs) people keep in their watchlist. */
    public function watchlists()
    {
        return $this->hasMany(\App\Models\Shared\Watchlist::class, 'org_id')->where('org_type', 'program');
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
        // 'open' is what the scheduled status update writes once the opening moment has passed
        if (! in_array($this->status, self::APPLICANT_STATUSES, true)) return 'Program is not open for applications.';

        $round = $this->applicationRound();
        if (!$round) return 'No application round exists for this program.';
        if ($round->status !== 'published') {
            return 'Applications are not open because the first round has not been published.';
        }

        $rounds = $this->relationLoaded('rounds') ? $this->rounds : $this->rounds()->get();
        $movedOn = $rounds->contains(fn ($r) => $r->round_number > $round->round_number && $r->status !== 'draft');

        if (in_array($round->status, ['closed', 'in_review', 'finalized'], true) || $movedOn) {
            return 'Applications are closed: the first round is complete and applicants have moved on to the next round.';
        }

        // Exact moments: open from the opening minute, closed once the closing minute has passed
        $closesAt = $round->closesAt() ?? $this->closesAt();
        if ($closesAt && $closesAt->isPast()) return 'The application deadline has passed.';

        if (! $round->hasOpened()) return self::NOT_OPEN_YET;

        return null;
    }

    /** Adds accepting_applications / applications_closed_reason to each program for the public lists. */
    public static function annotateApplicationState($programs)
    {
        $collection = collect($programs instanceof \Illuminate\Database\Eloquent\Model ? [$programs] : $programs);
        $collection->each->loadMissing('rounds:id,program_id,round_number,status,open_date,close_date,opens_at,closes_at');
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

    public function industry()
    {
        return $this->belongsTo(\App\Models\ProgramIndustry::class, 'program_industry_id');
    }
}
