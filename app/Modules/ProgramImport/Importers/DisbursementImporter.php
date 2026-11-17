<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\Programs\Disbursement;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;

/** Historic payments: recorded as completed, never sent. No wallet, escrow, LIPR or Stripe call. */
class DisbursementImporter extends Importer
{
    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $m = ProgramMilestone::findOrFail($c->findOrFail('milestone', $v['milestone_ref']));
        $program = Program::find(ProgramApplication::find($m->app_id)->program_id);
        $supplier = $c->find('supplier', $v['supplier_ref'] ?? null, null);
        $when = $v['disbursed_at'].' 00:00:00';
        $d = Disbursement::create([
            'milestone_id' => $m->id, 'wallet_id' => null, 'supplier_id' => $supplier, 'recipient_type' => $v['recipient_type'] ?? 'supplier',
            'amount' => $v['amount'], 'currency' => $program->currency, 'payment_method' => $v['payment_method'] ?? 'other',
            'payment_reference' => $v['payment_reference'], 'disbursed_at' => $when, 'status' => 'completed', 'supplier_confirmed' => true,
            'beneficiary_payment_justification' => ($v['recipient_type'] ?? 'supplier') === 'business_owner' ? 'Imported historic payment' : null,
            'authorized_by' => ($v['recipient_type'] ?? 'supplier') === 'business_owner' ? $c->owner()->id : null,
        ]);
        $c->record($d, 'created');
        if ($c->opts['adjust_program_balance']) {
            $c->updateModel($program, ['available_amount' => max(0, (float) $program->available_amount - (float) $v['amount'])]);
        }

        return ['disbursement', $d->id];
    }
}
