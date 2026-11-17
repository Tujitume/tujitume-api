<?php

namespace App\Modules\ProgramImport\Landing;

class ReviewLanding extends BaseLanding
{
    public function startPoint(): string
    {
        return 'review';
    }

    public function program(bool $allMilestonesDone = false): array
    {
        // Decision Q-STATUS (assumed default): under_review. The scheduler only touches published/open programs.
        return ['status' => 'under_review', 'visible' => false];
    }

    /** Earlier rounds finalized; the target round is `published` because advanceManual/finalize read it (not `in_review`). */
    public function roundStatus(int $roundNumber, ?int $targetRound): string
    {
        if ($targetRound === null) {
            return 'finalized';
        }

        return match (true) {
            $roundNumber < $targetRound => 'finalized',
            $roundNumber === $targetRound => 'published',
            default => 'draft',
        };
    }

    public function application(int $roundNumber, bool $hasScore): array
    {
        // Round 1 is decided by accept/reject (accept needs status=pending). Rounds 2+ arrive already approved.
        return [
            'status' => $roundNumber === 1 ? 'pending' : 'approved',
            'round_status' => 'submitted',
            'knockout_status' => 'pending',
        ];
    }

    public function describe(): array
    {
        return [
            'program' => 'under_review, hidden',
            'rounds' => 'earlier rounds finalized; the target round published with past dates so it can be reviewed and finalized',
            'applications' => 'round 1: pending + submitted; round 2+: approved + submitted',
            'milestones' => 'none',
        ];
    }

    public function skipped(): array
    {
        return array_merge(parent::skipped(), ['The target round must have a review period end date in the past before Finalize works.']);
    }
}
