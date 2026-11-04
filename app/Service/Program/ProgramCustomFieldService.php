<?php

namespace App\Service\Program;

use App\Models\Programs\Program;
use App\Models\Programs\ProgramCustomField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProgramCustomFieldService
{
    public function createForProgram(Program $program, array $definitions = []): array
    {
        $created = [];
        $activeCount = $program->customFields()->where('is_archived', false)->count();
        $maxActive = (int) config('program_fields.max_active_fields_per_program', 50);

        foreach ($definitions as $index => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            if ($activeCount + count($created) >= $maxActive) {
                throw ValidationException::withMessages([
                    'custom_fields' => ['You cannot add more than ' . $maxActive . ' active custom fields to a program.'],
                ]);
            }

            $definition = $this->validateDefinition($definition);
            $label = trim((string) ($definition['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $baseKey = Str::slug($label, '-');
            $key = $baseKey;
            $suffix = 1;
            while ($program->customFields()->where('key', $key)->exists()) {
                $key = $baseKey.'-'.$suffix;
                $suffix++;
            }

            $field = ProgramCustomField::create([
                'program_id' => $program->id,
                'key' => $key,
                'label' => $label,
                'help_text' => $definition['help_text'] ?? null,
                'audience' => $definition['audience'] ?? 'application',
                'type' => $definition['type'] ?? 'text',
                'is_required' => (bool) ($definition['is_required'] ?? false),
                'options' => $definition['options'] ?? null,
                'validation' => $definition['validation'] ?? null,
                'is_scoring' => (bool) ($definition['is_scoring'] ?? false),
                'scoring' => $definition['scoring'] ?? null,
                'is_bonus' => (bool) ($definition['is_bonus'] ?? false),
                'display_order' => $definition['display_order'] ?? ($index + 1),
                'visibility' => $definition['visibility'] ?? 'public',
                'is_archived' => (bool) ($definition['is_archived'] ?? false),
                'created_by' => auth()->id(),
            ]);

            $created[] = $field;
        }

        return $created;
    }

    protected function validateDefinition(array $definition): array
    {
        $rules = [
            'label' => ['required', 'string', 'max:' . config('program_fields.label_max_length', 150)],
            'type' => ['required', 'in:' . implode(',', config('program_fields.allowed_field_types', ['text']))],
            'audience' => ['nullable', 'in:application,info'],
            'help_text' => ['nullable', 'string'],
            'is_required' => ['nullable', 'boolean'],
            'options' => ['nullable', 'array'],
            'validation' => ['nullable', 'array'],
            'is_scoring' => ['nullable', 'boolean'],
            'scoring' => ['nullable', 'array'],
            'is_bonus' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer'],
            'visibility' => ['nullable', 'in:public,owner_only'],
        ];

        $validator = Validator::make($definition, $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $audience = $definition['audience'] ?? 'application';
        $type = $definition['type'] ?? 'text';
        $isScoring = (bool) ($definition['is_scoring'] ?? false);
        if ($isScoring && $audience !== 'application') {
            throw ValidationException::withMessages([
                'is_scoring' => ['Only application fields can be configured as scoring fields.'],
            ]);
        }

        if ($isScoring && in_array($type, ['text', 'textarea', 'url'], true) && (($definition['scoring']['match_type'] ?? 'provided') !== 'provided')) {
            throw ValidationException::withMessages([
                'scoring.match_type' => ['Text, textarea, and URL fields may only use the provided match type when scoring is enabled.'],
            ]);
        }

        if (($definition['options'] ?? null) !== null && count((array) $definition['options']) > (int) config('program_fields.max_options_per_field', 100)) {
            throw ValidationException::withMessages([
                'options' => ['A field cannot have more than ' . config('program_fields.max_options_per_field', 100) . ' options.'],
            ]);
        }

        return $definition;
    }
}
