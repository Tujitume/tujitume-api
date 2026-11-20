<?php

namespace App\Modules\ProgramImport\Support;

/** "name:weight:max" list items <-> the rounds' scoring_criteria JSON. */
class Criteria
{
    /** @return array{criteria: list<array>, error: ?string} */
    public static function parse(array $items, ?string $rubric): array
    {
        $out = [];
        foreach ($items as $item) {
            $p = array_map('trim', explode(':', (string) $item));
            if ($p[0] === '') {
                continue;
            }
            $out[] = ['name' => $p[0], 'description' => null, 'weight' => isset($p[1]) && is_numeric($p[1]) ? (int) $p[1] : null, 'score_range' => isset($p[2]) && is_numeric($p[2]) ? (int) $p[2] : 100];
        }
        if (($rubric ?? 'simple_total') === 'weighted') {
            if (! $out) {
                return ['criteria' => [], 'error' => 'A weighted rubric needs at least one criterion'];
            }
            if (array_filter($out, fn ($c) => $c['weight'] === null)) {
                return ['criteria' => $out, 'error' => 'A weighted rubric needs a weight for every criterion (name:weight:max)'];
            }
            if (array_sum(array_column($out, 'weight')) !== 100) {
                return ['criteria' => $out, 'error' => 'Criterion weights must add up to 100'];
            }
        }

        return ['criteria' => array_map(fn ($c) => $c + [], $out), 'error' => null];
    }
}
