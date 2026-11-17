<?php

namespace App\Service\Program\CustomFields;

use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramCustomField;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Creates and changes a program's custom field definitions, enforcing the lock rules and the scoring matrix. */
class CustomFieldService
{
    private const SCORING_TYPES = [
        'in_set' => ['select', 'multi_select'], 'range' => ['number', 'date'], 'equals' => ['boolean'],
        'provided' => ['text', 'textarea', 'number', 'boolean', 'date', 'select', 'multi_select', 'file', 'url'],
    ];

    public function create(Program $program, array $data): ProgramCustomField
    {
        $data = $this->normalize($data);
        $active = $program->customFields()->where('is_archived', false)->count();
        if ($active >= config('program_fields.max_active_fields')) {
            $this->fail('label', 'A program can have at most '.config('program_fields.max_active_fields').' active fields.');
        }
        $this->validateDefinition($data);

        return $program->customFields()->create($data + [
            'key' => $this->uniqueKey($program, $data['label']),
            'display_order' => (int) $program->customFields()->max('display_order') + 1,
        ]);
    }

    public function update(ProgramCustomField $field, array $data): ProgramCustomField
    {
        $always = ['label', 'help_text', 'display_order', 'is_public'];
        $locked = array_diff(array_keys($data), $always);
        $hasApplications = ProgramApplication::where('program_id', $field->program_id)->where('status', '!=', 'draft')->exists();
        if ($hasApplications && $locked) {
            // Once applications exist only appending options is allowed (never removing or changing what answers may refer to)
            $onlyOptions = array_values($locked) === ['options'] && in_array($field->type, ['select', 'multi_select'], true)
                && ! array_diff((array) $field->options, (array) $data['options']);
            if (! $onlyOptions) {
                $this->fail('type', 'This field has applications. You can change its label, help text, order and visibility, or add options.');
            }
        }
        $merged = $this->normalize(array_merge($field->only(['type', 'audience', 'options', 'validation', 'is_required', 'is_scoring', 'scoring', 'is_bonus']), $data));
        $this->validateDefinition($merged + ['label' => $data['label'] ?? $field->label]);
        $field->update(array_intersect_key($merged + ['label' => $data['label'] ?? $field->label, 'help_text' => $data['help_text'] ?? $field->help_text, 'display_order' => $data['display_order'] ?? $field->display_order, 'is_public' => $data['is_public'] ?? $field->is_public], array_flip(array_merge($always, array_keys($data)))));

        return $field->refresh();
    }

    /** Hard delete when nothing refers to it, otherwise archive (kept on historic applications, gone from the form and the engine). */
    public function delete(ProgramCustomField $field): string
    {
        if ($field->values()->exists()) {
            $field->update(['is_archived' => true, 'is_scoring' => false]);

            return 'archived';
        }
        $field->delete();

        return 'deleted';
    }

    public function reorder(Program $program, array $ids): void
    {
        $own = $program->customFields()->pluck('id')->all();
        foreach ($ids as $i => $id) {
            if (in_array((int) $id, $own, true)) {
                ProgramCustomField::where('id', $id)->update(['display_order' => $i + 1]);
            }
        }
    }

    private function normalize(array $d): array
    {
        $d['audience'] ??= 'application';
        foreach (['is_required', 'is_scoring', 'is_bonus', 'is_public'] as $k) {
            isset($d[$k]) && $d[$k] = (bool) $d[$k];
        }
        if (array_key_exists('label', $d)) {
            $d['label'] = trim(strip_tags((string) $d['label']));
        }
        if (array_key_exists('help_text', $d) && $d['help_text'] !== null) {
            $d['help_text'] = trim(strip_tags((string) $d['help_text']));
        }
        if (! empty($d['options'])) {
            $d['options'] = array_values(array_unique(array_map(fn ($o) => trim(strip_tags((string) $o)), (array) $d['options'])));
        }
        if (($d['audience'] ?? '') === 'info') {
            $d['is_scoring'] = false;
            $d['is_bonus'] = false;
            $d['scoring'] = null;
        }

        return $d;
    }

    private function validateDefinition(array $d): void
    {
        if (empty($d['label']) || mb_strlen($d['label']) > config('program_fields.label_max')) {
            $this->fail('label', 'A label of up to '.config('program_fields.label_max').' characters is required.');
        }
        if (! in_array($d['type'] ?? null, config('program_fields.types'), true)) {
            $this->fail('type', 'Unknown field type.');
        }
        if (! in_array($d['audience'], config('program_fields.audiences'), true)) {
            $this->fail('audience', 'Audience must be application or info.');
        }
        $isChoice = in_array($d['type'], ['select', 'multi_select'], true);
        $options = (array) ($d['options'] ?? []);
        if ($isChoice && (count($options) < 1 || count($options) > config('program_fields.max_options') || in_array('', $options, true))) {
            $this->fail('options', 'A choice field needs 1 to '.config('program_fields.max_options').' non-empty options.');
        }
        if (! $isChoice && $options) {
            $this->fail('options', 'Only select and multi_select fields have options.');
        }
        if (! empty($d['is_scoring'])) {
            $this->validateScoring($d, $options);
        }
        if (! empty($d['is_bonus']) && empty($d['is_scoring'])) {
            $this->fail('is_bonus', 'A bonus field must also be a scoring field.');
        }
    }

    private function validateScoring(array $d, array $options): void
    {
        $s = (array) ($d['scoring'] ?? []);
        $type = $s['match_type'] ?? null;
        if (! isset(self::SCORING_TYPES[$type]) || ! in_array($d['type'], self::SCORING_TYPES[$type], true)) {
            $this->fail('scoring.match_type', 'This match type does not fit a '.$d['type'].' field.');
        }
        $w = $s['weight'] ?? null;
        if (! is_numeric($w) || $w <= 0 || $w > 1000) {
            $this->fail('scoring.weight', 'Weight must be greater than 0 and at most 1000.');
        }
        $t = $s['target'] ?? null;
        if ($type === 'in_set' && (! is_array($t) || ! $t || array_diff(array_map('strval', $t), $options))) {
            $this->fail('scoring.target', 'The target must be a non-empty list of the field\'s options.');
        }
        if ($type === 'in_set' && $d['type'] === 'multi_select' && ! in_array($s['mode'] ?? 'any', ['any', 'proportional', 'all'], true)) {
            $this->fail('scoring.mode', 'Mode must be any, proportional or all.');
        }
        if ($type === 'range') {
            $min = $t['min'] ?? null;
            $max = $t['max'] ?? null;
            if (! is_array($t) || ($min === null && $max === null) || ($min !== null && $max !== null && $min > $max)) {
                $this->fail('scoring.target', 'A range needs a min and/or a max, with min not above max.');
            }
        }
        if ($type === 'equals' && ! is_bool($t)) {
            $this->fail('scoring.target', 'The target must be true or false.');
        }
    }

    private function uniqueKey(Program $program, string $label): string
    {
        $base = Str::slug($label, '_') ?: 'field';
        $base = mb_substr($base, 0, 56);
        $key = $base;
        $i = 2;
        while ($program->customFields()->where('key', $key)->exists()) {
            $key = $base.'_'.$i++;
        }

        return $key;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
