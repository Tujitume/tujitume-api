<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Service\Pending\PendingTasks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PendingTasksController extends Controller
{
    /**
     * GET /pending-tasks
     * Everything waiting for the signed-in person, grouped, found in one pass. Cached for a few seconds so opening
     * pages quickly never repeats the work; `?fresh=1` skips the cache (used right after the person acts).
     */
    public function index(Request $request, PendingTasks $pending)
    {
        $user = $request->user();
        $key = 'pending-tasks:'.$user->id;
        if ($request->boolean('fresh')) Cache::forget($key);

        $data = Cache::remember($key, 20, fn () => $pending->for($user));

        return response()->json(['data' => $data]);
    }
}
