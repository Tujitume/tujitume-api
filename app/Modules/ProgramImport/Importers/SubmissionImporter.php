<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\Monitoring\MESubmission;
use App\Models\Programs\ProgramApplication;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\ImportRowException;

/** Historic checkpoint submissions, stored as verified. */
class SubmissionImporter extends Importer
{
    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $cp = MECheckpoint::findOrFail($c->findOrFail('checkpoint', $v['checkpoint_ref']));
        if (MESubmission::where('checkpoint_id', $cp->id)->exists()) {
            throw new ImportRowException('This checkpoint already has a submission.');
        }
        $app = ProgramApplication::find($cp->app_id);
        $kpis = [];
        foreach ($v['kpi_actual_values'] ?? [] as $pair) {
            [$k, $val] = array_pad(explode(':', $pair, 2), 2, null);
            if ($k !== null && $val !== null) {
                $kpis[trim($k)] = trim($val);
            }
        }
        $at = ($v['submitted_at'] ?? now()->toDateString()).' 00:00:00';
        $s = MESubmission::create([
            'checkpoint_id' => $cp->id, 'app_id' => $cp->app_id, 'submitted_by' => $app->user_id ?? $c->owner()->id, 'written_report' => $v['written_report'],
            'kpi_actual_values' => $kpis ?: null, 'status' => 'verified', 'reviewed_by' => $c->owner()->id, 'reviewed_at' => $at, 'submitted_at' => $at,
        ]);
        $c->record($s, 'created');
        $c->updateModel($cp, ['status' => 'verified']);

        return ['submission', $s->id];
    }
}
