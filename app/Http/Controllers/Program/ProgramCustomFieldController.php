<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponseResource;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramCustomField;
use App\Service\Program\CustomFields\CustomFieldService;
use App\Service\Program\CustomFields\CustomFieldValueService;
use App\Service\Program\ProgramAccess;
use Illuminate\Http\Request;

/** The program owner's custom fields: definitions, scoring factors and info values. */
class ProgramCustomFieldController extends Controller
{
    private function authorizeProgram(Program $program): void
    {
        abort_unless(ProgramAccess::owns($program), 403, ProgramAccess::DENIED);
    }

    private function rules(bool $create): array
    {
        $t = config('program_fields.types');

        return [
            'label' => [$create ? 'required' : 'sometimes', 'string', 'max:'.config('program_fields.label_max')],
            'help_text' => ['nullable', 'string', 'max:1000'],
            'audience' => ['sometimes', 'in:application,info'],
            'type' => $create ? ['required', 'in:'.implode(',', $t)] : ['prohibited'],
            'is_required' => ['sometimes', 'boolean'], 'is_public' => ['sometimes', 'boolean'], 'display_order' => ['sometimes', 'integer', 'min:0'],
            'options' => ['sometimes', 'nullable', 'array', 'max:'.config('program_fields.max_options')], 'options.*' => ['string', 'max:200'],
            'validation' => ['sometimes', 'nullable', 'array'], 'is_scoring' => ['sometimes', 'boolean'], 'is_bonus' => ['sometimes', 'boolean'],
            'scoring' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function index(Program $program)
    {
        $this->authorizeProgram($program);
        $fields = $program->customFields()->withCount('values')->get();
        $active = $fields->where('is_archived', false)->where('audience', 'application')->where('is_scoring', true)->where('is_bonus', false)
            ->filter(fn ($f) => ($f->scoring['enabled'] ?? false));
        $total = (float) $active->sum(fn ($f) => (float) $f->scoring['weight']);

        return ApiResponseResource::success('Fields', [
            'fields' => $fields->map(fn ($f) => $f->toArray() + ['usage_count' => $f->values_count])->values(),
            'info_values' => app(CustomFieldValueService::class)->answersFor('program', $program->id),
            'scoring_summary' => ['has_scoring_field' => $total > 0, 'normalized_weights' => $active->mapWithKeys(fn ($f) => [$f->key => $total ? round((float) $f->scoring['weight'] / $total * 100, 2) : 0])],
        ]);
    }

    public function store(Request $request, Program $program, CustomFieldService $service)
    {
        $this->authorizeProgram($program);
        $field = $service->create($program, $request->validate($this->rules(true)));

        return ApiResponseResource::success('Field created.', $field->toArray(), 201);
    }

    public function update(Request $request, ProgramCustomField $field, CustomFieldService $service)
    {
        $this->authorizeProgram($field->program);
        abort_if($request->has('type') || $request->has('audience'), 422, 'A field\'s type and audience cannot be changed. Create a new field instead.');
        $data = $request->validate($this->rules(false));

        return ApiResponseResource::success('Field updated.', $service->update($field, $data)->toArray());
    }

    public function destroy(ProgramCustomField $field, CustomFieldService $service)
    {
        $this->authorizeProgram($field->program);

        return ApiResponseResource::success('Field removed.', ['result' => $service->delete($field)]);
    }

    public function reorder(Request $request, Program $program, CustomFieldService $service)
    {
        $this->authorizeProgram($program);
        $service->reorder($program, $request->validate(['ordered_ids' => 'required|array|max:100', 'ordered_ids.*' => 'integer'])['ordered_ids']);

        return ApiResponseResource::success('Order saved.', null);
    }

    /** PUT {program}/info-values  { values: { key: value } } */
    public function infoValues(Request $request, Program $program, CustomFieldValueService $values)
    {
        $this->authorizeProgram($program);
        $fields = $program->customFields()->where('audience', 'info')->where('is_archived', false)->get()->keyBy('key');
        $request->validate(['values' => 'required|array'] + collect($values->rules($fields, 'values'))->map(fn ($r) => array_diff($r, ['required']) + [0 => 'nullable'])->all());
        foreach ($request->input('values', []) as $key => $value) {
            $f = $fields->get($key);
            $f && $values->store($program, $f, 'program', $program->id, $request->file("values.$key") ?? $value);
        }

        return ApiResponseResource::success('Info saved.', $values->answersFor('program', $program->id));
    }
}
