<?php

namespace App\Service\DemoRequests;

use App\Models\Auth\User;
use App\Models\Business\BusinessSubscriptions;
use App\Models\DemoRequest;
use App\Models\Organizations\Organization;
use App\Models\Organizations\Workspace;
use App\Models\ProgramRegistrationInvite;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\RecipientUrl;
use Illuminate\Support\Facades\DB;

/**
 * The life of a landing-page request (see DemoRequest for the states).
 *
 *   submit → pending ─ approve (demo) ─→ trial_active ─ requestFullAccess ─→ full_access_requested
 *                    ├ sendProposal ──→ proposal_sent        (one-time sign-up link emailed)
 *                    └ reject(reason) → rejected                     │ sign-up at /auth/create/program
 *                                                                    ▼
 *   trial ends without full access → expired (deleted)    registered ─ first paid subscription → converted
 *                                                          (trial deleted, "workspace ready" email)
 */
class DemoRequestService
{
    public const REMINDER_HOURS_BEFORE_END = 48;

    public function __construct(
        private TrialAccountService $trials = new TrialAccountService(),
        private DemoRequestMailer $mailer = new DemoRequestMailer(),
    ) {
    }

    /** An open request of the same kind from the same address, which a new one would duplicate. */
    public function openDuplicate(string $email, string $requestType): ?DemoRequest
    {
        $email = strtolower(trim($email));

        return DemoRequest::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where(function ($q) use ($requestType) {
                $q->where(fn ($q) => $q->where('request_type', $requestType)->where('status', DemoRequest::STATUS_PENDING))
                    ->orWhereIn('status', DemoRequest::TRIAL_STATUSES);
            })
            ->latest('id')
            ->first();
    }

    public function duplicateMessage(DemoRequest $r): string
    {
        return match ($r->status) {
            DemoRequest::STATUS_TRIAL_ACTIVE => 'You already have a demo account. Use the link in the email we sent to '.$r->email.' to sign in. It is open until '.$r->onRequesterClock($r->trial_expires_at).'.',
            DemoRequest::STATUS_FULL_ACCESS_REQUESTED, DemoRequest::STATUS_REGISTERED => 'Your full-access request is in progress. Check '.$r->email.' for the link to set up your workspace.',
            default => 'Your "'.$r->requestTypeLabel().'" request is being reviewed. We will email you at '.$r->email.' as soon as it is.',
        };
    }

    public function submit(array $data): DemoRequest
    {
        $request = DemoRequest::create($data + ['status' => DemoRequest::STATUS_PENDING]);
        $request->load('programIndustry');

        $this->mailer->received($request);
        $this->mailer->teamNotice($request);

        return $request;
    }

    public function approve(DemoRequest $r, int $adminId): void
    {
        if ($r->status !== DemoRequest::STATUS_PENDING || $r->request_type !== DemoRequest::TYPE_DEMO) {
            throw new \RuntimeException('Only a pending demo request can be approved.');
        }

        $link = $this->trials->create($r);
        $r->update(['reviewed_by_admin_id' => $adminId, 'reviewed_at' => now(), 'rejection_reason' => null]);

        $this->mailer->trialReady($r->fresh(), $link, $this->fullAccessLink($r));
    }

    public function resendAccess(DemoRequest $r): bool
    {
        $link = $this->trials->resendAccess($r);
        if (! $link) return false;

        return $this->mailer->trialReady($r, $link, $this->fullAccessLink($r));
    }

    public function reject(DemoRequest $r, string $reason, int $adminId): void
    {
        if ($r->status !== DemoRequest::STATUS_PENDING) {
            throw new \RuntimeException('Only a pending request can be rejected.');
        }

        $r->update([
            'status' => DemoRequest::STATUS_REJECTED,
            'rejection_reason' => $reason,
            'reviewed_by_admin_id' => $adminId,
            'reviewed_at' => now(),
        ]);
        $this->mailer->rejected($r);
    }

    public function sendProposal(DemoRequest $r, string $subject, string $body, int $adminId): bool
    {
        if (in_array($r->status, [DemoRequest::STATUS_REJECTED, DemoRequest::STATUS_EXPIRED, DemoRequest::STATUS_CLOSED], true)) {
            throw new \RuntimeException('This request is closed.');
        }

        $r->update([
            'proposal_subject' => $subject,
            'proposal_body' => $body,
            'reviewed_by_admin_id' => $adminId,
            'reviewed_at' => $r->reviewed_at ?? now(),
        ]);

        $sent = $this->mailer->proposal($r);
        if ($sent) {
            $r->update([
                'proposal_sent_at' => now(),
                // A demo keeps its own state; a proposal / pilot / inquiry is answered by the email
                'status' => $r->status === DemoRequest::STATUS_PENDING && $r->request_type !== DemoRequest::TYPE_DEMO
                    ? DemoRequest::STATUS_PROPOSAL_SENT : $r->status,
            ]);
        }

        return $sent;
    }

    public function extendTrial(DemoRequest $r, int $days): void
    {
        if (! $r->hasTrial()) throw new \RuntimeException('This request has no running trial.');
        $this->trials->extend($r, $days);
    }

    public function endTrialNow(DemoRequest $r): void
    {
        if (! $r->hasTrial()) throw new \RuntimeException('This request has no running trial.');
        $this->trials->purge($r, DemoRequest::STATUS_EXPIRED);
        $this->mailer->trialClosed($r->fresh());
    }

    /** The request behind a trial account, when the person is in one. */
    public function forTrialUser(User $user): ?DemoRequest
    {
        if (! $user->organization_id) return null;

        return DemoRequest::where('trial_organization_id', $user->organization_id)
            ->whereIn('status', DemoRequest::TRIAL_STATUSES)
            ->latest('id')
            ->first();
    }

    /**
     * The "full access" link in the demo-ready email: a one-time sign-up link, issued without asking for full access
     * yet (the demo still ends on time unless they use it or press Get full access). A new one replaces the earlier one.
     */
    private function fullAccessLink(DemoRequest $r): string
    {
        [, $token] = ProgramRegistrationInvite::issue($r->email, $r->name, $r->id);

        return ProgramRegistrationInvite::urlFor($token);
    }

    /**
     * "Get full access" inside the demo: the one-time sign-up link the demo then opens straight away (no email).
     * The team is told. @return string the link to /auth/create/program
     */
    public function fullAccessFromDemo(DemoRequest $r): string
    {
        if (! $r->hasTrial()) {
            throw new \RuntimeException('This demo has ended.');
        }

        [, $token] = ProgramRegistrationInvite::issue($r->email, $r->name, $r->id);
        $first = $r->full_access_requested_at === null;
        $r->update(['full_access_requested_at' => $r->full_access_requested_at ?? now(), 'status' => DemoRequest::STATUS_FULL_ACCESS_REQUESTED]);
        if ($first) {
            $this->mailer->teamNoticeFullAccess($r);
        }

        return ProgramRegistrationInvite::urlFor($token);
    }

    /**
     * "Give full access" in the admin: emails the requester a one-time link to /auth/create/program. Sending again
     * replaces the earlier link.
     */
    public function grantFullAccess(DemoRequest $r, ?int $adminId = null): ProgramRegistrationInvite
    {
        if (! in_array($r->status, [...DemoRequest::TRIAL_STATUSES, DemoRequest::STATUS_PENDING, DemoRequest::STATUS_PROPOSAL_SENT], true)) {
            throw new \RuntimeException('Full access can only be given to an open request.');
        }

        [$invite, $token] = ProgramRegistrationInvite::issue($r->email, $r->name, $r->id, $adminId);

        $r->update([
            'full_access_requested_at' => $r->full_access_requested_at ?? now(),
            'status' => $r->hasTrial() || $r->status === DemoRequest::STATUS_FULL_ACCESS_REQUESTED
                ? DemoRequest::STATUS_FULL_ACCESS_REQUESTED : $r->status,
        ]);

        $this->mailer->registrationInvite($r->email, $r->name, ProgramRegistrationInvite::urlFor($token), $invite->expires_at, $r);
        if (! $adminId) {
            $this->mailer->teamNoticeFullAccess($r);
        }

        return $invite;
    }

    /** An invite for any email address, from the admin (not tied to a request). */
    public function inviteEmail(string $email, ?string $name, int $adminId): ProgramRegistrationInvite
    {
        [$invite, $token] = ProgramRegistrationInvite::issue($email, $name, null, $adminId);
        $this->mailer->registrationInvite($invite->email, $name, ProgramRegistrationInvite::urlFor($token), $invite->expires_at);

        return $invite;
    }

    /**
     * Before sign-up validation runs: the trial still holds the email address the new account needs. The trial
     * account is closed to sign-in now and deleted after payment (or when the trial would have ended).
     */
    public function prepareRegistration(ProgramRegistrationInvite $invite): void
    {
        $r = $invite->demoRequest;
        if ($r && $r->hasTrial()) {
            $this->trials->releaseEmail($r);
        }
    }

    /** Inside the sign-up transaction, once the new account exists. */
    public function markRegistered(ProgramRegistrationInvite $invite, User $user): void
    {
        $invite->update(['used_at' => now(), 'registered_user_id' => $user->id]);

        $r = $invite->demoRequest;
        if ($r && ! in_array($r->status, [DemoRequest::STATUS_REJECTED, DemoRequest::STATUS_CONVERTED], true)) {
            $hadTrial = $r->hasTrial();
            $r->update([
                'status' => $hadTrial ? DemoRequest::STATUS_REGISTERED : $r->status,
                'converted_user_id' => $user->id,
            ]);
            // The full account replaces the demo: the demo could no longer be signed in to (its email moved here) and
            // nothing carries over, so it is deleted now rather than counting down to its end or waiting for payment
            if ($hadTrial) {
                $this->trials->purge($r->fresh(), DemoRequest::STATUS_REGISTERED);
            }
        }
    }

    /**
     * A subscription row was created. When it is the first paid one of an account that signed up through an
     * invite: delete the trial (if any) and send the "your workspace is ready" email once.
     */
    public function onSubscriptionCreated(BusinessSubscriptions $subscription): void
    {
        if ((int) $subscription->trial === 1 || (float) $subscription->amount <= 0) return;

        $invite = ProgramRegistrationInvite::where('registered_user_id', $subscription->investor_id)
            ->whereNull('workspace_ready_sent_at')
            ->first();
        if (! $invite) return;

        $user = User::find($subscription->investor_id);
        $organization = $user?->organization_id ? Organization::find($user->organization_id) : null;
        if (! $user || ! $organization) return;

        $invite->update(['workspace_ready_sent_at' => now()]);

        $r = $invite->demoRequest;
        if ($r) {
            try {
                if ($r->hasTrial()) $this->trials->purge($r, DemoRequest::STATUS_CONVERTED);
                $r->update(['status' => DemoRequest::STATUS_CONVERTED, 'converted_at' => now(), 'workspace_ready_sent_at' => now()]);
            } catch (\Throwable $e) {
                ErrorLogService::report($e, ['demo_request_id' => $r->id]);
            }
        }

        $workspace = Workspace::where('organization_id', $organization->id)->orderBy('id')->first();
        $link = ($workspace ? RecipientUrl::workspaceBase($workspace) : null) ?? RecipientUrl::main();

        $this->mailer->workspaceReady($user->email, $user->first_name ?: $user->display_name, $link.'/login', $organization->name);
    }

    /**
     * Scheduled (demo-trials:sweep): the "ends soon" reminder, then closing trials whose end has passed. A trial
     * whose owner asked for full access stays until the sign-up link expires (and is deleted after payment).
     * All moments are UTC instants, so the end is exact whatever zone the requester is in.
     *
     * @return array{reminded:int, closed:int}
     */
    public function sweep(): array
    {
        $reminded = 0;
        $closed = 0;

        DemoRequest::where('status', DemoRequest::STATUS_TRIAL_ACTIVE)
            ->whereNull('trial_reminder_sent_at')
            ->where('trial_expires_at', '>', now())
            ->where('trial_expires_at', '<=', now()->addHours(self::REMINDER_HOURS_BEFORE_END))
            ->get()
            ->each(function (DemoRequest $r) use (&$reminded) {
                $r->update(['trial_reminder_sent_at' => now()]);
                $this->mailer->trialEndingSoon($r);
                $reminded++;
            });

        DemoRequest::whereIn('status', DemoRequest::TRIAL_STATUSES)
            ->whereNull('trial_closed_at')
            ->where('trial_expires_at', '<=', now())
            ->get()
            ->each(function (DemoRequest $r) use (&$closed) {
                if ($r->status !== DemoRequest::STATUS_TRIAL_ACTIVE && $this->hasOpenInvite($r)) return;

                try {
                    $final = $r->status === DemoRequest::STATUS_REGISTERED ? DemoRequest::STATUS_REGISTERED : DemoRequest::STATUS_EXPIRED;
                    $this->trials->purge($r, $final);
                    if ($final === DemoRequest::STATUS_EXPIRED) $this->mailer->trialClosed($r->fresh());
                    $closed++;
                } catch (\Throwable $e) {
                    ErrorLogService::report($e, ['demo_request_id' => $r->id]);
                }
            });

        return ['reminded' => $reminded, 'closed' => $closed];
    }

    private function hasOpenInvite(DemoRequest $r): bool
    {
        return ProgramRegistrationInvite::where('demo_request_id', $r->id)
            ->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->exists();
    }
}
