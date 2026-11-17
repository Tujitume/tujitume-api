<?php

namespace App\Modules\ProgramImport\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the import reads answer while importing is switched off, so a page can say so without a failed request. Each address
 * answers in the shape its real endpoint uses (a list stays a list), so a page that reads it cannot break on it.
 */
class DisabledController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = match (true) {
            str_ends_with($request->path(), '/start-points') => [],
            str_ends_with($request->path(), '/types') => ['enabled' => false, 'types' => []],
            default => ['enabled' => false, 'items' => []],
        };

        return response()->json(['success' => true, 'message' => 'Importing is not turned on.', 'data' => $data]);
    }
}
