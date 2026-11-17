<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\Programs\Program;
use App\Models\Programs\ProgramWallet;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Support\Lifecycle;

class ProgramImporter extends Importer
{
    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $ref = $row->external_ref;
        $land = $c->landing->program();

        if ($id = $c->find('program', $ref, null)) {
            $p = Program::findOrFail($id);
            $c->program ??= $p;
            if ($c->opts['on_duplicate'] === 'update') {
                $changes = array_filter([
                    'program_title' => $v['program_title'], 'program_description' => $v['program_description'] ?? null,
                    'total_program_amount' => $v['total_program_amount'], 'funding_per_business' => $v['funding_per_business'],
                    'application_start_date' => $v['application_start_date'], 'application_deadline' => $v['application_deadline'],
                ], fn ($x) => $x !== null);
                $c->updateModel($p, $changes);
            }

            return ['program', $p->id];
        }

        $attrs = [
            'user_id' => $c->owner()->id, 'organization_id' => $c->organizationId(),
            'program_industry_id' => $v['program_industry_id'], 'program_title' => $v['program_title'],
            'program_description' => $v['program_description'] ?? null,
            'total_program_amount' => $v['total_program_amount'], 'available_amount' => $v['total_program_amount'],
            'funding_per_business' => $v['funding_per_business'], 'disbursement_type' => $v['disbursement_type'],
            'program_type' => $v['program_type'], 'total_rounds' => $v['program_type'] === 'single_round' ? 1 : (int) ($v['total_rounds'] ?? 1),
            'max_awardees' => $v['max_awardees'] ?? (int) floor($v['total_program_amount'] / max($v['funding_per_business'], 1)),
            'currency' => $v['currency'], 'application_start_date' => $v['application_start_date'], 'application_deadline' => $v['application_deadline'],
            'require_kyc_verification_to_apply' => (bool) ($v['require_kyc_verification_to_apply'] ?? false),
            'match_score_limit' => $v['match_score_limit'] ?? null,
        ] + $land;
        $p = Program::create($attrs);
        // Same empty, inactive wallet a manually created program gets. Nothing is funded.
        $wallet = ProgramWallet::create(['program_id' => $p->id, 'total_deposited' => 0, 'total_disbursed' => 0, 'total_reserved' => 0, 'balance' => 0, 'status' => 'inactive', 'currency' => $p->currency]);
        $c->record($p, 'created', [], ['status' => $p->status]);
        $c->record($wallet, 'created');
        $c->link('program', $p, $ref);
        $c->program ??= $p;
        $c->touchedPrograms[$p->id] = true;

        return ['program', $p->id];
    }

    /** Move touched programs forward to the landing status, never backward. */
    public function finish(CommitContext $c): void
    {
        foreach (array_keys($c->touchedPrograms) as $id) {
            $p = Program::find($id);
            $all = $c->landing->program($c->allMilestonesDone ?? false);
            if ($p && Lifecycle::rank('program', $all['status']) > Lifecycle::rank('program', $p->status)) {
                $c->updateModel($p, ['status' => $all['status']]);
            }
        }
    }
}
