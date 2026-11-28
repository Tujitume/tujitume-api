<?php

namespace App\Service\Program;

use App\Models\Programs\Disbursement;
use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;

/**
 * Where a program on Tujitume has got to, for "Add to a program you have" in the import: the stage it is at (as the
 * app runs a program), the stages an import may bring it up to from there, and its awardees (whom milestones,
 * payments and checkpoints in the file are for).
 *
 *  - setup:        no applications yet
 *  - review:       applications still to be reviewed, nobody awarded
 *  - finalization: applications reviewed, nobody awarded yet
 *  - funding:      awardees, none of them with an active funding plan (milestones)
 *  - disbursement: awardees with milestones, nothing paid and no checkpoints yet
 *  - monitoring:   funds paid out, or checkpoints set
 *
 * What an import can add: up to finalization, new rounds and their application fields (the stage it is at; scoring
 * and awarding are done in the program). From funding setup on, the awardees' milestones (with their budget and
 * suppliers) and, at monitoring, the payments already made and the checkpoints. Stages before the one the
 * program is at are done and cannot be chosen.
 */
class ExistingProgramState
{
    public const STAGES = ['setup', 'review', 'finalization', 'funding', 'disbursement', 'monitoring'];

    private const TO_REVIEW = ['pending', 'submitted', 'under_review', 'in_review'];

    /** The stages an import into a program at $current may choose */
    public static function allowed(string $current): array
    {
        return match ($current) {
            'funding' => ['disbursement', 'monitoring'],
            'disbursement' => ['disbursement', 'monitoring'],
            'monitoring' => ['monitoring'],
            default => [$current],
        };
    }

    /** Whether an import into a program at this stage brings milestones (and more) rather than new rounds */
    public static function addsFunding(?string $stage): bool
    {
        return in_array($stage, ['disbursement', 'monitoring'], true);
    }

    /** The awardee's email, as the import file names them: their account's, else the one on the application */
    public static function emailOf(ProgramApplication $application): string
    {
        return mb_strtolower(trim((string) ($application->user?->email ?: ($application->email ?: $application->contact_person_email))));
    }

    public static function of(Program $program): array
    {
        $applications = ProgramApplication::where('program_id', $program->id)->where('status', '!=', 'draft');
        $count = (clone $applications)->count();
        $toReview = (clone $applications)->whereIn('status', self::TO_REVIEW)->count();
        $approved = (clone $applications)->whereIn('status', ['approved', 'awarded', 'in_monitoring', 'completed'])->count();
        $awardees = ProgramApplication::where('program_id', $program->id)->whereIn('status', ['awarded', 'in_monitoring', 'completed'])
            ->with('user:id,email,first_name,last_name')
            ->get(['id', 'user_id', 'email', 'contact_person_email', 'contact_person_name', 'startup_name']);
        $ids = $awardees->pluck('id');
        // An active funding plan: milestones that are no longer the draft template
        $withPlan = ProgramMilestone::whereIn('app_id', $ids)->where('is_template', false)->distinct()->pluck('app_id')->flip();
        $withDraft = ProgramMilestone::whereIn('app_id', $ids)->where('is_template', true)->distinct()->pluck('app_id')->flip();
        $checkpoints = MECheckpoint::whereIn('app_id', $ids)->selectRaw('app_id, count(*) as n')->groupBy('app_id')->pluck('n', 'app_id');
        $milestoneIds = ProgramMilestone::whereIn('app_id', $ids)->where('is_template', false)->pluck('id');
        $paid = $milestoneIds->isEmpty() ? 0 : Disbursement::whereIn('milestone_id', $milestoneIds)->where('status', 'completed')->count();

        $stage = match (true) {
            $count === 0 => 'setup',
            $awardees->isEmpty() => $toReview > 0 ? 'review' : 'finalization',
            $withPlan->isEmpty() => 'funding',
            $paid > 0 || $checkpoints->sum() > 0 => 'monitoring',
            default => 'disbursement',
        };

        return [
            'program_id' => $program->id,
            'stage' => $stage,
            'allowed' => self::allowed($stage),
            'counts' => [
                'applications' => $count,
                'to_review' => $toReview,
                'approved' => $approved,
                'awardees' => $awardees->count(),
                'with_milestones' => $withPlan->count(),
                'checkpoints' => (int) $checkpoints->sum(),
                'payments' => $paid,
            ],
            'program' => [
                'title' => $program->program_title,
                'currency' => $program->currency,
                'disbursement_type' => $program->disbursement_type,
                'funding_per_business' => $program->funding_per_business,
            ],
            'awardees' => $awardees->take(2000)->map(fn (ProgramApplication $a) => [
                'email' => self::emailOf($a),
                'name' => $a->startup_name ?: ($a->contact_person_name ?: trim(($a->user?->first_name ?? '').' '.($a->user?->last_name ?? ''))),
                'has_milestones' => $withPlan->has($a->id),
                // A draft funding plan in the deal room (not activated): milestones in the file replace it
                'has_draft' => ! $withPlan->has($a->id) && $withDraft->has($a->id),
                'checkpoints' => (int) ($checkpoints[$a->id] ?? 0),
            ])->filter(fn ($a) => $a['email'] !== '')->values()->all(),
        ];
    }
}
