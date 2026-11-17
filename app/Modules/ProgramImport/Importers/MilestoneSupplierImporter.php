<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\Programs\MilestoneSupplier;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\ImportRowException;

class MilestoneSupplierImporter extends Importer
{
    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $m = ProgramMilestone::findOrFail($c->findOrFail('milestone', $v['milestone_ref']));
        $supplierId = $c->findOrFail('supplier', $v['supplier_ref']);
        $program = ProgramApplication::find($m->app_id)->program;
        if (MilestoneSupplier::where('milestone_id', $m->id)->where('supplier_id', $supplierId)->exists()) {
            throw new ImportRowException('This supplier is already assigned to the milestone.');
        }
        $route = match ($program->disbursement_type) { 'beneficiary' => 'direct_to_applicant', 'hybrid' => 'split', default => 'direct_to_supplier' };
        $a = MilestoneSupplier::create([
            'milestone_id' => $m->id, 'supplier_id' => $supplierId, 'assignment_type' => 'approved', 'is_locked' => false,
            'payment_route' => $route, 'quoted_amount' => $v['amount'], 'added_by' => 'program_owner',
        ]);
        $c->record($a, 'created');

        return ['milestone_supplier', $a->id];
    }
}
