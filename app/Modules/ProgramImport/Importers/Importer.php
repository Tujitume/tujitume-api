<?php

namespace App\Modules\ProgramImport\Importers;

use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;

abstract class Importer
{
    /** Write one staged row. Returns [target_type, target_id]. Runs inside a per-row transaction. */
    abstract public function import(ImportRow $row, CommitContext $c): array;

    /** Called once after every row of this sheet is written (derived data, unlocking, totals). */
    public function finish(CommitContext $c): void {}

    public static function for(string $sheet): self
    {
        return match ($sheet) {
            'program' => new ProgramImporter, 'rounds' => new RoundImporter, 'questions' => new QuestionImporter, 'fields' => new FieldImporter, 'reviewers' => new ReviewerImporter, 'site_visits' => new SiteVisitImporter,
            'applications' => new ApplicationImporter, 'scores' => new ScoreImporter, 'winners' => new WinnerImporter,
            'suppliers' => new SupplierImporter, 'milestones' => new MilestoneImporter, 'milestone_suppliers' => new MilestoneSupplierImporter,
            'disbursements' => new DisbursementImporter, 'checkpoints' => new CheckpointImporter, 'submissions' => new SubmissionImporter,
        };
    }
}
