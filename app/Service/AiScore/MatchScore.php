<?php

namespace App\Service\AiScore;

use App\Models\Programs\Program;
use App\Models\Programs\ProgramCustomField;
use App\Service\Misc\ErrorLogService;

class MatchScore
{
    public function program($request, $program_id = null)
    {
        try {
            $program = $request instanceof Program ? $request : Program::findOrFail($program_id ?? $request->program_id ?? $request->input('program_id'));
            $answers = $request instanceof Program ? [] : $request->input('custom_answers', $request->all());

            return $this->compute($program, is_array($answers) ? $answers : []);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => is_object($request) ? $request->all() : $request]);

            return ['score' => null, 'breakdown' => [], 'bonus' => [], 'eligible_for_bonus' => false, 'error' => 'Something went wrong, please try again later.'];
        }
    }

    public function compute(Program $program, array $answers): array
    {
        $fields = $program->customFields()
            ->where('audience', 'application')
            ->where('is_archived', false)
            ->where('is_scoring', true)
            ->orderBy('display_order')
            ->get();

        if ($fields->isEmpty()) {
            return ['score' => null, 'breakdown' => [], 'bonus' => [], 'eligible_for_bonus' => false];
        }

        $nonBonusFields = $fields->filter(fn (ProgramCustomField $field) => ! ($field->is_bonus ?? false));
        if ($nonBonusFields->isEmpty()) {
            return ['score' => null, 'breakdown' => [], 'bonus' => [], 'eligible_for_bonus' => false];
        }

        $baseWeightTotal = $nonBonusFields->sum(function (ProgramCustomField $field) {
            $config = $field->scoring ?? ['weight' => 0];
            return (float) ($config['weight'] ?? 0);
        });

        $baseScore = 0.0;
        $breakdown = [];
        foreach ($fields as $field) {
            $config = $field->scoring ?? ['enabled' => false, 'weight' => 0];
            if (($config['enabled'] ?? false) === false) {
                continue;
            }

            $answer = $this->extractAnswer($field, $answers);
            $matchType = $config['match_type'] ?? 'provided';
            $strategy = $this->strategyFor($matchType);
            $evaluation = $strategy->evaluate($field, $answer, $config);

            $weight = (float) ($config['weight'] ?? 0);
            $normalizedWeight = $baseWeightTotal > 0 ? ($weight / $baseWeightTotal) * 100 : 0;
            $matchValue = (float) ($evaluation['match'] ?? 0);
            $earned = $normalizedWeight * $matchValue;

            if (($field->is_bonus ?? false) === false) {
                $baseScore += $earned;
            }

            $breakdown[] = [
                'field_id' => (int) $field->id,
                'key' => $field->key,
                'label' => $field->label,
                'match_type' => $matchType,
                'weight' => $weight,
                'normalized_weight' => round($normalizedWeight, 2),
                'match' => round(max(0, min(1, $matchValue)), 4),
                'earned_points' => round($earned, 2),
                'answer' => $answer,
                'target' => $config['target'] ?? null,
            ];
        }

        $baseScore = min(100, max(0, $baseScore));
        $limit = $program->match_score_limit ?? null;
        $eligibleForBonus = $limit === null || $baseScore >= (float) $limit;

        $bonusEntries = [];
        $bonusScore = 0.0;
        foreach ($fields as $field) {
            if (! ($field->is_bonus ?? false)) {
                continue;
            }

            $config = $field->scoring ?? ['enabled' => false, 'weight' => 0];
            if (($config['enabled'] ?? false) === false) {
                continue;
            }

            $answer = $this->extractAnswer($field, $answers);
            $evaluation = $this->strategyFor($config['match_type'] ?? 'provided')->evaluate($field, $answer, $config);
            $weight = (float) ($config['weight'] ?? 0);
            $matchValue = (float) ($evaluation['match'] ?? 0);
            $bonusEarned = $eligibleForBonus ? $weight * $matchValue : 0.0;
            $bonusScore += $bonusEarned;

            $bonusEntries[] = [
                'field_id' => (int) $field->id,
                'key' => $field->key,
                'label' => $field->label,
                'match_type' => $config['match_type'] ?? 'provided',
                'weight' => $weight,
                'normalized_weight' => $weight,
                'match' => round(max(0, min(1, $matchValue)), 4),
                'earned_points' => round($bonusEarned, 2),
                'answer' => $answer,
                'target' => $config['target'] ?? null,
            ];
        }

        $finalScore = $baseScore + $bonusScore;
        $scoreValue = (int) round(min(100, max(0, $finalScore)), 0, PHP_ROUND_HALF_UP);

        $scoreBreakdown = array_values($breakdown);

        return [
            'score' => $scoreValue,
            'match_score' => $scoreValue,
            'breakdown' => $breakdown,
            'score_breakdown' => $scoreBreakdown,
            'bonus' => $bonusEntries,
            'eligible_for_bonus' => $eligibleForBonus,
        ];
    }

    protected function extractAnswer(ProgramCustomField $field, array $answers): mixed
    {
        $key = $field->key;
        if (array_key_exists($key, $answers)) {
            return $answers[$key];
        }

        if (array_key_exists('custom_answers', $answers) && is_array($answers['custom_answers'])) {
            return $this->extractAnswer($field, $answers['custom_answers']);
        }

        if (array_key_exists($field->id, $answers)) {
            return $answers[$field->id];
        }

        return null;
    }

    protected function strategyFor(string $matchType): MatchTypeStrategy
    {
        return match ($matchType) {
            'in_set' => new InSetMatchStrategy,
            'range' => new RangeMatchStrategy,
            'equals' => new EqualsMatchStrategy,
            'provided' => new ProvidedMatchStrategy,
            default => new ProvidedMatchStrategy,
        };
    }
}

interface MatchTypeStrategy
{
    public function evaluate(ProgramCustomField $field, mixed $answer, array $config): array;
}

class InSetMatchStrategy implements MatchTypeStrategy
{
    public function evaluate(ProgramCustomField $field, mixed $answer, array $config): array
    {
        $target = $this->normalizeList($config['target'] ?? []);
        $selected = $this->normalizeList($answer);

        if ($field->type === 'multi_select') {
            $mode = $config['mode'] ?? 'any';
            $intersection = array_values(array_intersect($selected, $target));
            $match = match ($mode) {
                'any' => count($intersection) > 0 ? 1.0 : 0.0,
                'proportional' => count($target) > 0 ? (count($intersection) / count($target)) : 0.0,
                'all' => count($target) > 0 && count(array_diff($target, $selected)) === 0 ? 1.0 : 0.0,
                default => count($intersection) > 0 ? 1.0 : 0.0,
            };

            return ['match' => max(0, min(1, $match))];
        }

        $match = in_array((string) $answer, $target, true) ? 1.0 : 0.0;

        return ['match' => $match];
    }

    protected function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(fn ($item) => (string) $item, $value));
        }

        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values(array_map(fn ($item) => (string) $item, $decoded));
            }

            return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $value) ?: [])));
        }

        return [(string) $value];
    }
}

class RangeMatchStrategy implements MatchTypeStrategy
{
    public function evaluate(ProgramCustomField $field, mixed $answer, array $config): array
    {
        $target = $config['target'] ?? [];
        $min = $target['min'] ?? null;
        $max = $target['max'] ?? null;

        $value = $field->type === 'date' ? (string) ($answer ?? '') : (float) ($answer ?? 0);
        if ($field->type === 'date' && ! empty($answer)) {
            try {
                $value = \Illuminate\Support\Carbon::parse($answer)->toDateString();
                $min = $min ? \Illuminate\Support\Carbon::parse($min)->toDateString() : null;
                $max = $max ? \Illuminate\Support\Carbon::parse($max)->toDateString() : null;
            } catch (\Throwable $e) {
                return ['match' => 0.0];
            }
        }

        if ($answer === null || $answer === '' || $answer === []) {
            return ['match' => 0.0];
        }

        if ($field->type === 'date') {
            $match = (empty($min) || $value >= $min) && (empty($max) || $value <= $max) ? 1.0 : 0.0;
            return ['match' => $match];
        }

        $match = ((empty($min) || $value >= $min) && (empty($max) || $value <= $max)) ? 1.0 : 0.0;

        return ['match' => $match];
    }
}

class EqualsMatchStrategy implements MatchTypeStrategy
{
    public function evaluate(ProgramCustomField $field, mixed $answer, array $config): array
    {
        if ($answer === null || $answer === '') {
            return ['match' => 0.0];
        }

        $target = $config['target'] ?? null;
        $normalized = is_bool($answer) ? $answer : filter_var($answer, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $expected = is_bool($target) ? $target : filter_var($target, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return ['match' => ($normalized === $expected) ? 1.0 : 0.0];
    }
}

class ProvidedMatchStrategy implements MatchTypeStrategy
{
    public function evaluate(ProgramCustomField $field, mixed $answer, array $config): array
    {
        if ($answer === null) {
            return ['match' => 0.0];
        }

        if (is_array($answer)) {
            return ['match' => ! empty($answer) ? 1.0 : 0.0];
        }

        if ($answer instanceof \Illuminate\Http\UploadedFile) {
            return ['match' => $answer->isValid() ? 1.0 : 0.0];
        }

        return ['match' => (string) $answer !== '' ? 1.0 : 0.0];
    }
}

