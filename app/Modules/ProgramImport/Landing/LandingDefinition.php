<?php

namespace App\Modules\ProgramImport\Landing;

/**
 * Owns the lifecycle values an import writes for one start point. Values were read from the real
 * controllers (accept, finalize, advanceManual, activateTemplates, ScoreAverager); see docs/program-import/DISCOVERY.md.
 */
interface LandingDefinition
{
    public function startPoint(): string;

    /** Program attributes to write on a new program. */
    public function program(bool $allMilestonesDone = false): array;

    /** Round status given its number relative to the target round (null target = no live round). */
    public function roundStatus(int $roundNumber, ?int $targetRound): string;

    /** Application attributes for one imported application in $roundNumber. */
    public function application(int $roundNumber, bool $hasScore): array;

    /** Milestone attributes. $position is 1-based sequence among the application's milestones. */
    public function milestone(int $position): array;

    /** Human-readable description for the readiness preview. */
    public function describe(): array;

    /** What is skipped and the consequence, shown to the user. */
    public function skipped(): array;
}
