<?php

namespace App\Service\Program;

use App\Models\Programs\ProgramApplication;
use Illuminate\Support\Collection;

/**
 * Where an application is in its deal room, decided on the server so the program owner and the entrepreneur
 * always agree and neither page has to guess from whatever happens to be loaded.
 *
 * `steps` is the deal room as a whole (funding setup, MPRV, mid-milestone, final approval, M&E) and
 * `funding_setup` is the progress inside the funding-setup step.
 */
class DealRoomProgress
{
    public static function forApplication(ProgramApplication $application, Collection $milestones, float $allocated, float $approved): array
    {
        $fundingDone = $application->funding_setup_status === 'completed';

        // A milestone's status is a plain string, or an object/array carrying `value` once decorated for the API
        $statusOf = function ($m): string {
            $status = $m->status ?? '';
            if (is_object($status)) return (string) ($status->value ?? '');
            if (is_array($status)) return (string) ($status['value'] ?? '');
            return (string) $status;
        };
        $required = fn (string $flag) => $milestones->contains(fn ($m) => (bool) $m->{$flag});

        $mprvRequired = $required('mprv_required');
        $midRequired = $required('mid_milestone_required');
        $finalRequired = $required('final_approval_required');

        $allApprovedVerifications = function (string $type) use ($milestones): bool {
            $requiredMilestones = $milestones->filter(fn ($m) => (bool) $m->{match ($type) {
                'mprv' => 'mprv_required',
                'mid_milestone' => 'mid_milestone_required',
                default => 'final_approval_required',
            }});

            return $requiredMilestones->isNotEmpty() && $requiredMilestones->every(
                fn ($m) => $m->verifications()
                    ->where('verification_type', $type)
                    ->where('decision', 'approved')
                    ->exists()
            );
        };
        $finalMilestones = $milestones->filter(fn ($m) => (bool) $m->final_approval_required);
        $milestonesComplete = $milestones->isNotEmpty()
            && $milestones->every(fn ($m) => $statusOf($m) === 'completion_approved');
        $checkpoints = \App\Models\Programs\Monitoring\MECheckpoint::where('app_id', $application->id)->get();
        $monitoringComplete = $milestonesComplete && $checkpoints->isNotEmpty()
            && $checkpoints->every(fn ($checkpoint) => $checkpoint->status === 'verified');

        $steps = [
            ['id' => 'funding-setup', 'required' => true, 'done' => $fundingDone],
            ['id' => 'mprv', 'required' => $mprvRequired, 'done' => $mprvRequired && $allApprovedVerifications('mprv')],
            ['id' => 'mid-milestone', 'required' => $midRequired, 'done' => $midRequired && $allApprovedVerifications('mid_milestone')],
            ['id' => 'final-approval', 'required' => $finalRequired, 'done' => $finalRequired
                && $finalMilestones->isNotEmpty()
                && $finalMilestones->every(fn ($m) => $statusOf($m) === 'completion_approved')],
            ['id' => 'monitoring-evaluation', 'required' => true, 'done' => $monitoringComplete],
        ];

        // The person's step is the first one that is required and not finished. Steps that do not apply
        // to this program are skipped, and nothing after funding setup opens before it is complete.
        $active = collect($steps)->first(fn ($s) => $s['required'] && ! $s['done']);

        return [
            'steps' => $steps,
            'completed_steps' => collect($steps)->where('done', true)->pluck('id')->values()->all(),
            'not_required_steps' => collect($steps)->where('required', false)->pluck('id')->values()->all(),
            'active_step_id' => $active['id'] ?? 'monitoring-evaluation',
            'funding_setup' => self::fundingSetup($application, $milestones, $allocated, $approved),
        ];
    }

    /** Progress inside the funding-setup step, with the one thing to do next. */
    private static function fundingSetup(ProgramApplication $application, Collection $milestones, float $allocated, float $approved): array
    {
        $status = (string) $application->funding_setup_status;
        $hasMilestones = $milestones->isNotEmpty();
        $remaining = round($approved - $allocated, 2);
        $allocationDone = $hasMilestones && abs($remaining) < 0.01;
        $detailsDone = $hasMilestones && $milestones->every(fn ($m) => $m->relationLoaded('budgetItems') ? $m->budgetItems->isNotEmpty() : true);
        $completed = $status === 'completed';

        $steps = [
            ['id' => 'milestones', 'done' => $hasMilestones],
            ['id' => 'allocation', 'done' => $allocationDone],
            ['id' => 'budget', 'done' => $detailsDone],
            ['id' => 'activate', 'done' => $completed],
        ];

        $message = match (true) {
            $completed => 'Funding setup is complete.',
            $status === 'awaiting_applicant_revision' => 'Waiting for the applicant to revise the plan.',
            $status === 'awaiting_owner_review' => 'The plan is ready: review it, then activate it or request changes.',
            ! $hasMilestones => 'Add the first milestone to start the funding plan.',
            ! $allocationDone && $remaining > 0 => 'Allocate the remaining ' . number_format($remaining, 2) . ' across your milestones.',
            ! $allocationDone => 'Your milestones add up to more than the approved amount: reduce them by ' . number_format(abs($remaining), 2) . '.',
            ! $detailsDone => 'Add budget items to each milestone.',
            default => 'Everything is in place: activate the funding plan.',
        };

        $active = collect($steps)->first(fn ($s) => ! $s['done']);

        return [
            'steps' => $steps,
            'completed_steps' => collect($steps)->where('done', true)->pluck('id')->values()->all(),
            'active_step_id' => $active['id'] ?? 'activate',
            'next_action' => $message,
        ];
    }
}
