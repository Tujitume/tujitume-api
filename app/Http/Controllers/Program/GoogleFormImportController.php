<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Service\Program\GoogleFormReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class GoogleFormImportController extends Controller
{
    public function preview(Request $request, GoogleFormReader $reader): JsonResponse
    {
        $request->validate(['url' => ['required', 'string', 'max:500']]);

        try {
            $result = $reader->read($request->input('url'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'code' => $e->getMessage(), 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'code' => 'form_unreachable', 'message' => 'form_unreachable'], 422);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }
}
