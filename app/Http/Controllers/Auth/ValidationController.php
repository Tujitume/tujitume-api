<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ValidationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function checkAvailability(Request $request, string $field)
    {
        $definition = config("registration_validation.fields.{$field}");

        if (! $definition) {
            return response()->json(['message' => 'Unsupported availability field.'], 404);
        }

        $data = $request->validate([
            'value' => $definition['rules'],
        ]);

        $value = $this->normalize($data['value'], $definition['normalizer'] ?? null);
        $exists = DB::table($definition['table'])
            ->where($definition['column'], $value)
            ->exists();

        return response()->json([
            'field' => $field,
            'value' => $value,
            'available' => ! $exists,
        ]);
    }

    private function normalize(string $value, ?string $normalizer): string
    {
        $value = trim($value);

        return $normalizer === 'lowercase_trim' ? strtolower($value) : $value;
    }
}
