<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\ProgramApplication;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\ImportRowException;

/** M&E checkpoints are created unsent (sent_at null): the owner decides when applicants hear about them. */
class CheckpointImporter extends Importer
{
    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $app = ProgramApplication::findOrFail($c->findOrFail('application', $v['application_ref']));
        if (! in_array($app->status, ['awarded', 'completed'], true)) {
            throw new ImportRowException('Checkpoints can only be added to awarded applications.');
        }
        if ($id = $c->find('checkpoint', $row->external_ref, $app->program_id)) {
            return ['checkpoint', $id];
        }
        $cp = MECheckpoint::create([
            'app_id' => $app->id, 'program_id' => $app->program_id, 'checkpoint_name' => mb_substr($v['checkpoint_name'], 0, 255), 'type' => mb_substr($v['type'], 0, 40),
            'due_date' => $v['due_date'], 'requirement' => $v['requirement'],
            'kpis_to_track' => array_map(fn ($k) => ['name' => $k, 'unit' => null, 'target' => null], $v['kpis_to_track'] ?? []) ?: null,
            'evidence_required' => $v['evidence_required'] ?: null, 'require_site_visit' => (bool) ($v['require_site_visit'] ?? false),
            'status' => 'pending', 'should_notify_applicant' => false, 'sent_at' => ! empty($c->opts['mark_checkpoints_sent']) ? now() : null, 'respondent' => 'applicant',
        ]);
        $c->record($cp, 'created');
        $c->link('checkpoint', $cp, $row->external_ref, $app->program_id);

        return ['checkpoint', $cp->id];
    }
}
