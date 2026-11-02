<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Programs\ProgramMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgramMetadataController extends Controller
{
    public function index(Request $request, ?int $program_industry_id = null)
    {
        $industryId = $program_industry_id ?? $request->query('program_industry_id');
        if ($industryId !== null) {
            validator(['program_industry_id' => $industryId], [
                'program_industry_id' => 'required|integer|exists:program_industries,id',
            ])->validate();
        }

        $defaults = ProgramMetadata::whereNull('program_industry_id')->get(['key', 'value'])
            ->mapWithKeys(fn (ProgramMetadata $record) => [$record->key => $record->value])
            ->all();
        // Industry 1 is the legacy/default industry and shares global values.
        if ($industryId === null || (int) $industryId === 1) {
            return response()->json($defaults);
        }

        $specific = ProgramMetadata::where('program_industry_id', $industryId)->get(['key', 'value'])
            ->mapWithKeys(fn (ProgramMetadata $record) => [$record->key => $record->value])
            ->all();
        return response()->json(array_merge($defaults, $specific));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'program_industry_id' => 'required|integer|exists:program_industries,id',
        ]);

        $values = $request->except('program_industry_id');
        foreach ($values as $key => $value) {
            if (! is_string($key) || $key === '' || strlen($key) > 255 || $key === 'program_industry_id' || ! $this->isJsonValue($value)) {
                throw ValidationException::withMessages([$key => ['Each metadata field must have a valid key and JSON value.']]);
            }
        }

        DB::transaction(function () use ($validated, $values) {
            foreach ($values as $key => $value) {
                ProgramMetadata::updateOrCreate(
                    ['program_industry_id' => $validated['program_industry_id'], 'key' => $key],
                    ['value' => $value]
                );
            }
        });

        return response()->json(['message' => 'Program metadata saved successfully.']);
    }

    private function isJsonValue(mixed $value): bool
    {
        if ($value instanceof \Illuminate\Http\UploadedFile || is_resource($value) || is_object($value)) {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (! $this->isJsonValue($item)) {
                    return false;
                }
            }
        }

        return is_null($value) || is_scalar($value) || is_array($value);
    }
}
