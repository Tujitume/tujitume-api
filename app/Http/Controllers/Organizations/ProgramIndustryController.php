<?php

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\ProgramIndustry;
use Illuminate\Http\JsonResponse;

class ProgramIndustryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ProgramIndustry::query()
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
