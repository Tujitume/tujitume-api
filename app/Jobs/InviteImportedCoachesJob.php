<?php

namespace App\Jobs;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Programs\ProgramImport;
use App\Models\Programs\Rounds\RoundReviewer;
use App\Service\Notification\EmailBrand;
use App\Service\Notification\ProgramNotificationService;
use App\Service\Notification\RecipientUrl;
use App\Service\Program\ImportCoachAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Jobs\Concerns\RecordsImportSkips;
use App\Service\Program\ImportMail;
use Throwable;

/**
 * "Invite Business Coaches" after an import, one person at a time:
 *  - a Business Coach new to Tujitume (their quiet account has no password) gets the same invitation Round Config
 *    sends, to set their password and join; once in, the review requests are waiting for them to accept;
 *  - someone who already has an account (a team member, a coach you work with) is told which rounds they were
 *    asked to review (the same "scoring assigned" notice as Round Config), and accepts there.
 * Who was invited is saved after each person, so nobody is invited twice. After TIME_BUDGET seconds the rest is
 * handed to a fresh run, as the import job does.
 */
class InviteImportedCoachesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, RecordsImportSkips;

    private const TIME_BUDGET = 45;
    private const INVITATION_DAYS = 7;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $importId)
    {
    }

    public function handle(ProgramNotificationService $notifications): void
    {
        $lock = Cache::lock("program-import-invite:{$this->importId}", $this->timeout + 30);
        if (! $lock->get()) return;
        try {
            $more = $this->work($notifications);
        } finally {
            $lock->release();
        }
        if ($more) self::dispatch($this->importId);
    }

    private function work(ProgramNotificationService $notifications): bool
    {
        $import = ProgramImport::find($this->importId);
        if (! $import || ($import->progress['invite']['status'] ?? null) !== 'sending') return false;

        $started = microtime(true);
        $organization = Organization::find($import->organization_id);
        $inviter = User::find($import->progress['invite']['by'] ?? $import->user_id);
        $people = RoundReviewer::whereIn('id', $import->reviewersToInvite())->with(['programRound.program'])->orderBy('id')->get()->groupBy('user_id');

        foreach ($people as $userId => $places) {
            if (microtime(true) - $started > self::TIME_BUDGET) return true;
            try {
                $user = User::find($userId);
                // An address that cannot receive mail is not sent to (it would bounce): recorded with the reason
                if ($user && ($why = ImportMail::problem($user->email))) { $this->skip($import, 'invite', $places->pluck('id')->all(), mb_strtolower($user->email), $why); continue; }
                if ($user) $this->invite($notifications, $user, $places, $organization, $inviter);
                $this->record($import, $places->pluck('id')->all());
                ImportMail::pace();
            } catch (Throwable $e) {
                // Not counted as invited: a retry reaches them
                Log::error('Program import: a Business Coach could not be invited', ['import' => $import->id, 'error' => $e->getMessage()]);
            }
        }

        $progress = $import->progress;
        $progress['invite']['status'] = 'sent';
        $progress['invite']['sent_at'] = now()->toIso8601String();
        $import->forceFill(['progress' => $progress])->save();

        return false;
    }

    /**
     * Someone the import made quietly (no password yet) gets their invitation to Tujitume: a team member new to
     * Tujitume the Team Members → Invite email (their place on the team waits), an outside Business Coach the Round
     * Config → Add Business Coach one. Also used for site visit officers (NotifyImportedInspectorsJob).
     * @return bool whether an invitation was sent (the person is new); false for someone who can already sign in
     */
    public static function sendInvitation(User $user, ?Organization $organization, ?User $inviter): bool
    {
        $noPassword = empty($user->getAttributes()['password'] ?? null);
        // A team member new to Tujitume: the same invitation as Team Members → Invite (their place on the team waits)
        $teamRole = $noPassword && $organization ? \App\Models\Auth\OrganizationUserRole::where('organization_id', $organization->id)
            ->where('user_id', $user->id)->where('status', 'pending')->with('role')->first() : null;
        if ($teamRole) {
            $token = Str::random(64);
            $teamRole->forceFill(['invited_at' => now(), 'invitation_token_hash' => hash('sha256', $token), 'invitation_expires_at' => now()->addDays(self::INVITATION_DAYS)])->save();
            Mail::send('organization_team_invitation', [
                'brand' => EmailBrand::forOrganization($organization),
                'teamMember' => $user,
                'organization' => $organization,
                'inviter' => $inviter ?? $organization->owner,
                'role' => $teamRole->role?->name ?? 'internal_reviewer',
                'invitationUrl' => RecipientUrl::url('organization-invitations/accept?token='.$token, $user),
                'expiresAt' => now()->addDays(self::INVITATION_DAYS),
            ], function ($message) use ($user, $organization): void {
                $message->to($user->email)->subject("You've been invited to join {$organization->name}");
            });

            return true;
        }
        $new = $noPassword && (int) $user->user_type_id === ImportCoachAccount::COACH_TYPE;
        if ($new && $organization) {
            // The same invitation as Round Config → Add Business Coach (RegisterService::registerExternalReviewer)
            $token = Str::random(64);
            $user->forceFill(['invited_at' => now(), 'invitation_token_hash' => hash('sha256', $token), 'invitation_expires_at' => now()->addDays(self::INVITATION_DAYS)])->save();
            Mail::send('external_reviewer_invitation', [
                'brand' => EmailBrand::forOrganization($organization),
                'teamMember' => $user,
                'organization' => $organization,
                'inviter' => $inviter ?? $organization->owner,
                'role' => 'Business Coach',
                'invitationUrl' => RecipientUrl::url('organization-invitations/accept?token='.$token, $user),
                'expiresAt' => now()->addDays(self::INVITATION_DAYS),
            ], function ($message) use ($user, $organization): void {
                $message->to($user->email)->subject("You've been invited to join Tujitume as a Business Coach for {$organization->name}");
            });

            return true;
        }

        return false;
    }

    private function invite(ProgramNotificationService $notifications, User $user, $places, ?Organization $organization, ?User $inviter): void
    {
        if (self::sendInvitation($user, $organization, $inviter)) return;

        foreach ($places as $place) {
            $round = $place->programRound;
            // Work already done (a finished round, or scores that came with the import) has nothing to accept
            if (! $round || $place->acceptance_status === 'accepted') continue;
            $notifications->send('round.scoring_assigned', [$user], [
                'program_title' => $round->program?->program_title,
                'round_name' => $round->round_name,
                'max_apps' => $place->max_apps_assigned,
                'expertise_tags' => [],
                'program_id' => $round->program_id,
                'proposed_fee' => $place->reviewer_type === 'internal' ? 0 : $place->reviewer_fee,
                'fee_type' => $place->fee_type,
                'fee_currency' => $place->fee_currency,
                'recipientName' => $user->first_name ?? $user->display_name,
            ]);
        }
    }

    private function record(ProgramImport $import, array $reviewerIds): void
    {
        $import->refresh();
        $progress = $import->progress;
        $progress['invite']['sent_ids'] = array_values(array_unique(array_merge($progress['invite']['sent_ids'] ?? [], $reviewerIds)));
        $progress['invite']['sent'] = ($progress['invite']['sent'] ?? 0) + 1;
        $import->forceFill(['progress' => $progress])->save();
    }

    public function failed(Throwable $e): void
    {
        $import = ProgramImport::find($this->importId);
        if ($import && ($import->progress['invite']['status'] ?? null) === 'sending') {
            $progress = $import->progress;
            $progress['invite']['status'] = 'stopped';
            $import->forceFill(['progress' => $progress])->save();
        }
        Log::error('Program import invite job stopped', ['import' => $this->importId, 'error' => $e->getMessage()]);
    }
}
