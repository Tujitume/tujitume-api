<?php

namespace App\Http\Controllers\Misc;
use App\Http\Controllers\Controller;
use App\Models\Shared\Watchlist;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WatchlistController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {

        try{
            $user = Auth::user();
            $type = $user->user_type_id;

            // SME can bookmark G/C
            if($type == 5 || $type == 4 || $type == 3 || $type == 2){
                $watchlists1 = Watchlist::with('program')->where('user_id', $user->id)
                    ->where('org_type', 'program')->latest()->get();

                $watchlists2 = Watchlist::with('capital')->where('user_id', $user->id)
                    ->where('org_type', 'capital')->latest()->get();

                $watchlists3 = Watchlist::with('listing')->where('user_id', $user->id)
                    ->where('org_type', 'listing')->latest()->get();

                $watchlists4 = Watchlist::with('service')->where('user_id', $user->id)
                    ->where('org_type', 'service')->latest()->get();

                $program_lists = $watchlists1->pluck('program')->filter()->values();
                $capital_lists = $watchlists2->pluck('capital')->filter()->values();
                $listing_lists = $watchlists3->pluck('listing')->filter()->values();
                $service_lists = $watchlists4->pluck('service')->filter()->values();
                $org_lists = [
                    'programs' => $program_lists,
                    'capitals' => $capital_lists,
                    'listings' => $listing_lists,
                    'services' => $service_lists,
                ];
                return response()->json(['watchlists' => $org_lists],200);
            }
            else{
                // Investors can bookmark Listings
                $watchlists = Watchlist::with('listing')->where('user_id', $user->id)
                    ->where('org_type', 'listing')->latest()->get();
                //service
                $watchlists2 = Watchlist::with('service')->where('user_id', $user->id)
                    ->where('org_type', 'service')->latest()->get();

                $org_lists = [
                    'listings' => $watchlists,
                    'services' => $watchlists2,
                ];
                return response()->json(['watchlists' => $org_lists],200);
            }


        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try{
            $request->validate([
                'org_id' => 'required|integer',
                'org_type' => 'required|string|in:program,capital,listing',
            ]);

            $user_id = Auth::id();
            $watchlist = Watchlist::where('org_id',$request->org_id)
                ->where('user_id',$user_id)->first();
            if ($watchlist){
                $watchlist->delete();
                return response()->json(['message' => 'Removed from watchlist'],200);
            }
            else
            {
                Watchlist::create([
                    'user_id' => $user_id,
                    'org_id' => $request->org_id,
                    'org_type' => $request->org_type,
                ]);
                return response()->json(['message' => 'Added to watchlist'],200);
            }
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
