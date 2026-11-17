<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\Programs\ProgramCustomField;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\ImportRowException;
use App\Service\Program\CustomFields\CustomFieldService;
use App\Service\Program\CustomFields\CustomFieldValueService;
use Illuminate\Validation\ValidationException;

/**
 * Custom field definitions. Scoring is a program-owner decision: a scoring suggestion in the file is stored as a suggestion
 * and never switched on, so an import can never change how applicants are scored.
 */
class FieldImporter extends Importer
{
    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $p = $c->programFor($v);
        if ($id = $c->find('field', $row->external_ref, $p->id)) {
            return ['field', $id];
        }
        try {
            $field = app(CustomFieldService::class)->create($p, [
                'label' => $v['label'], 'type' => $v['type'], 'audience' => $v['audience'] ?? 'application', 'is_required' => (bool) ($v['is_required'] ?? false),
                'options' => $v['options'] ?: null, 'help_text' => $v['help_text'] ?? null,
            ]);
        } catch (ValidationException $e) {
            throw new ImportRowException(collect($e->errors())->flatten()->first());
        }
        $suggestion = $this->suggestion($v);
        $suggestion && $field->update(['scoring_suggestion' => $suggestion]);
        $c->record($field, 'created');
        $c->link('field', $field, $row->external_ref, $p->id);
        if ($field->audience === 'info' && ! empty($v['info_value'])) {
            $value = app(CustomFieldValueService::class)->store($p, $field, 'program', $p->id, $v['info_value']);
            $c->record($value, 'created');
        }

        return ['field', $field->id];
    }

    private function suggestion(array $v): ?array
    {
        if (empty($v['scoring_match_type'])) {
            return null;
        }
        $target = trim((string) ($v['scoring_target'] ?? ''));
        $t = match ($v['scoring_match_type']) {
            'in_set' => array_values(array_filter(array_map('trim', preg_split('/[;|,]/', $target)))),
            'range' => $this->range($target),
            'equals' => in_array(strtolower($target), ['true', 'yes', '1'], true),
            default => null,
        };

        return ['match_type' => $v['scoring_match_type'], 'weight' => $v['scoring_weight'] ?? null, 'target' => $t, 'note' => 'Suggested by the import. Not active until you enable it.'];
    }

    private function range(string $s): array
    {
        [$min, $max] = array_pad(explode('..', $s, 2), 2, null);

        return array_filter(['min' => is_numeric($min) ? $min + 0 : null, 'max' => is_numeric($max) ? $max + 0 : null], fn ($x) => $x !== null);
    }
}
