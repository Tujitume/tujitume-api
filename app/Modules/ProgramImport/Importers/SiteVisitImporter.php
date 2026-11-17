<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\Auth\User;
use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\ImportRowException;

/**
 * Site visits that already happened, stored as completed records. No reviewer order, no fee, no payment, no notification:
 * nothing is assigned or scheduled. The visitor is the matching existing account, otherwise the program owner (record only).
 */
class SiteVisitImporter extends Importer
{
    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $cp = MECheckpoint::findOrFail($c->findOrFail('checkpoint', $v['checkpoint_ref']));
        if (MESiteVisit::where('checkpoint_id', $cp->id)->where('start_date', $v['start_date'])->exists()) {
            throw new ImportRowException('A site visit on this date already exists for the checkpoint.');
        }
        $visitor = ! empty($v['reviewer_email']) ? User::where('email', $v['reviewer_email'])->first() : null;
        $visit = MESiteVisit::create([
            'checkpoint_id' => $cp->id, 'app_id' => $cp->app_id, 'reviewer_id' => $visitor?->id ?? $c->owner()->id,
            'inspector' => $v['inspector'] ?? ($visitor ? trim($visitor->first_name.' '.$visitor->last_name) : null), 'start_date' => $v['start_date'], 'assign_type' => 'internal',
            'location' => $v['location'], 'objective' => $v['objective'], 'observed_actions' => $v['observed_actions'], 'evidence_found' => $v['evidence_found'],
            'risk_notes' => $v['risk_notes'], 'recommendation_notes' => $v['recommendation_notes'], 'visit_comments' => $v['visit_comments'], 'status' => 'completed',
        ]);
        $c->record($visit, 'created');

        return ['site_visit', $visit->id];
    }
}
