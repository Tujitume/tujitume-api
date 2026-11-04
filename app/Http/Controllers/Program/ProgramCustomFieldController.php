<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponseResource;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramCustomField;
use App\Service\Program\CustomFieldValueService;
use App\Service\Program\ProgramCustomFieldService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProgramCustomFieldController extends Controller
{
    public function index(Program $program)
    {
        $fields = $program->customFields()->withCount('values')->get();

        return ApiResponseResource::success('Program fields fetched successfully.', [
            'fields' => $fields->map(function (ProgramCustomField $field) {
                return [
                    'id' => $field->id,
                    'key' => $field->key,
                    'label' => $field->label,
                    'help_text' => $field->help_text,
                    'audience' => $field->audience,
                    'type' => $field->type,
                    'is_required' => $field->is_required,
                    'options' => $field->options,
                    'validation' => $field->validation,
                    'is_scoring' => $field->is_scoring,
                    'scoring' => $field->scoring,
                    'is_bonus' => $field->is_bonus,
                    'display_order' => $field->display_order,
                    'visibility' => $field->visibility,
                    'is_archived' => $field->is_archived,
                    'usage_count' => $field->values_count ?? 0,
                ];
            })->values(),
            'scoring_summary' => [
                'has_scoring_fields' => $fields->contains(fn ($field) => (bool) $field->is_scoring),
                'normalized_weights' => $fields->filter(fn ($field) => $field->is_scoring)->map(function ($field) {
                    $weight = (float) (($field->scoring ?? [])['weight'] ?? 0);
                    return ['field_id' => $field->id, 'key' => $field->key, 'weight' => $weight];
                })->values(),
            ],
        ], 200);
    }

    public function store(Request $request, Program $program)
    {
        $request->validate([
            'label' => 'required|string|max:150',
            'type' => 'required|in:text,textarea,number,boolean,date,select,multi_select,file,url',
            'audience' => 'nullable|in:application,info',
            'help_text' => 'nullable|string',
            'is_required' => 'nullable|boolean',
            'options' => 'nullable|array',
            'validation' => 'nullable|array',
            'is_scoring' => 'nullable|boolean',
            'scoring' => 'nullable|array',
            'is_bonus' => 'nullable|boolean',
            'display_order' => 'nullable|integer',
            'visibility' => 'nullable|in:public,owner_only',
        ]);

        $field = app(ProgramCustomFieldService::class)->createForProgram($program, [$request->all()]);
        $field = $field[0] ?? null;

        return ApiResponseResource::success('Program field created successfully.', $field, 201);
    }

    public function update(Request $request, ProgramCustomField $field)
    {
        if ($request->user()?->id !== $field->program->user_id) {
            return ApiResponseResource::error('Unauthorized.', null, 403);
        }

        $hasSubmittedApplications = $field->program->applications()->exists();
        $baseRules = [
            'label' => 'sometimes|required|string|max:150',
            'help_text' => 'sometimes|nullable|string',
            'display_order' => 'sometimes|required|integer',
            'visibility' => 'sometimes|required|in:public,owner_only',
        ];

        if ($hasSubmittedApplications) {
            $baseRules = array_merge($baseRules, [
                'options' => 'sometimes|array',
                'is_required' => 'sometimes|boolean',
                'is_archived' => 'sometimes|boolean',
            ]);

            $disallowedKeys = ['type', 'validation', 'is_scoring', 'scoring', 'is_bonus'];
            foreach ($disallowedKeys as $key) {
                if ($request->has($key)) {
                    return ApiResponseResource::error('This field is locked after applications exist. Only label/help_text/display_order/visibility, appending options, relaxing is_required, and archiving are allowed.', null, 422);
                }
            }

            if ($request->has('options')) {
                $previousOptions = array_values((array) ($field->options ?? []));
                $newOptions = array_values((array) $request->input('options'));
                if (count($newOptions) < count($previousOptions) || array_slice($newOptions, 0, count($previousOptions)) !== $previousOptions) {
                    return ApiResponseResource::error('After applications exist, options can only be appended to the existing list.', null, 422);
                }
            }

            if ($request->has('is_required')) {
                $newIsRequired = (bool) $request->boolean('is_required');
                if ($field->is_required && ! $newIsRequired) {
                    // relaxing required is allowed
                } elseif (! $field->is_required && $newIsRequired) {
                    return ApiResponseResource::error('After applications exist, is_required can only be relaxed, not tightened.', null, 422);
                }
            }
        }

        $request->validate($baseRules);

        if ($request->has('label')) {
            $field->label = $request->label;
        }

        if ($request->has('help_text')) { $field->help_text = $request->help_text; }
        if ($request->has('display_order')) { $field->display_order = $request->display_order; }
        if ($request->has('visibility')) { $field->visibility = $request->visibility; }
        if ($request->has('options') && ! $hasSubmittedApplications) { $field->options = $request->options; }
        if ($request->has('options') && $hasSubmittedApplications) { $field->options = array_values(array_unique(array_merge((array) ($field->options ?? []), (array) $request->input('options')))); }
        if ($request->has('is_required')) { $field->is_required = (bool) $request->boolean('is_required'); }
        if ($request->has('is_archived')) { $field->is_archived = (bool) $request->boolean('is_archived'); }
        if ($request->has('type') && ! $hasSubmittedApplications) { $field->type = $request->type; }
        if ($request->has('validation') && ! $hasSubmittedApplications) { $field->validation = $request->validation; }
        if ($request->has('is_scoring') && ! $hasSubmittedApplications) { $field->is_scoring = (bool) $request->boolean('is_scoring'); }
        if ($request->has('scoring') && ! $hasSubmittedApplications) { $field->scoring = $request->scoring; }
        if ($request->has('is_bonus') && ! $hasSubmittedApplications) { $field->is_bonus = (bool) $request->boolean('is_bonus'); }
        $field->save();

        return ApiResponseResource::success('Program field updated successfully.', $field->fresh(), 200);
    }

    public function destroy(ProgramCustomField $field)
    {
        if (auth()->id() !== $field->program->user_id) {
            return ApiResponseResource::error('Unauthorized.', null, 403);
        }

        if ($field->values()->count() > 0) {
            $field->update(['is_archived' => true]);
            return ApiResponseResource::success('Program field archived successfully.', $field, 200);
        }

        $field->delete();
        return ApiResponseResource::success('Program field deleted successfully.', null, 200);
    }

    public function reorder(Request $request, Program $program)
    {
        $request->validate(['ordered_ids' => 'required|array']);

        $orderedIds = $request->ordered_ids;
        foreach ($orderedIds as $index => $id) {
            $program->customFields()->where('id', $id)->update(['display_order' => $index + 1]);
        }

        return ApiResponseResource::success('Program fields reordered successfully.', ['ordered_ids' => $orderedIds], 200);
    }

    public function saveInfoValues(Request $request, Program $program)
    {
        $request->validate(['values' => 'required|array']);
        $service = app(CustomFieldValueService::class);
        $saved = [];

        foreach ($request->values as $key => $value) {
            $field = $program->customFields()->where('key', $key)->where('audience', 'info')->first();
            if (! $field) {
                continue;
            }

            $saved[$key] = $service->saveForEntity($field, 'program', $program->id, $value);
        }

        return ApiResponseResource::success('Program info values updated successfully.', $saved, 200);
    }
}
