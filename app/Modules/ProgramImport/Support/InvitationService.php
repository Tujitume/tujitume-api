<?php

namespace App\Modules\ProgramImport\Support;

use App\Models\Auth\User;
use App\Models\Programs\Program;
use App\Modules\ProgramImport\Models\ImportUser;
use App\Modules\ProgramImport\Silent\ImportContext;
use App\Service\Notification\EmailService;
use Illuminate\Support\Str;

/** Deliberate claim invitations for imported (shadow) applicants. Placeholder accounts have no real email and are never contacted. */
class InvitationService
{
    /** @return array{sent: int, skipped: int, skipped_reasons: array} */
    public function send(Program $program, ?array $userIds = null): array
    {
        $applicantIds = $program->applications()->whereNotNull('user_id')->pluck('user_id');
        $q = ImportUser::whereIn('user_id', $applicantIds)->whereNull('claimed_at')->where('kind', 'shadow');
        $userIds && $q->whereIn('user_id', $userIds);
        $sent = 0;
        $skipped = 0;
        foreach ($q->get() as $iu) {
            $user = User::find($iu->user_id);
            if (! $user || $user->password) { // already took over the account
                $skipped++;

                continue;
            }
            $token = Str::random(64);
            $user->forceFill(['invited_at' => now(), 'invitation_token_hash' => hash('sha256', $token), 'invitation_expires_at' => now()->addDays(7)])->save();
            ImportContext::inviting(fn () => (new EmailService)->send(
                'Claim your Tujitume account',
                'program-import::claim_invitation',
                ['recipientName' => $user->first_name, 'program_title' => $program->program_title, 'organization_name' => $program->owner?->display_name,
                    'action_url' => rtrim((string) config('app.app_url'), '/').'/organization-invitations/accept?token='.$token],
                $user->email,
            ));
            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'placeholders_not_contacted' => ImportUser::whereIn('user_id', $applicantIds)->where('kind', 'placeholder')->count()];
    }
}
