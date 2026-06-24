<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Auth\UserDevice;
use App\Models\Auth\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        $devices = UserDevice::where('user_id',$request->user()->id)
            ->withCount('sessions')
            ->orderByDesc('last_seen_at')
            ->get();

        return response()->json($devices);
    }

    public function update(Request $request, UserDevice $device)
    {
        abort_unless($device->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'name' => ['nullable','string','max:100'],
        ]);

        $device->update(['name' => $data['name'] ?? $device->name]);

        return response()->json(['message' => 'Device updated.']);
    }

    public function destroy(Request $request, UserDevice $device)
    {
        abort_unless($device->user_id === $request->user()->id, 403);

        // Delete device and kill its active sessions
        $sessionIds = UserSession::where('user_device_id',$device->id)->pluck('id')->all();
        DB::table('sessions')->whereIn('id', $sessionIds)->delete();
        UserSession::whereIn('id', $sessionIds)->delete();

        $device->delete();

        return response()->json(['message' => 'Device removed and related sessions ended.']);
    }
}
