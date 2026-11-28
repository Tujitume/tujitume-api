<?php

namespace App\Models\Capital;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StartupPitches extends Model
{
    use HasFactory;

    /** status: 0 = pending (awaiting the capital owner), 1 = accepted, 2 = rejected (kept so the applicant sees it). */
    public const STATUS_PENDING = 0;
    public const STATUS_ACCEPTED = 1;
    public const STATUS_REJECTED = 2;

    protected $guarded = [];

    /** The status as a word: pending | approved | rejected (what the entrepreneur lists show). */
    public static function statusName(mixed $status): string
    {
        return match ((int) $status) {
            self::STATUS_ACCEPTED => 'approved',
            self::STATUS_REJECTED => 'rejected',
            default => 'pending',
        };
    }

    /** Applications still in play (pending or accepted), i.e. not rejected. */
    public function scopeNotRejected($query)
    {
        return $query->where('status', '!=', self::STATUS_REJECTED);
    }

    protected $casts = [
        'social_impact_areas' => 'array',
        'fund_requested_at' => 'datetime',
    ];

    protected $appends = ['pitch_deck_file_url', 'pitch_video_url', 'business_plan_url', 'score_breakdown_parsed'];

    public function getPitchDeckFileUrlAttribute(): ?string
    {
        return \App\Service\Capital\CapitalFiles::url($this->pitch_deck_file);
    }

    public function getPitchVideoUrlAttribute(): ?string
    {
        return \App\Service\Capital\CapitalFiles::url($this->pitch_video);
    }

    public function getBusinessPlanUrlAttribute(): ?string
    {
        return \App\Service\Capital\CapitalFiles::url($this->business_plan);
    }

    /** score_breakdown as {sector, geo, stage, revenue, team, impact, ...} (stored as JSON; older rows as "a,b,c,d,e,f") */
    public function getScoreBreakdownParsedAttribute(): array
    {
        return \App\Service\Capital\CapitalMatchScore::parseBreakdown($this->score_breakdown);
    }

    public function agreements()
    {
        return $this->hasMany(CapitalTermsAgreement::class, 'pitch_id');
    }

    /** The newest terms agreement (the one in play) */
    public function latestAgreement()
    {
        return $this->hasOne(CapitalTermsAgreement::class, 'pitch_id')->latestOfMany();
    }
    public function capital_offer()
    {
        return $this->belongsTo(CapitalOffer::class,'capital_id','id');
    }

    public function capital_milestones()
    {
        return $this->hasMany(CapitalMilestone::class,'app_id','id');
    }

    public function sme()
    {
        return $this->belongsTo(User::class,'user_id','id');
    }
}
