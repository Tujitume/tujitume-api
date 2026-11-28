<?php

namespace App\Service\Program;

use App\Models\Programs\MilestonePreAgreement;
use App\Models\Programs\MilestoneVerification;
use App\Models\Programs\ProgramMilestone;

/**
 * DECISION-8 (owner 27 Nov): a milestone check (MPRV, mid-milestone or final approval) that the owner has sent back twice
 * (rejected or changes requested), or whose step agreement was rejected twice, is not decided by the owner again: its
 * next submission waits for a project manager audit ("audit needed": the owner picks a project manager, who reports;
 * then the owner decides).
 */
class RepeatedRejectionAudit
{
    public const LIMIT = 2;

    public static function rejections(ProgramMilestone $milestone, string $type): int
    {
        return MilestoneVerification::where('milestone_id', $milestone->id)->where('verification_type', $type)
            ->whereIn('decision', ['rejected', 'modification_requested'])->count();
    }

    public static function agreementFinallyRejected(ProgramMilestone $milestone, string $type): bool
    {
        return MilestonePreAgreement::where('milestone_id', $milestone->id)->where('verification_type', $type)
            ->where('status', 'final_rejected')->exists();
    }

    public static function required(ProgramMilestone $milestone, string $type): bool
    {
        return self::rejections($milestone, $type) >= self::LIMIT || self::agreementFinallyRejected($milestone, $type);
    }

    /** Waiting for the owner to pick a project manager (a decision is refused until then). */
    public static function waiting(MilestoneVerification $verification): bool
    {
        return $verification->decision === 'pending' && $verification->audit_status === 'needed';
    }
}
