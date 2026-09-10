<?php

namespace App\Http\Controllers;

use App\Events\NewNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TestNotificationController extends Controller
{
    /**
     * Send a test notification to a user
     * POST /api/v1/test/send-notification
     * 
     * Body:
     * {
     *   "user_id": 123,
     *   "message": "Your test notification message"
     * }
     */
    public function sendTestNotification(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'message' => 'required|string|max:500',
        ]);

        $user = User::find($validated['user_id']);

        // Create notification record in database
        $notification = DB::table('notifications')->insertGetId([
            'user_id' => $user->id,
            'title' => 'Test Notification',
            'message' => $validated['message'],
            'type' => 'test',
            'link' => null,
            'is_read' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Broadcast real-time notification
        broadcast(new NewNotification([
            'id' => $notification,
            'user_id' => $user->id,
            'title' => 'Test Notification',
            'message' => $validated['message'],
            'type' => 'test',
            'created_at' => now()->toISOString(),
        ], $user->id));

        return response()->json([
            'success' => true,
            'message' => 'Test notification sent successfully',
            'data' => [
                'notification_id' => $notification,
                'user_id' => $user->id,
                'user_name' => $user->first_name . ' ' . $user->last_name,
                'message' => $validated['message'],
            ]
        ]);
    }
}
