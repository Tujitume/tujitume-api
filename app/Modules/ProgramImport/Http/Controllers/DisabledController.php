<?php

namespace App\Modules\ProgramImport\Http\Controllers;

use Illuminate\Http\JsonResponse;

/** What the import history call answers while importing is switched off, so a page can say so without a failed request. */
class DisabledController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Importing is not turned on.', 'data' => ['enabled' => false, 'items' => []]]);
    }
}
