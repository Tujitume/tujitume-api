<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Models\Business\Categories;
use App\Models\Services\ServiceCategory;
use App\Models\Shared\ErrorLog;
use App\Service\Misc\ErrorLogService;
use Exception;
use Illuminate\Http\Request;

class MiscController extends Controller
{
    public function categories()
    {
        try {
            return response()->json(['categories' => Categories::all()], 200, [], JSON_UNESCAPED_SLASHES);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function serviceCategories()
    {
        try {
            return response()->json([
                'categories' => ServiceCategory::all()
            ], 200, [], JSON_UNESCAPED_SLASHES);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // extra
    public function errorLogs(Request $request)
    {
        try {
            $query = ErrorLog::query()->latest();

            // SEARCH FILTER
            if ($request->filled('search')) {
                $query->where(function ($q) use ($request) {
                    $q->where('message', 'LIKE', "%{$request->search}%")
                        ->orWhere('type', 'LIKE', "%{$request->search}%")
                        ->orWhere('file', 'LIKE', "%{$request->search}%");
                });
            }

            // TYPE FILTER
            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            // PAGINATION (safe cast)
            $perPage = (int) $request->get('per_page', 20);
            $logs = $query->paginate($perPage);

            return response()->json([
                'message' => 'Error logs fetched successfully',
                'data' => $logs
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            ErrorLogService::report($e, [
                'input' => $request->all()
            ]);

            return response()->json([
                'message' => 'Something went wrong while fetching error logs',
            ], 500);
        }
    }
}
