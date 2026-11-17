<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\ImportRowException;

class QuestionImporter extends Importer
{
    private const TYPES = ['text' => 'short_answer', 'paragraph' => 'long_text', 'choice' => 'multiple_choice', 'checkbox' => 'checkbox', 'scale' => 'linear_scale', 'date' => 'date'];

    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $roundId = $c->findOrFail('round', $v['round_ref']);
        $pid = \App\Models\Programs\Rounds\ProgramRound::whereKey($roundId)->value('program_id');
        if ($id = $c->find('question', $row->external_ref, $pid)) {
            return ['question', $id];
        }
        $type = self::TYPES[$v['question_type']] ?? throw new ImportRowException('Unknown question type.');
        if (in_array($type, ['multiple_choice', 'checkbox'], true) && empty($v['options'])) {
            throw new ImportRowException('Choice questions need options.');
        }
        $q = RoundCustomQuestion::create([
            'round_id' => $roundId, 'question_text' => mb_substr($v['question_text'], 0, 500), 'question_type' => $type,
            'options' => $v['options'] ?: null, 'is_required' => (bool) ($v['is_required'] ?? false),
            'display_order' => RoundCustomQuestion::where('round_id', $roundId)->count() + 1,
        ]);
        $c->record($q, 'created');
        $c->link('question', $q, $row->external_ref, $pid);

        return ['question', $q->id];
    }
}
