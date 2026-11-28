<?php

namespace App\Models;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request sent from the landing-page "Get in touch" form, and its review.
 *
 *   demo      admin approves → 7-day trial program-provider account (TrialAccountService), or rejects with a reason
 *   proposal / pilot / info   admin answers with an editable email (the proposal), or rejects with a reason
 *
 * A trial runs until trial_expires_at (UTC). The trial user can ask for full access, which emails them a one-time
 * link to /auth/create/program (ProgramRegistrationInvite). Once that new account pays a subscription the trial is
 * deleted and the request is "converted". A trial that ends without a full-access request is deleted ("expired").
 */
class DemoRequest extends Model
{
    use HasFactory;

    public const TYPE_DEMO = 'demo';
    public const TYPE_PROPOSAL = 'proposal';
    public const TYPE_PILOT = 'pilot';
    public const TYPE_INFO = 'info';

    public const REQUEST_TYPES = [self::TYPE_DEMO, self::TYPE_PROPOSAL, self::TYPE_PILOT, self::TYPE_INFO];

    /** Older form values, still accepted from cached pages. */
    public const LEGACY_REQUEST_TYPES = ['meeting' => self::TYPE_INFO, 'consultation' => self::TYPE_PROPOSAL, 'trial' => self::TYPE_PILOT];

    public const STATUS_PENDING = 'pending';
    public const STATUS_TRIAL_ACTIVE = 'trial_active';
    public const STATUS_PROPOSAL_SENT = 'proposal_sent';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FULL_ACCESS_REQUESTED = 'full_access_requested';
    public const STATUS_REGISTERED = 'registered';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CLOSED = 'closed';

    /** A request in one of these states blocks a new request of the same kind from the same email. */
    public const OPEN_STATUSES = [
        self::STATUS_PENDING, self::STATUS_TRIAL_ACTIVE, self::STATUS_FULL_ACCESS_REQUESTED, self::STATUS_REGISTERED,
    ];

    /** States in which a trial account exists. */
    public const TRIAL_STATUSES = [self::STATUS_TRIAL_ACTIVE, self::STATUS_FULL_ACCESS_REQUESTED, self::STATUS_REGISTERED];

    public const TRIAL_DAYS = 7;

    protected $guarded = [];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'proposal_sent_at' => 'datetime',
        'trial_started_at' => 'datetime',
        'trial_expires_at' => 'datetime',
        'trial_reminder_sent_at' => 'datetime',
        'trial_closed_at' => 'datetime',
        'full_access_requested_at' => 'datetime',
        'converted_at' => 'datetime',
        'workspace_ready_sent_at' => 'datetime',
    ];

    public static function normalizeRequestType(?string $type): string
    {
        $type = strtolower(trim((string) $type));

        return self::LEGACY_REQUEST_TYPES[$type] ?? (in_array($type, self::REQUEST_TYPES, true) ? $type : self::TYPE_DEMO);
    }

    public function requestTypeLabel(): string
    {
        return match ($this->request_type) {
            self::TYPE_PROPOSAL => 'Request a Proposal',
            self::TYPE_PILOT => 'Start a Pilot',
            self::TYPE_INFO => 'General Inquiry',
            default => 'Book a Demo',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_TRIAL_ACTIVE => 'Trial active',
            self::STATUS_PROPOSAL_SENT => 'Proposal sent',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_FULL_ACCESS_REQUESTED => 'Full access requested',
            self::STATUS_REGISTERED => 'Registered, awaiting payment',
            self::STATUS_CONVERTED => 'Full access (paid)',
            self::STATUS_EXPIRED => 'Trial ended, data deleted',
            self::STATUS_CLOSED => 'Closed',
            default => 'Pending review',
        };
    }

    /** The requester's zone (their device, sent with the form), else the platform default. */
    public function displayTimezone(): string
    {
        $zone = (string) ($this->timezone ?: config('app.display_timezone', 'Africa/Nairobi'));

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'Africa/Nairobi';
    }

    /** A moment on the requester's clock, e.g. "Thu 4 Dec 2026, 14:30 (Africa/Nairobi)". */
    public function onRequesterClock(?\DateTimeInterface $moment): ?string
    {
        if (! $moment) return null;
        $zone = $this->displayTimezone();

        return \Carbon\Carbon::instance($moment)->setTimezone($zone)->format('D j M Y, H:i').' ('.$zone.')';
    }

    public function hasTrial(): bool
    {
        return $this->trial_user_id !== null && $this->trial_closed_at === null
            && in_array($this->status, self::TRIAL_STATUSES, true);
    }

    public function programIndustry(): BelongsTo
    {
        return $this->belongsTo(ProgramIndustry::class);
    }

    public function trialUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trial_user_id');
    }

    public function trialOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'trial_organization_id');
    }

    /** Proposal terms, newest first (each save of the calculator is a version). */
    public function quotes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DemoRequestQuote::class)->orderByDesc('id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }
}
