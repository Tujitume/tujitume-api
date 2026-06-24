<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Auth\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $sessions = UserSession::with('device')
            ->where('user_id', $userId)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function($s){
                return [
                    'id' => $s->id,
                    'ip' => $s->ip,
                    'user_agent' => $s->user_agent,
                    'last_activity' => $s->last_activity,
                    'device' => $s->device ? [
                        'id' => $s->device->id,
                        'name' => $s->device->name,
                        'platform' => $s->device->platform,
                        'browser' => $s->device->browser,
                        'is_verified' => $s->device->is_verified,
                        'last_seen_at' => optional($s->device->last_seen_at)?->toIso8601String(),
                    ] : null,
                    'is_current' => $s->id === session()->getId(),
                ];
            });

        return response()->json($sessions);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        // Delete user_sessions + invalidate Laravel native session row
        if ($id === session()->getId()) {
            return response()->json(['message' => 'Use logout to end your current session.'], 422);
        }

        $session = UserSession::where('user_id', $user->id)->where('id', $id)->firstOrFail();
        $session->delete(); // removes our mirror

        // Also remove from native sessions table
        DB::table('sessions')->where('id', $id)->delete();

        return response()->json(['message' => 'Session revoked.']);
    }

    public function destroyAllExceptCurrent(Request $request)
    {
        $user = $request->user();
        $currentId = session()->getId();

        $ids = UserSession::where('user_id',$user->id)
            ->where('id','<>',$currentId)
            ->pluck('id')
            ->all();

        UserSession::whereIn('id', $ids)->delete();
        \DB::table('sessions')->whereIn('id',$ids)->delete();

        return response()->json(['message' => 'All other sessions revoked.']);
    }

}
