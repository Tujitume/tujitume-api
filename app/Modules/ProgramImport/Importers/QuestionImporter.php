<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\ImportRowException;

class QuestionImporter extends Importer
{
    /** The question types the round setup offers (data/questionTypes.js in the frontend). */
    public const APP_TYPES = ['short_answer', 'long_text', 'multiple_choice', 'checkbox', 'dropdown', 'yes_no', 'knockout', 'paragraph', 'true_false', 'rating',
        'linear_scale', 'number', 'email', 'phone', 'url', 'video_link', 'date', 'time', 'datetime', 'file_upload', 'address', 'location', 'currency',
        'percentage', 'matrix', 'ranking', 'signature'];

    /** Older or everyday names still accepted in files. */
    public const ALIASES = ['text' => 'short_answer', 'short_text' => 'short_answer', 'long_answer' => 'long_text', 'choice' => 'multiple_choice', 'radio' => 'multiple_choice',
        'checkboxes' => 'checkbox', 'select' => 'dropdown', 'scale' => 'linear_scale', 'rating_scale' => 'rating', 'phone_number' => 'phone', 'website' => 'url',
        'url_website' => 'url', 'link' => 'url', 'video' => 'video_link', 'file' => 'file_upload', 'upload' => 'file_upload', 'date_time' => 'datetime',
        'currency_money' => 'currency', 'matrix_grid' => 'matrix', 'knockout_yes_no' => 'knockout'];

    public const OPTION_TYPES = ['multiple_choice', 'checkbox', 'dropdown', 'ranking'];

    public static function appType(?string $type): ?string
    {
        $t = strtolower(trim((string) $type));
        $t = preg_replace('/[\s-]+/', '_', $t);

        return in_array($t, self::APP_TYPES, true) ? $t : (self::ALIASES[$t] ?? null);
    }

    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $roundId = $c->findOrFail('round', $v['round_ref']);
        $pid = \App\Models\Programs\Rounds\ProgramRound::whereKey($roundId)->value('program_id');
        if ($id = $c->find('question', $row->external_ref, $pid)) {
            return ['question', $id];
        }
        $type = self::appType($v['question_type']) ?? throw new ImportRowException('Unknown question type.');
        if (in_array($type, self::OPTION_TYPES, true) && empty($v['options'])) {
            throw new ImportRowException('Choice questions need options.');
        }
        $q = RoundCustomQuestion::create([
            'round_id' => $roundId, 'question_text' => self::text($v['question_text'], $v['help_text'] ?? null), 'question_type' => $type,
            'options' => $v['options'] ?: null, 'is_required' => (bool) ($v['is_required'] ?? false),
            'display_order' => RoundCustomQuestion::where('round_id', $roundId)->count() + 1,
        ]);
        $c->record($q, 'created');
        $c->link('question', $q, $row->external_ref, $pid);

        return ['question', $q->id];
    }

    /** Questions have no separate help field, so help text (e.g. "a) … b) … (500 characters max)") is kept under the title. */
    public static function text(string $title, ?string $help): string
    {
        $title = trim($title);
        $help = trim((string) $help);
        $full = $help !== '' && ! str_contains($title, $help) ? $title."\n".$help : $title;

        return mb_substr($full, 0, 500);
    }
}
