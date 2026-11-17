<?php

namespace App\Modules\ProgramImport\Mapping;

use App\Modules\ProgramImport\Registry\ImportTypeRegistry;

/** Applies a saved mapping to one raw row: header cells -> registry keys (+ question answers). */
class MappingApplier
{
    /** @return array{values: array, answers: array, custom: array} */
    public static function apply(array $mapping, string $sheet, array $cells): array
    {
        $m = $mapping['sheets'][$sheet] ?? [];
        $columns = $m['columns'] ?? [];
        $ignored = array_flip($m['ignored'] ?? []);
        $fields = ImportTypeRegistry::fields($sheet);
        $values = [];
        $answers = [];
        $custom = [];
        foreach ($cells as $header => $cell) {
            if (isset($ignored[$header])) {
                continue;
            }
            $target = $columns[$header]['target'] ?? (isset($fields[$header]) ? $header : null);
            if (! $target) {
                continue;
            }
            if (str_starts_with($target, 'custom:')) {
                $custom[substr($target, 7)] = $cell;
            } elseif (str_starts_with($target, 'question:')) {
                $answers[substr($target, 9)] = $cell;
            } elseif (isset($fields[$target])) {
                $values[$target] = $cell;
            }
        }

        return compact('values', 'answers', 'custom');
    }

    /** Only registry keys and question: targets are allowed (the AI allowlist rule applies to user edits too). */
    public static function sanitize(string $sheet, array $mapping): array
    {
        $fields = ImportTypeRegistry::fields($sheet);
        $out = ['columns' => [], 'value_maps' => [], 'defaults' => [], 'ignored' => array_values($mapping['ignored'] ?? [])];
        foreach ($mapping['columns'] ?? [] as $header => $c) {
            $t = $c['target'] ?? null;
            if ($t === null || $t === '') {
                $out['ignored'][] = $header;
            } elseif (isset($fields[$t]) || (is_string($t) && preg_match('/^(question|custom):[\w.\-]{1,100}$/', $t))) {
                $out['columns'][$header] = ['target' => $t, 'confidence' => (float) ($c['confidence'] ?? 1), 'origin' => $c['origin'] ?? 'user'];
            }
        }
        foreach ($mapping['value_maps'] ?? [] as $t => $map) {
            if (isset($fields[$t]) && is_array($map)) {
                $out['value_maps'][$t] = array_map('strval', $map);
            }
        }
        foreach ($mapping['defaults'] ?? [] as $t => $v) {
            if (isset($fields[$t]) && (is_scalar($v) || $v === null)) {
                $out['defaults'][$t] = $v;
            }
        }
        $out['ignored'] = array_values(array_unique($out['ignored']));

        return $out;
    }
}
