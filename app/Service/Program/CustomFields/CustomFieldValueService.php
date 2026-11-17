<?php

namespace App\Service\Program\CustomFields;

use App\Models\Programs\Program;
use App\Models\Programs\ProgramCustomField;
use App\Models\Programs\ProgramCustomFieldValue;
use App\Service\File\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** Validates and stores answers (applications) and info values (programs) in typed columns. */
class CustomFieldValueService
{
    private static bool $tablesFound = false;

    /**
     * Whether the custom-fields tables exist yet. Until the migration has run, every program page, application and match
     * request must behave as if the program simply had no custom fields, never fail because a table is missing.
     * (Only "found" is remembered, so the feature switches on as soon as the migration has run.)
     */
    public static function available(): bool
    {
        return self::$tablesFound = self::$tablesFound
            || (Schema::hasTable('program_custom_fields') && Schema::hasTable('program_custom_field_values'));
    }

    /** Adds `application_fields` and `info` (public view only: never scoring, weights or targets) to a program about to be shown. */
    public function attachPublicFields(Program $program): void
    {
        if (! self::available()) {
            $program->setAttribute('application_fields', []);
            $program->setAttribute('info', []);

            return;
        }

        $fields = $program->customFields()->where('is_archived', false)->where('is_public', true)->get();
        $info = $this->answersFor('program', $program->id, $fields->where('audience', 'info'));
        $program->setAttribute('application_fields', $fields->where('audience', 'application')->map(fn ($f) => $f->publicView())->values()->all());
        $program->setAttribute('info', $fields->where('audience', 'info')->map(fn ($f) => $f->publicView() + ['value' => $f->type === 'file' ? null : ($info[$f->key] ?? null), 'has_file' => $f->type === 'file' && isset($info[$f->key])])->values()->all());
    }

    /** @return Collection<int, ProgramCustomField> the fields an applicant answers */
    public function applicationFields(Program $program): Collection
    {
        if (! self::available()) {
            return collect();
        }

        return $program->customFields()->where('audience', 'application')->where('is_archived', false)->get();
    }

    /** Laravel rules for `custom_answers.{key}`, built from the stored definitions (options are never trusted from the client). */
    public function rules(Collection $fields, string $prefix = 'custom_answers'): array
    {
        $rules = [];
        foreach ($fields as $f) {
            $v = (array) $f->validation;
            $r = [$f->is_required ? 'required' : 'nullable'];
            switch ($f->type) {
                case 'text': case 'textarea':
                    $r[] = 'string';
                    $r[] = 'max:'.min((int) ($v['max_length'] ?? config('program_fields.text_max')), config('program_fields.text_max'));
                    break;
                case 'url': $r[] = 'url'; $r[] = 'max:2000'; break;
                case 'number':
                    $r[] = 'numeric';
                    isset($v['min']) && $r[] = 'min:'.$v['min'];
                    isset($v['max']) && $r[] = 'max:'.$v['max'];
                    break;
                case 'boolean': $r[] = 'boolean'; break;
                case 'date': $r[] = 'date'; break;
                case 'select': $r[] = Rule::in((array) $f->options); break;
                case 'multi_select':
                    $r = [$f->is_required ? 'required' : 'nullable', 'array'];
                    $rules["$prefix.{$f->key}.*"] = [Rule::in((array) $f->options)];
                    break;
                case 'file':
                    $mimes = implode(',', $v['mimes'] ?? config('program_fields.file_mimes'));
                    $r[] = 'file';
                    $r[] = 'mimes:'.$mimes;
                    $r[] = 'max:'.min((int) ($v['max_kb'] ?? config('program_fields.file_max_kb')), config('program_fields.file_max_kb'));
                    break;
            }
            $rules["$prefix.{$f->key}"] = $r;
        }

        return $rules;
    }

    /** @param  array<string,mixed>  $answers  already validated, keyed by field key */
    public function storeAnswers(Program $program, string $entityType, int $entityId, array $answers, Collection $fields, ?FileUploadService $files = null): void
    {
        foreach ($fields as $f) {
            if (! array_key_exists($f->key, $answers) || $answers[$f->key] === null || $answers[$f->key] === '') {
                continue;
            }
            $this->store($program, $f, $entityType, $entityId, $answers[$f->key], $files);
        }
    }

    public function store(Program $program, ProgramCustomField $f, string $entityType, int $entityId, mixed $value, ?FileUploadService $files = null): ProgramCustomFieldValue
    {
        $row = ['value_text' => null, 'value_number' => null, 'value_date' => null, 'value_bool' => null, 'value_json' => null];
        switch ($f->type) {
            case 'number': $row['value_number'] = (float) $value; break;
            case 'boolean': $row['value_bool'] = filter_var($value, FILTER_VALIDATE_BOOLEAN); break;
            case 'date': $row['value_date'] = \Illuminate\Support\Carbon::parse($value)->toDateString(); break;
            case 'multi_select': $row['value_json'] = array_values(array_intersect((array) $f->options, array_map('strval', (array) $value))); break;
            case 'file':
                $row['value_text'] = $value instanceof UploadedFile
                    ? ($files ?? app(FileUploadService::class))->saveFile($value, "files/customFields/{$program->id}/{$f->id}")
                    : (string) $value;
                break;
            default: $row['value_text'] = mb_substr(trim(strip_tags((string) $value)), 0, config('program_fields.text_max'));
        }

        return ProgramCustomFieldValue::updateOrCreate(['field_id' => $f->id, 'entity_type' => $entityType, 'entity_id' => $entityId], $row);
    }

    /** @return array<string,mixed> answers keyed by field key, typed */
    public function answersFor(string $entityType, int $entityId, ?Collection $fields = null): array
    {
        if (! self::available()) {
            return [];
        }

        $values = ProgramCustomFieldValue::where('entity_type', $entityType)->where('entity_id', $entityId)->with('field')->get();
        $out = [];
        foreach ($values as $v) {
            if ($v->field && (! $fields || $fields->contains('id', $v->field_id))) {
                $out[$v->field->key] = $v->typed($v->field->type);
            }
        }

        return $out;
    }
}
