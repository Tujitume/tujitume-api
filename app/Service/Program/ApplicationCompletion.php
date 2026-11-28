<?php

namespace App\Service\Program;

use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\ProgramNotificationService;
use Throwable;

/**
 * Where a funded application stands after its award (owner decision 27 Nov, replacing the first completion rule):
 *   awarded        some milestone is not finished yet (ProgramMilestone::isFinished: paid, final approval approved)
 *   in_monitoring  every milestone is finished, but monitoring is not: an M&E checkpoint is not verified yet, or there
 *                  are none and the owner has not closed monitoring
 *   completed      every milestone finished AND (every checkpoint verified, or the owner closed monitoring)
 * refresh() is called whenever one of those can change (a final approval, a payment landing, a checkpoint verified,
 * monitoring closed) and tells the applicant and the owner when the application reaches "completed".
 */
class ApplicationCompletion
{
    public const FUNDED = ['awarded', 'in_monitoring', 'completed'];

    public static function milestonesFinished(ProgramApplication $application): bool
    {
        $milestones = ProgramMilestone::where('app_id', $application->id)->where('is_template', false)->get();

        return $milestones->isNotEmpty() && $milestones->every(fn (ProgramMilestone $m) => $m->isFinished());
    }

    public static function monitoringFinished(ProgramApplication $application): bool
    {
        if ($application->monitoring_closed_at) return true;
        $checkpoints = MECheckpoint::where('app_id', $application->id)->get(['status']);

        return $checkpoints->isNotEmpty() && $checkpoints->every(fn ($c) => $c->status === 'verified');
    }

    /** What the status should be now (only for funded applications; null otherwise). */
    public static function statusFor(ProgramApplication $application): ?string
    {
        if (! in_array($application->status, self::FUNDED, true)) return null;
        if (! self::milestonesFinished($application)) return 'awarded';

        return self::monitoringFinished($application) ? 'completed' : 'in_monitoring';
    }

    /** Brings the status up to date; returns the new status when it changed. */
    public static function refresh(?ProgramApplication $application, bool $notify = true): ?string
    {
        if (! $application) return null;
        $application = $application->fresh();
        $next = self::statusFor($application);
        if (! $next || $next === $application->status) return null;

        $application->update([
            'status' => $next,
            'completed_at' => $next === 'completed' ? (self::completedAtFor($application) ?? now()) : null,
        ]);
        if ($next === 'completed' && $notify) self::notifyCompleted($application);

        return $next;
    }

    /**
     * When the application was really complete (ISSUE-34): the later of its last milestone finishing (paid, final
     * approval approved) and its monitoring finishing (last checkpoint verified, or monitoring closed).
     */
    public static function completedAtFor(ProgramApplication $application): ?\Illuminate\Support\Carbon
    {
        $ids = ProgramMilestone::where('app_id', $application->id)->where('is_template', false)->pluck('id');
        $times = collect([
            \App\Models\Programs\MilestoneVerification::whereIn('milestone_id', $ids)->where('verification_type', 'final_approval')->where('decision', 'approved')->max('decided_at'),
            ProgramMilestone::whereIn('id', $ids)->max('completion_approved_at'),
            \App\Models\Programs\Disbursement::whereIn('milestone_id', $ids)->where('status', 'completed')->max('disbursed_at'),
            \App\Models\Programs\Monitoring\MESubmission::whereIn('checkpoint_id', MECheckpoint::where('app_id', $application->id)->where('status', 'verified')->select('id'))
                ->where('status', 'verified')->max('reviewed_at'),
            $application->monitoring_closed_at,
        ])->filter();

        return $times->isEmpty() ? null : $times->map(fn ($t) => \Illuminate\Support\Carbon::parse($t))->max();
    }

    public static function notifyCompleted(ProgramApplication $application): void
    {
        $program = $application->program;
        $data = [
            'program_title' => $program?->program_title, 'program_id' => $application->program_id,
            'application_id' => $application->id, 'business_name' => $application->startup_name,
        ];
        try {
            $notifications = app(ProgramNotificationService::class);
            $notifications->send('application.completed', [$application->user], $data + [
                'email_title' => 'Your program is complete',
                'email_message' => 'Every milestone of your funding plan for '.($program?->program_title ?? 'the program').' is approved and paid, and monitoring is done. Your program is complete. Thank you!',
                'action_label' => 'Open your deal room',
            ]);
            if ($program?->owner) {
                $notifications->send('application.completed_owner', \App\Service\Program\ProgramTeam::members($program)->all(), $data + [
                    'email_title' => 'Program completed',
                    'email_message' => ($application->startup_name ?: 'The applicant').' has completed '.($program->program_title ?? 'the program').': every milestone is approved and paid, and monitoring is done.',
                    'action_label' => 'Open the deal room',
                ]);
            }
        } catch (Throwable $e) {
            ErrorLogService::report($e, ['application_id' => $application->id]);
        }
    }
}
