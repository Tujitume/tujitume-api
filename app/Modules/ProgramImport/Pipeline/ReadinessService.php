<?php

namespace App\Modules\ProgramImport\Pipeline;

use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Readiness\ReadinessChecker;
use App\Modules\ProgramImport\Registry\ImportTypeRegistry;
use App\Modules\ProgramImport\Support\Refs;
use App\Models\Programs\Program;

class ReadinessService
{
    public static function startPoint(ImportBatch $b): string
    {
        return $b->start_point ?: (Refs::DEFAULT_START[$b->type] ?? 'setup');
    }

    /** Applications lacking an answer to a required question of their round (counted across the batch's own questions). */
    private function missingRequiredAnswers(array $sheets): int
    {
        $roundNumber = collect($sheets['rounds'] ?? [])->mapWithKeys(fn ($r) => [$r['round_ref'] ?? '' => (int) $r['round_number']]);
        $customRequired = collect($sheets['fields'] ?? [])->filter(fn ($f) => ! empty($f['is_required']) && ($f['audience'] ?? 'application') === 'application')->pluck('field_ref');
        $required = collect($sheets['questions'] ?? [])->filter(fn ($q) => ! empty($q['is_required']))->map(fn ($q) => ['ref' => $q['question_ref'], 'round' => $roundNumber[$q['round_ref'] ?? ''] ?? null]);
        if ($required->isEmpty() && $customRequired->isEmpty()) {
            return 0;
        }
        $n = 0;
        foreach ($sheets['applications'] ?? [] as $a) {
            $given = array_filter($a['_custom'] ?? [], fn ($x) => $x !== null && trim((string) $x) !== '');
            if ($customRequired->contains(fn ($ref) => ! array_key_exists($ref, $given))) {
                $n++;

                continue;
            }
            $answers = array_filter($a['_answers'] ?? [], fn ($x) => $x !== null && trim((string) $x) !== '');
            if ($required->contains(fn ($q) => ($q['round'] === null || $q['round'] === (int) ($a['round_number'] ?? 1)) && ! array_key_exists($q['ref'], $answers))) {
                $n++;
            }
        }

        return $n;
    }

    public function run(ImportBatch $batch): array
    {
        $opts = BatchValidator::options($batch);
        $sheets = [];
        foreach (ImportTypeRegistry::ORDER as $sheet) {
            $rows = ImportRow::where('batch_id', $batch->id)->where('sheet', $sheet)->whereIn('status', ['valid'])->orderBy('id')->get()->pluck('normalized')->all();
            if ($rows) {
                $sheets[$sheet] = $rows;
            }
        }
        $existing = ['already_imported' => [], 'invalid' => []];
        foreach (ImportRow::where('batch_id', $batch->id)->whereIn('status', ['skipped', 'invalid'])->selectRaw('sheet, status, count(*) n')->groupBy('sheet', 'status')->get() as $c) {
            $existing[$c->status === 'skipped' ? 'already_imported' : 'invalid'][$c->sheet] = (int) $c->n;
        }
        if ($batch->program_id && $p = Program::find($batch->program_id)) {
            $existing += ['program' => $p->only(['total_program_amount', 'funding_per_business', 'currency', 'disbursement_type', 'program_type', 'total_rounds', 'max_awardees', 'application_start_date', 'application_deadline']),
                'round_numbers' => $p->rounds()->pluck('round_number')->all()];
            $existing['program']['application_start_date'] = optional($p->application_start_date)->toDateString();
            $existing['program']['application_deadline'] = optional($p->application_deadline)->toDateString();
        }
        $sp = self::startPoint($batch);
        $missingAnswers = $this->missingRequiredAnswers($sheets);
        $res = (new ReadinessChecker)->check($sp, $sheets, $opts + ['piecemeal' => ! $batch->start_point || ImportTypeRegistry::has($batch->type)], $existing);

        // Readiness sees only valid normalized rows. Explain when a required sheet
        // was uploaded but its rows were rejected during mapping/validation, rather
        // than incorrectly telling the user that the sheet is absent.
        foreach ($res['blockers'] as &$blocker) {
            if (($blocker['code'] ?? null) !== 'missing_sheet' || empty($blocker['sheet'])) {
                continue;
            }

            $sheet = $blocker['sheet'];
            $stagedCount = ImportRow::where('batch_id', $batch->id)->where('sheet', $sheet)->count();
            $invalidRows = ImportRow::where('batch_id', $batch->id)->where('sheet', $sheet)->where('status', 'invalid')->orderBy('row_number')->get(['row_number', 'errors']);

            if ($stagedCount > 0 && $invalidRows->count() === $stagedCount) {
                $blocker['code'] = 'invalid_required_sheet';
                $blocker['message'] = "The \"$sheet\" sheet was found, but all {$stagedCount} row(s) failed validation. Correct the column mapping or row values, then validate again.";
                $blocker['row_errors'] = $invalidRows->take(10)->map(fn (ImportRow $row) => [
                    'row' => $row->row_number,
                    'errors' => $row->errors ?? [],
                ])->values()->all();
            } elseif ($stagedCount === 0) {
                $detected = array_values(array_filter(array_keys($batch->stats['sheets'] ?? []), fn ($name) => $name !== '_ignored'));
                $blocker['message'] = $detected
                    ? "No valid \"$sheet\" data was found. The workbook has these recognized sheets: ".implode(', ', $detected).'. Check the sheet tabs and choose the matching start point.'
                    : "No valid \"$sheet\" data was found in the uploaded file. Check that it contains the required sheet and header row.";
            }
        }
        unset($blocker);

        if ($missingAnswers > 0) {
            // Q-CUSTOM default: manual applications must answer required questions; imported history may not have them, so this warns.
            $res['warnings'][] = ['code' => 'required_answers_missing', 'message' => "$missingAnswers application(s) have no answer to a required question. They are imported as they are."];
        }
        $invalid = ImportRow::where('batch_id', $batch->id)->where('status', 'invalid')->count();
        if ($invalid) {
            if ($opts['mode'] === 'all_or_nothing') {
                $res['blockers'][] = ['code' => 'invalid_rows', 'message' => "$invalid row(s) have errors. Fix them, or switch to partial mode."];
                $res['ready'] = false;
            } else {
                $res['warnings'][] = ['code' => 'invalid_rows', 'message' => "$invalid row(s) have errors and will be skipped."];
            }
        }
        $batch->update(['readiness' => $res, 'landing_preview' => $res['landing_preview']]);

        return $res;
    }
}
