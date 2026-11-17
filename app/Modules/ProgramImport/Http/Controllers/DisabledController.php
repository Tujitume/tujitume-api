<?php

namespace App\Modules\ProgramImport\Http\Controllers;

use Illuminate\Http\JsonResponse;

/** What every import read answers while importing is switched off, so a page can say so without a failed request. */
class DisabledController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Importing is not turned on.', 'data' => ['enabled' => false, 'items' => [], 'types' => [], 'start_points' => []]]);
    }
}
