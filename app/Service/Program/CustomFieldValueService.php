<?php

namespace App\Service\Program;

use App\Models\Programs\ProgramCustomField;
use App\Models\Programs\ProgramCustomFieldValue;
use Illuminate\Support\Carbon;

class CustomFieldValueService
{
    /**
     * Save a single value for a custom field and entity.
     */
    public function saveForEntity(ProgramCustomField $field, string $entityType, int|string $entityId, mixed $value): ?ProgramCustomFieldValue
    {
        $payload = $this->normalizeValue($field, $value);

        $row = ProgramCustomFieldValue::updateOrCreate(
            [
                'field_id' => $field->id,
                'entity_type' => $entityType,
                'entity_id' => (int) $entityId,
            ],
            $payload
        );

        return $row;
    }

    /**
     * Read a value for a custom field and entity.
     */
    public function getForEntity(ProgramCustomField $field, string $entityType, int|string $entityId): mixed
    {
        $row = ProgramCustomFieldValue::query()
            ->where('field_id', $field->id)
            ->where('entity_type', $entityType)
            ->where('entity_id', (int) $entityId)
            ->first();

        if (! $row) {
            return null;
        }

        return $this->decodeValue($field, $row);
    }

    public function deleteForEntity(ProgramCustomField $field, string $entityType, int|string $entityId): bool
    {
        return ProgramCustomFieldValue::query()
            ->where('field_id', $field->id)
            ->where('entity_type', $entityType)
            ->where('entity_id', (int) $entityId)
            ->delete() > 0;
    }

    protected function normalizeValue(ProgramCustomField $field, mixed $value): array
    {
        $normalized = [
            'value_text' => null,
            'value_number' => null,
            'value_date' => null,
            'value_bool' => null,
            'value_json' => null,
        ];

        $type = $field->type;

        if ($value === null || $value === '' || $value === []) {
            return $normalized;
        }

        switch ($type) {
            case 'number':
                $normalized['value_number'] = (float) $value;
                break;
            case 'boolean':
                $normalized['value_bool'] = (bool) $value;
                break;
            case 'date':
                $normalized['value_date'] = Carbon::parse($value)->toDateString();
                break;
            case 'multi_select':
            case 'select':
                if (is_array($value)) {
                    $normalized['value_json'] = $value;
                } else {
                    $normalized['value_json'] = [$value];
                }
                break;
            case 'file':
            case 'url':
            case 'text':
            case 'textarea':
                $normalized['value_text'] = (string) $value;
                break;
            default:
                $normalized['value_text'] = (string) $value;
                break;
        }

        return $normalized;
    }

    protected function decodeValue(ProgramCustomField $field, ProgramCustomFieldValue $row): mixed
    {
        switch ($field->type) {
            case 'number':
                return $row->value_number;
            case 'boolean':
                return $row->value_bool;
            case 'date':
                return $row->value_date;
            case 'multi_select':
            case 'select':
                return $row->value_json ?? [];
            case 'file':
                return $row->value_text;
            case 'url':
                return $row->value_text;
            default:
                return $row->value_text;
        }
    }
}
