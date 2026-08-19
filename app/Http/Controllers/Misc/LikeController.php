<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Models\Communication\Notifications;
use App\Models\Shared\Like;
use App\Models\Shared\Watchlist;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LikeController extends Controller
{
    public function index()
    {
        try {
            $user_id = Auth::id();
            $user_type = Auth::user()->user_type_id;
            $likes = Like::where('user_id', $user_id)
                ->whereIn('type', ['listing', 'service'])
                ->with(['listing', 'service'])->get();

            $response = [
                'listings' => $likes->where('type', 'listing')
                    ->pluck('listing')
                    ->filter()
                    ->values(),

                'services' => $likes->where('type', 'service')
                    ->pluck('service')
                    ->filter()
                    ->values(),
            ];

            return response()->json($response, 200);


        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }


    public function toggle(Request $request)
    {
        try {
            $request->validate([
                'type' => 'required|in:listing,service,program,capital'
            ]);
            $listing_id = $request->listing_id;
            $type = $request->type;
            $user_id = Auth::id();

            $liked = Like::where([
                'listing_id' => $listing_id,
                'user_id' => $user_id,
                'type'    => $type,
            ])->first();

            if ($liked) {
                $liked->delete();

                // Remove from watchlist
                $watchlist = Watchlist::where('org_id',$listing_id)
                    ->where('user_id',$user_id)
                    ->where('org_type', $type)->first();

                if ($watchlist){
                    $watchlist->delete();
                    return response()->json(['message' => 'Removed from watchlist'],200);
                }
                return response()->json(['message' => 'Unliked.'], 200);
            } else {
                Like::create([
                    'listing_id' => $listing_id,
                    'user_id' => $user_id,
                    'type'    => $type,
                ]);

                // Add to watchlist
                Watchlist::create([
                    'user_id' => $user_id,
                    'org_id' => $listing_id,
                    'org_type' => $type,
                ]);

                return response()->json(['message' => 'You liked this '.$type], 200);
            }
        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function clear_notification(Request $request)
    {
        $user = Auth::user();

        if ($request->id) {
            // 🔹 Clear single notification
            $deleted = $user->notifications()->where('id', $request->id)
                ->update([
                    'visible' => 0
                ]);

            if ($deleted) {
                return response()->json([
                    'message' => 'NotificationService cleared'
                ], 200);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'NotificationService not found'
            ], 404);
        }

        // 🔹 Clear all notifications
        $user->notifications()->update([
            'visible' => 0
        ]);
        return response()->json([
            'status' => 'success',
            'message' => 'All notifications cleared'
        ],200);
    }

    public function update_notification(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:notifications,id',
        ]);

        $not = Notifications::find($request->id);
        $not->update([ 'link' => '/' ]);

        return response()->json([
            'message' => 'NotificationService link updated'
        ], 200);
    }

}
