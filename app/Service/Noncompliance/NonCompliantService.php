<?php
namespace App\Service\Noncompliance;
use App\Models\Business\BusinessSanction;
use App\Models\Milestones\Milestones;
use App\Models\Milestones\NonCompliance\MilestoneNonCompliance;
use App\Models\Shared\Vote;
use App\Service\Misc\ErrorLogService;
use App\Service\Misc\GlobalVotingService;
use App\Service\Notification\EmailLink;
use App\Service\Notification\EmailService;
use App\Service\Notification\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A business milestone that passes its deadline below 60% progress (AMAP):
 *   1. it is flagged non_compliant (publicly shown on the project);
 *   2. response_window: the owner has 72 hours to upload completion proof or a Revised Milestone Execution Plan (RMEP);
 *   3. ipm (Investor Protection Mode): when the owner answers, or the 72 hours pass without an answer, the investors take
 *      control and vote for 7 days (weighted by what they put in): continue (7 more days), freeze or dispute;
 *   4. pid (Platform-Initiated Dispute): a freeze or dispute result, or no vote at all in 7 days, locks the milestone and
 *      Tujitume reviews it;
 *   5. sanctioned: a dispute still open after 14 days sanctions the business.
 * A "continue" result resolves the case. Every move is claimed atomically (whereKey + the stage it leaves), so the hourly
 * command, a vote and the owner's answer never move one case twice or send its notices twice.
 */
class NonCompliantService
{
    public const RESPONSE_HOURS = 72;
    public const VOTE_DAYS = 7;
    public const PID_DAYS = 14;
    public const OPEN_STAGES = ['response_window', 'ipm', 'pid'];
    /** Stages that decide the milestone's status themselves (a sanctioned milestone stays locked) */
    public const HELD_STAGES = ['response_window', 'ipm', 'pid', 'sanctioned'];

    protected $emailService;
    protected $notification;
    protected $votingService;
    protected $ncVotingService;

    public function __construct()
    {
        $this->emailService = new EmailService();
        $this->notification = new NotificationService();
        $this->votingService = new GlobalVotingService();
        $this->ncVotingService = new VotingService();
    }

    /** Stage 1 and 2: flag the milestone and open the owner's 72-hour window. */
    public function trigger($milestone): ?MilestoneNonCompliance
    {
        if (self::heldCase($milestone->id)) return null; // already escalating (or sanctioned): its stage decides the status

        $milestone->status = 'non_compliant';
        $milestone->active = false;
        $milestone->save();

        return $this->startResponseWindow($milestone);
    }

    /**
     * Opens the case (one per milestone: a case that was resolved earlier opens again, as a failed extension). Returns null,
     * and tells nobody, when a case is already open or the business was sanctioned for it.
     */
    public function startResponseWindow(Milestones $milestone): ?MilestoneNonCompliance
    {
        $listing = $milestone->listing;
        if (! $listing) return null;

        try {
            $nc = DB::transaction(function () use ($milestone, $listing) {
                $existing = MilestoneNonCompliance::where('milestone_id', $milestone->id)->lockForUpdate()->first();
                $fresh = [
                    'stage' => 'response_window',
                    'response_deadline' => now()->addHours(self::RESPONSE_HOURS),
                    'owner_response_type' => 'none', 'owner_response_note' => null,
                    'investor_decision' => null, 'resolution_result' => null,
                    'ipm_started_at' => null, 'pid_started_at' => null, 'resolved_at' => null,
                ];

                if (! $existing) {
                    return MilestoneNonCompliance::create($fresh + [
                        'milestone_id' => $milestone->id,
                        'business_id' => $listing->id,
                        'owner_id' => $listing->user_id,
                        'trigger_reason' => 'missed_deadline',
                    ]);
                }
                if ($existing->stage !== 'resolved') return null; // open, or sanctioned

                // Missed the extended deadline: the same case opens again, with a clean vote
                $existing->votes()->delete();
                $existing->update($fresh + ['trigger_reason' => 'failed_extension']);

                return $existing;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            return null; // another run opened it at the same moment (unique milestone_id)
        }

        if ($nc) $this->tell($milestone, 'response_window', $nc);

        return $nc;
    }

    /** Stage 3: the investors take control (the owner answered, or the 72 hours passed). */
    public function triggerIPM(MilestoneNonCompliance $nc): bool
    {
        $claimed = MilestoneNonCompliance::whereKey($nc->id)->where('stage', 'response_window')
            ->update(['stage' => 'ipm', 'ipm_started_at' => now()]);
        if (! $claimed) return false;
        $nc->refresh();

        Vote::where('type', 'non_compliance')->where('reference_id', $nc->id)->where('status', 'open')->update(['status' => 'closed']);
        if (! $this->votingService->open('non_compliance', $nc->id, self::VOTE_DAYS)) {
            ErrorLogService::report(new \RuntimeException('Unable to open voting for non-compliance '.$nc->id));
        }

        $this->tell($nc->milestone, 'ipm', $nc);

        return true;
    }

    /**
     * Stage 3 → 4 or resolved: the investors' result. Called when a vote reaches quorum, and by the hourly command when the
     * 7 days are up ($votingOver): with no vote at all by then, Tujitume opens a dispute.
     */
    public function getAndSetIPMResult(MilestoneNonCompliance $nc, bool $votingOver = false): ?string
    {
        $winner = $this->ncVotingService->voteResult($nc) ?: null;
        if (! $winner && ! $votingOver) return null;

        $continue = $winner === 'continue';
        $claimed = MilestoneNonCompliance::whereKey($nc->id)->where('stage', 'ipm')->update($continue
            ? ['stage' => 'resolved', 'resolved_at' => now(), 'investor_decision' => 'continue', 'resolution_result' => 'continued']
            : ['stage' => 'pid', 'pid_started_at' => now(), 'investor_decision' => $winner]);
        if (! $claimed) return null;
        $nc->refresh();

        Vote::where('type', 'non_compliance')->where('reference_id', $nc->id)->where('status', 'open')
            ->update(['status' => 'closed', 'ends_at' => now()]);

        $milestone = $nc->milestone;
        if ($continue) {
            $this->reopen($milestone);
            $this->tell($milestone, 'continue', $nc);
        } else {
            $milestone->status = 'locked';
            $milestone->active = false;
            $milestone->save();
            $this->tell($milestone, $winner ? 'pid' : 'pid_no_vote', $nc);
        }

        return $winner ?? 'pid';
    }

    /** 7 more days from the old deadline (or from now when that is long gone); the milestone is open for work again. */
    private function reopen(Milestones $milestone): void
    {
        $base = Carbon::parse($milestone->deadline_date);
        $milestone->deadline_date = ($base->isPast() ? now() : $base)->addDays(self::VOTE_DAYS);
        $milestone->status = 'to_do';
        $milestone->active = true;
        $milestone->save();
    }

    /**
     * Stage 4, decided by Tujitume (admin panel › Non-compliance): settle an open platform dispute before the 14 days run
     * out. "continue" unlocks the milestone with 7 more days; "sanction" sanctions the business now. Returns false when
     * the case is no longer an open dispute (already settled, or the 14 days ran out first).
     */
    public function resolveDispute(MilestoneNonCompliance $nc, string $outcome): bool
    {
        if ($outcome === 'sanction') return $this->applySanctions($nc, true);
        if ($outcome !== 'continue') return false;

        $claimed = MilestoneNonCompliance::whereKey($nc->id)->where('stage', 'pid')->where('sanctioned', false)
            ->update(['stage' => 'resolved', 'resolved_at' => now(), 'resolution_result' => 'continued']);
        if (! $claimed) return false;

        $milestone = $nc->fresh()->milestone;
        $this->reopen($milestone);
        $this->tell($milestone, 'platform_continue', $nc->fresh());

        return true;
    }

    /** Stage 5: a platform dispute still open after 14 days, or one Tujitume decided to sanction ($byPlatform). */
    public function applySanctions(MilestoneNonCompliance $nc, bool $byPlatform = false): bool
    {
        $businessId = $nc->business_id;
        $claimed = MilestoneNonCompliance::whereKey($nc->id)->where('stage', 'pid')->where('sanctioned', false)->update([
            'stage' => 'sanctioned', 'sanctioned' => true, 'resolution_result' => 'blacklisted', 'resolved_at' => now(),
        ]);
        if (! $claimed) return false;

        // Count previous serious sanctions (Tier 2+)
        $strikeCount = BusinessSanction::where('business_id', $businessId)->whereIn('tier', ['tier_2', 'tier_3'])->count();

        DB::transaction(function () use ($nc, $businessId, $strikeCount) {
            BusinessSanction::where('business_id', $businessId)->where('active', true)->update(['active' => false]);

            BusinessSanction::create($strikeCount >= 1
                ? ['business_id' => $businessId, 'non_compliance_id' => $nc->id, 'tier' => 'tier_3',
                    'reason' => 'Repeated milestone non-compliance', 'ends_at' => null, 'active' => true]
                : ['business_id' => $businessId, 'non_compliance_id' => $nc->id, 'tier' => 'tier_1',
                    'reason' => 'Milestone non-compliance', 'ends_at' => now()->addDays(30), 'active' => true]);
        });

        $this->tell($nc->fresh()->milestone, $byPlatform ? 'sanctioned_platform' : 'sanctioned', $nc);

        return true;
    }

    /** The milestone's case while it is still escalating (response window, investor vote or platform dispute). */
    public static function openCase(int $milestoneId): ?MilestoneNonCompliance
    {
        return MilestoneNonCompliance::where('milestone_id', $milestoneId)->whereIn('stage', self::OPEN_STAGES)->first();
    }

    public static function heldCase(int $milestoneId): ?MilestoneNonCompliance
    {
        return MilestoneNonCompliance::where('milestone_id', $milestoneId)->whereIn('stage', self::HELD_STAGES)->first();
    }

    // ── notices ──────────────────────────────────────────────────────────────

    /** The deal-room tab where each side sees the case: the owner answers there, investors vote there. */
    public static function ownerLink(Milestones $m): string
    {
        return 'dashboard.entrepreneur.dealroom::'.$m->listing_id.'?tab=non-compliance';
    }

    public static function investorLink(Milestones $m): string
    {
        return 'dashboard.investor.dealroom?business=@'.$m->listing_id.'&milestone=@'.$m->id.'&tab=non-compliance';
    }

    /** Owner and every investor in the milestone, in-app and by email, each with their own wording and link. */
    public function tell(Milestones $milestone, string $event, MilestoneNonCompliance $nc): void
    {
        $listing = $milestone->listing;
        $owner = $listing?->owner;
        $copy = $this->copy($event, $milestone, $nc);

        $recipients = collect([['user' => $owner, 'side' => 'owner']]);
        foreach ($milestone->investors()->get()->unique('id') as $investor) {
            $recipients->push(['user' => $investor, 'side' => 'investor']);
        }

        foreach ($recipients as $r) {
            $user = $r['user'];
            if (! $user) continue;
            $side = $r['side'];
            $link = $side === 'owner' ? self::ownerLink($milestone) : self::investorLink($milestone);
            try {
                $this->notification->create($user->id, null, $copy[$side], $link, 'milestone');
                if ($user->email) {
                    $this->emailService->send($copy['subject'], $copy['view'], [
                        'milestone_name' => $milestone->title,
                        'business_name' => $listing->name,
                        'body' => $copy[$side],
                        'button' => $copy[$side.'_button'] ?? 'Open the deal room',
                        'reviewUrl' => EmailLink::url($link, $user),
                    ], $user->email);
                }
            } catch (\Throwable $e) {
                ErrorLogService::report($e, ['non_compliance_id' => $nc->id, 'event' => $event, 'user_id' => $user->id]);
            }
        }
    }

    /** @return array{subject: string, view: string, owner: string, investor: string} */
    private function copy(string $event, Milestones $m, MilestoneNonCompliance $nc): array
    {
        $title = "'{$m->title}'";
        $business = $m->listing?->name;
        $progress = (int) $m->progress_percentage;
        $hours = self::RESPONSE_HOURS;
        $days = self::VOTE_DAYS;
        $answered = in_array($nc->owner_response_type, ['completion_proof', 'rmep', 'other'], true);
        $answer = ['completion_proof' => 'completion proof', 'rmep' => 'a revised plan (RMEP)', 'other' => 'documents'][$nc->owner_response_type] ?? null;

        return match ($event) {
            'response_window' => [
                'subject' => 'Milestone Non-Compliant: 72 hours to respond',
                'view' => 'milestone.non_compliance.non_compliant',
                'owner' => "The milestone {$title} for {$business} passed its deadline at {$progress}% progress and is now marked non-compliant. You have {$hours} hours to upload completion proof or a revised plan (RMEP) in the deal room. If you do not respond, your investors take control of the decision.",
                'owner_button' => 'Respond now',
                'investor' => "The milestone {$title} for {$business} passed its deadline at {$progress}% progress and is now marked non-compliant. The owner has {$hours} hours to respond; then you and the other investors decide what happens next.",
            ],
            'ipm' => [
                'subject' => 'Investors now decide on a non-compliant milestone',
                'view' => 'milestone.non_compliance.ipm',
                'owner' => ($answered
                        ? "Your response on {$title} was sent to your investors."
                        : "You did not respond on {$title} within {$hours} hours, so your investors now take control.")
                    ." They have {$days} days to vote: continue ({$days} more days), freeze the milestone or open a dispute.",
                'investor' => "Investors now decide on the non-compliant milestone {$title} for {$business}"
                    .($answered ? " (the owner sent {$answer})" : ' (the owner did not respond in time)')
                    .". Vote to continue ({$days} more days), freeze it or open a dispute. Voting closes in {$days} days.",
                'investor_button' => 'Vote now',
            ],
            'continue' => [
                'subject' => 'Milestone continues after the investor vote',
                'view' => 'milestone.non_compliance.continue',
                'owner' => "Your investors voted to continue {$title}: it has {$days} more days, until ".Carbon::parse($m->deadline_date)->format('j M Y').'.',
                'investor' => "Investors voted to continue {$title} for {$business}: it has {$days} more days.",
            ],
            'pid', 'pid_no_vote' => [
                'subject' => 'Milestone frozen: platform dispute opened',
                'view' => 'milestone.non_compliance.pid',
                'owner' => ($event === 'pid' ? 'Your investors voted to '.($nc->investor_decision === 'freeze' ? 'freeze' : 'dispute')." {$title}." : "No investor voted on {$title} within {$days} days.")
                    .' The milestone is locked and Tujitume is reviewing it as a platform dispute. Reply in the deal room with anything that helps resolve it.',
                'investor' => ($event === 'pid' ? "Investors voted to {$nc->investor_decision} {$title} for {$business}." : "No investor voted on {$title} for {$business} within {$days} days.")
                    .' The milestone is locked and Tujitume is reviewing it as a platform dispute.',
            ],
            'platform_continue' => [
                'subject' => 'Dispute settled: the milestone continues',
                'view' => 'milestone.non_compliance.continue',
                'owner' => "Tujitume reviewed the dispute on {$title} and it continues: it has {$days} more days, until ".Carbon::parse($m->deadline_date)->format('j M Y').'.',
                'investor' => "Tujitume reviewed the dispute on {$title} for {$business} and it continues: it has {$days} more days.",
            ],
            'sanctioned_platform' => [
                'subject' => 'Business sanctioned for milestone non-compliance',
                'view' => 'milestone.non_compliance.sanctioned',
                'owner' => "Tujitume reviewed the dispute on {$title} and sanctioned the business; further milestones stay locked. Tujitume will contact you.",
                'investor' => "Tujitume reviewed the dispute on {$title} for {$business} and sanctioned the business; further milestones stay locked. Tujitume will follow up with investors about the money held for this milestone.",
            ],
            'sanctioned' => [
                'subject' => 'Business sanctioned for milestone non-compliance',
                'view' => 'milestone.non_compliance.sanctioned',
                'owner' => "The dispute on {$title} was not resolved within ".self::PID_DAYS.' days, so the business is sanctioned and further milestones stay locked. Tujitume will contact you.',
                'investor' => "The dispute on {$title} for {$business} was not resolved within ".self::PID_DAYS.' days, so the business is sanctioned and further milestones stay locked. Tujitume will follow up with investors about the money held for this milestone.',
            ],
        };
    }
}
