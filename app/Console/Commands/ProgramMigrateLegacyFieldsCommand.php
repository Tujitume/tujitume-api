<?php

namespace App\Console\Commands;

use App\Models\Programs\Program;
use App\Service\Program\ProgramCustomFieldService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ProgramMigrateLegacyFieldsCommand extends Command
{
    protected $signature = 'programs:migrate-legacy-fields {--dry-run : Preview changes without writing them}';

    protected $description = 'Create custom fields from the legacy program columns and back up the original values.';

    public function handle(): int
    {
        $legacyColumns = [
            'program_focus',
            'regions',
            'startup_stage_focus',
            'social_impact_areas',
            'required_documents',
            'funding_per_business',
            'bonus_points',
            'eligibility_criteria',
            'impact_objectives',
            'evaluation_criteria',
            'funder_name',
            'funder_type',
            'program_brief_pdf',
            'start_date',
            'end_date',
            'mid_milestone_required',
        ];

        $programs = Program::query()->orderBy('id')->get();
        $processed = 0;
        $dryRun = (bool) $this->option('dry-run');

        foreach ($programs as $program) {
            $legacyValues = [];
            foreach ($legacyColumns as $column) {
                if (! Schema::hasColumn('programs', $column)) {
                    continue;
                }

                $value = $program->getAttribute($column);
                if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                    continue;
                }

                $legacyValues[$column] = $value;
            }

            if (empty($legacyValues)) {
                continue;
            }

            $definitions = $this->buildDefinitions($program, $legacyValues);
            if ($dryRun) {
                $this->info(sprintf('Program #%d has %d legacy values to convert into custom fields.', $program->id, count($definitions)));
                $processed++;
                continue;
            }

            DB::transaction(function () use ($program, $definitions) {
                $backup = json_encode([
                    'program_id' => $program->id,
                    'legacy_values' => $this->collectLegacyPayload($program),
                    'converted_at' => now()->toIso8601String(),
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

                Storage::disk('local')->put('backups/programs_legacy_' . $program->id . '_' . now()->timestamp . '.json', $backup);
                app(ProgramCustomFieldService::class)->createForProgram($program, $definitions);
            });

            $processed++;
        }

        $this->info('Processed ' . $processed . ' program(s).');

        return self::SUCCESS;
    }

    protected function buildDefinitions(Program $program, array $legacyValues): array
    {
        $definitions = [];

        if (! empty($legacyValues['program_focus'])) {
            $definitions[] = [
                'label' => 'Business sector',
                'audience' => 'application',
                'type' => 'multi_select',
                'options' => $this->optionList($legacyValues['program_focus']),
                'is_required' => false,
                'is_scoring' => true,
                'scoring' => ['enabled' => true, 'weight' => 30, 'match_type' => 'in_set', 'mode' => 'any', 'target' => $this->optionList($legacyValues['program_focus'])],
            ];
        }

        if (! empty($legacyValues['regions'])) {
            $regions = $this->optionList($legacyValues['regions']);
            $definitions[] = [
                'label' => 'Region',
                'audience' => 'application',
                'type' => 'select',
                'options' => $regions,
                'is_required' => false,
                'is_scoring' => true,
                'scoring' => ['enabled' => true, 'weight' => 15, 'match_type' => 'in_set', 'target' => $regions],
            ];
        }

        if (! empty($legacyValues['startup_stage_focus'])) {
            $stageValues = $this->optionList($legacyValues['startup_stage_focus']);
            $definitions[] = [
                'label' => 'Business stage',
                'audience' => 'application',
                'type' => 'select',
                'options' => $stageValues,
                'is_required' => false,
                'is_scoring' => true,
                'scoring' => ['enabled' => true, 'weight' => 10, 'match_type' => 'in_set', 'target' => $stageValues],
            ];
        }

        if (! empty($legacyValues['social_impact_areas'])) {
            $impactValues = $this->optionList($legacyValues['social_impact_areas']);
            $definitions[] = [
                'label' => 'Social impact areas',
                'audience' => 'application',
                'type' => 'multi_select',
                'options' => $impactValues,
                'is_required' => false,
                'is_scoring' => true,
                'scoring' => ['enabled' => true, 'weight' => 10, 'match_type' => 'in_set', 'mode' => 'any', 'target' => $impactValues],
            ];
        }

        if (! empty($legacyValues['funding_per_business'])) {
            $definitions[] = [
                'label' => 'Revenue last 12 months',
                'audience' => 'application',
                'type' => 'number',
                'is_required' => false,
                'is_scoring' => true,
                'scoring' => ['enabled' => true, 'weight' => 10, 'match_type' => 'range', 'target' => ['min' => (float) $legacyValues['funding_per_business']],
                ],
            ];
        }

        if (! empty($legacyValues['required_documents'])) {
            $documents = $this->optionList($legacyValues['required_documents']);
            foreach ($documents as $document) {
                $definitions[] = [
                    'label' => (string) $document,
                    'audience' => 'application',
                    'type' => 'file',
                    'is_required' => false,
                    'is_scoring' => true,
                    'scoring' => ['enabled' => true, 'weight' => 5, 'match_type' => 'provided'],
                ];
            }
        }

        if (! empty($legacyValues['eligibility_criteria'])) {
            $definitions[] = ['label' => 'Eligibility criteria', 'audience' => 'info', 'type' => 'textarea', 'help_text' => 'Migrated from the legacy eligibility criteria field.'];
        }

        if (! empty($legacyValues['impact_objectives'])) {
            $definitions[] = ['label' => 'Impact objectives', 'audience' => 'info', 'type' => 'textarea', 'help_text' => 'Migrated from the legacy impact objectives field.'];
        }

        if (! empty($legacyValues['evaluation_criteria'])) {
            $definitions[] = ['label' => 'Evaluation criteria', 'audience' => 'info', 'type' => 'textarea', 'help_text' => 'Migrated from the legacy evaluation criteria field.'];
        }

        if (! empty($legacyValues['funder_name'])) {
            $definitions[] = ['label' => 'Funder name', 'audience' => 'info', 'type' => 'text'];
        }

        if (! empty($legacyValues['funder_type'])) {
            $definitions[] = ['label' => 'Funder type', 'audience' => 'info', 'type' => 'select', 'options' => [['value' => (string) $legacyValues['funder_type'], 'label' => (string) $legacyValues['funder_type']]]];
        }

        if (! empty($legacyValues['program_brief_pdf'])) {
            $definitions[] = ['label' => 'Program brief PDF', 'audience' => 'info', 'type' => 'file'];
        }

        if (! empty($legacyValues['start_date'])) {
            $definitions[] = ['label' => 'Program start date', 'audience' => 'info', 'type' => 'date'];
        }

        if (! empty($legacyValues['end_date'])) {
            $definitions[] = ['label' => 'Program end date', 'audience' => 'info', 'type' => 'date'];
        }

        if (! empty($legacyValues['mid_milestone_required'])) {
            $definitions[] = ['label' => 'Mid milestone required', 'audience' => 'info', 'type' => 'boolean'];
        }

        if (! empty($legacyValues['bonus_points'])) {
            $definitions[] = [
                'label' => 'Bonus points',
                'audience' => 'application',
                'type' => 'select',
                'options' => $this->optionList($legacyValues['bonus_points']),
                'is_required' => false,
                'is_scoring' => true,
                'is_bonus' => true,
                'scoring' => ['enabled' => true, 'weight' => 10, 'match_type' => 'in_set', 'target' => $this->optionList($legacyValues['bonus_points'])],
            ];
        }

        return $definitions;
    }

    protected function collectLegacyPayload(Program $program): array
    {
        $payload = [];
        foreach (['program_focus', 'regions', 'startup_stage_focus', 'social_impact_areas', 'required_documents', 'funding_per_business', 'bonus_points', 'eligibility_criteria', 'impact_objectives', 'evaluation_criteria', 'funder_name', 'funder_type', 'program_brief_pdf', 'start_date', 'end_date', 'mid_milestone_required'] as $column) {
            if (Schema::hasColumn('programs', $column)) {
                $value = $program->getAttribute($column);
                if (! in_array($value, [null, ''], true)) {
                    $payload[$column] = $value;
                }
            }
        }

        return $payload;
    }

    protected function optionList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(fn ($item) => is_array($item) ? ($item['value'] ?? $item['label'] ?? '') : (string) $item, $value));
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values(array_map(fn ($item) => is_array($item) ? ($item['value'] ?? $item['label'] ?? '') : (string) $item, $decoded));
            }

            return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $value) ?: [])));
        }

        return [];
    }
}
