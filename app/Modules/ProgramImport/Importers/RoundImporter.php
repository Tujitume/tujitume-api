<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\Programs\Rounds\ProgramRound;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\ImportRowException;
use App\Modules\ProgramImport\Support\Criteria;
use App\Modules\ProgramImport\Support\Lifecycle;

class RoundImporter extends Importer
{
    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $p = $c->programFor($v);
        $n = (int) $v['round_number'];
        if ($n > (int) $p->total_rounds) {
            throw new ImportRowException("Round $n is more than the program's total rounds ({$p->total_rounds}).");
        }
        $status = $c->landing->roundStatus($n, $c->targetRound);

        $existing = $c->find('round', $row->external_ref, $p->id) ?? ProgramRound::where('program_id', $p->id)->where('round_number', $n)->value('id');
        if ($existing) {
            $r = ProgramRound::findOrFail($existing);
            if (! $c->find('round', $row->external_ref, $p->id)) {
                if (! $c->opts['link_existing']) {
                    throw new ImportRowException("Round $n already exists in this program. Turn on \"link existing\" to use it.");
                }
                $c->link('round', $r, $row->external_ref, $p->id);
                $c->record($r, 'linked');
            }
            if ($c->opts['on_duplicate'] === 'update' && $c->opts['update_existing'] && Lifecycle::allows('round', $r->status, $status)) {
                $c->updateModel($r, ['round_name' => $v['round_name']] + array_filter(['open_date' => $v['open_date'], 'close_date' => $v['close_date'], 'review_period_end' => $v['review_period_end'], 'announcement_date' => $v['announcement_date']]) + ['status' => $status]);
            }

            return ['round', $r->id];
        }

        $rubric = $v['rubric_mode'] ?? 'simple_total';
        $crit = Criteria::parse($v['scoring_criteria'] ?? [], $rubric);
        $r = ProgramRound::create([
            'program_id' => $p->id, 'round_name' => $v['round_name'], 'round_number' => $n,
            'open_date' => $v['open_date'], 'close_date' => $v['close_date'], 'review_period_end' => $v['review_period_end'], 'announcement_date' => $v['announcement_date'],
            'rubric_mode' => $rubric, 'scoring_criteria' => $crit['criteria'] ?: null,
            'knockout_questions' => array_map(fn ($q) => ['question' => trim(explode(':', $q)[0]), 'required_answer' => trim(explode(':', $q, 2)[1] ?? 'yes')], $v['knockout_questions'] ?? []) ?: null,
            'required_documents' => array_map(fn ($d) => ['label' => $d, 'required' => true], $v['required_documents'] ?? []) ?: null,
            'assignment_type' => $v['assignment_type'] ?? 'owner_only', 'assignment_method' => $v['assignment_method'] ?? 'manual',
            'advancement_mode' => $v['advancement_mode'] ?? 'manual', 'score_threshold' => $v['score_threshold'], 'max_advancing' => $v['max_advancing'],
            'tie_breaker_rule' => $v['tie_breaker_rule'], 'status' => $status,
        ]);
        $c->record($r, 'created', [], ['status' => $status]);
        $c->link('round', $r, $row->external_ref, $p->id);

        return ['round', $r->id];
    }
}
