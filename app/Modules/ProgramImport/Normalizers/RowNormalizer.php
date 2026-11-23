<?php

namespace App\Modules\ProgramImport\Normalizers;

use App\Modules\ProgramImport\Registry\ImportTypeRegistry;

/** Normalizes one mapped row (registry key => raw cell) for a sheet type. */
class RowNormalizer
{
    /** @return array{values: array, errors: array, warnings: array} */
    public static function normalize(string $type, array $row, array $options = [], array $valueMaps = [], array $defaults = []): array
    {
        $values = [];
        $errors = [];
        $warnings = [];
        foreach (ImportTypeRegistry::fields($type) as $key => $spec) {
            $raw = $row[$key] ?? null;
            if (Normalizer::blank($raw)) {
                $raw = $defaults[$key] ?? $spec['default'];
            }
            if (Normalizer::blank($raw) && ! is_bool($raw)) {
                if ($spec['required']) {
                    $errors[$key] = "{$spec['label']} is required";
                }
                $values[$key] = null;

                continue;
            }
            $r = match ($spec['type']) {
                'money' => Normalizer::money($raw),
                'decimal' => Normalizer::decimal($raw),
                'int' => Normalizer::int($raw),
                'bool' => is_bool($raw) ? ['value' => $raw, 'error' => null, 'warning' => null] : Normalizer::bool($raw),
                'date', 'datetime' => Normalizer::date($raw, $options['date_format'] ?? null),
                'email' => Normalizer::email($raw),
                'phone' => Normalizer::phone($raw, $options['default_country_code'] ?? null),
                'url' => Normalizer::url($raw),
                'link_or_file' => Normalizer::linkOrFile($raw),
                'list' => Normalizer::list($raw),
                'enum' => Normalizer::enum($raw, $spec['enum'] ?? [], ($valueMaps[$key] ?? []) + ($spec['aliases'] ?? [])),
                default => Normalizer::text($raw),
            };
            if ($r['error']) {
                $errors[$key] = $r['error'];
            }
            if ($r['warning']) {
                $warnings[$key] = $r['warning'];
            }
            $values[$key] = $r['value'];
        }

        return compact('values', 'errors', 'warnings');
    }
}
