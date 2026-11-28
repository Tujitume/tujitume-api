<?php

namespace App\Service\Program;

use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundCustomQuestion;

/**
 * How ready a round is: which setup steps are done, whether the round is fully configured, and where it is in its
 * publishing life (not published, scheduled for its open date, open, ...). One place, so the wizard, the publish check
 * and the program overview all say the same thing.
 */
class RoundConfiguration
{
    /** The wizard steps a person confirms themselves; publishing is decided by the round's status. */
    // Scoring comes after the questions and reviewers, so the criteria can be written from the questions asked
    public const CONFIRMABLE_STEPS = ['setup', 'questions', 'reviewers', 'scoring', 'notifications'];

    public const LABELS = [
        'setup' => 'Round setup (dates and rules)',
        'questions' => 'Application questions',
        'reviewers' => 'Reviewers',
        'scoring' => 'Scoring criteria',
        'notifications' => 'Notification emails',
    ];

    /** The wizard steps the person has confirmed, stored on the round (program_rounds.confirmed_steps). */
    public function confirmedSteps(ProgramRound $round): array
    {
        return (array) ($round->confirmed_steps ?? []);
    }

    /**
     * Where the person is in the round wizard. A step is done when its data is valid AND the person has
     * confirmed it (saved or moved on). Data alone is not enough: the first round is created with the
     * program, so its dates and questions exist before anyone has opened the wizard.
     * A round that is already live counts every step as done.
     */
    public function progress(ProgramRound $round, bool $requireConfirmation = true): array
    {
        $isLive = $round->status !== 'draft';
        $confirmed = $this->confirmedSteps($round);
        $confirms = fn (string $step) => ! $requireConfirmation || $isLive || in_array($step, $confirmed, true);

        $steps = [
            'setup' => $this->setupComplete($round) && $confirms('setup'),
            'questions' => RoundCustomQuestion::where('round_id', $round->id)
                ->where('question_type', '!=', 'knockout')
                ->exists() && $confirms('questions'),
            'reviewers' => ($round->assignment_type === 'owner_only'
                || $round->reviewers()->count() >= max(1, (int) ($round->min_reviewers_required ?? 1)))
                && $confirms('reviewers'),
            // Rounds set up before Scoring was its own step confirmed their criteria with Setup
            'scoring' => $this->scoringComplete($round)
                && ($confirms('scoring') || ($requireConfirmation && in_array('setup', $confirmed, true))),
            // Every email is optional (one not written goes out as the system default): the step only needs a visit
            'notifications' => $confirms('notifications'),
            'publish' => $isLive,
        ];

        $completed = collect($steps)->filter()->keys()->values()->all();
        $stepOrder = array_keys($steps);
        $activeStep = collect($stepOrder)->search(fn ($step) => ! $steps[$step]);

        return [
            'steps' => $steps,
            'completed_steps' => $completed,
            'active_step' => $activeStep === false ? count($stepOrder) - 1 : $activeStep,
            'active_step_id' => $activeStep === false ? end($stepOrder) : $stepOrder[$activeStep],
        ];
    }

    /** Wizard steps (before publish) that are not done yet, as step => label, for the publish check. */
    public function unfinished(ProgramRound $round): array
    {
        $steps = $this->progress($round)['steps'];

        return collect(self::LABELS)->filter(fn ($label, $step) => empty($steps[$step]))->all();
    }

    public function setupComplete(ProgramRound $round): bool
    {
        if (! $round->round_name || ! $round->open_date || ! $round->close_date) {
            return false;
        }

        foreach ($round->required_documents ?? [] as $document) {
            if (empty($document['label'])) {
                return false;
            }
        }

        return true;
    }

    /** At least one named criterion; weighted rubrics must add up to exactly 100. */
    public function scoringComplete(ProgramRound $round): bool
    {
        $criteria = $round->scoring_criteria ?? [];
        if (empty($criteria)) {
            return false;
        }
        foreach ($criteria as $criterion) {
            if (empty($criterion['name'])) {
                return false;
            }
        }
        if (($round->rubric_mode ?? 'weighted') === 'weighted') {
            return collect($criteria)->sum(fn ($criterion) => (int) ($criterion['weight'] ?? 0)) === 100;
        }

        return true;
    }

    /**
     * Where the round is in its publishing life:
     *   draft          not published yet
     *   scheduled      published, but its open date has not arrived: applicants cannot apply yet
     *   open           published and taking applications
     *   deadline_passed published and past its close date, not closed yet
     *   closed / in_review / finalized   as the round's own status says
     */
    public function publishState(ProgramRound $round): string
    {
        if ($round->status !== 'published') {
            return (string) $round->status;
        }
        if (! $round->hasOpened()) {
            return 'scheduled';
        }
        if ($round->hasClosed()) {
            return 'deadline_passed';
        }

        return 'open';
    }

    /**
     * Everything the overview needs to tell the owner, for one round: is it fully configured, what is missing,
     * and is it published, waiting for its open date, or live.
     */
    public function readiness(ProgramRound $round): array
    {
        $steps = $this->progress($round)['steps'];
        $missing = collect(self::LABELS)
            ->filter(fn ($label, $step) => empty($steps[$step]))
            ->map(fn ($label, $step) => ['step' => $step, 'label' => $label])
            ->values()
            ->all();
        $state = $this->publishState($round);
        $configured = $missing === [];

        return [
            'configured' => $configured,
            'missing' => $missing,
            'steps_done' => count(self::LABELS) - count($missing),
            'steps_total' => count(self::LABELS),
            'publish_state' => $state,
            // A draft round that is fully configured is ready for the owner to publish
            'can_publish' => $configured && $round->status === 'draft',
            'opens_on' => $round->open_date?->toDateString(),
            'closes_on' => $round->close_date?->toDateString(),
            'opens_at' => $round->opensAt()?->format(\App\Support\ProgramSchedule::JSON_FORMAT),
            'closes_at' => $round->closesAt()?->format(\App\Support\ProgramSchedule::JSON_FORMAT),
        ];
    }
}
