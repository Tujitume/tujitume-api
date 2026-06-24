<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\Listing;
use App\Models\Milestones\MilestoneCommunications;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DealroomController extends Controller
{
    public function businesses(){
        try{
            if( !Auth::check() ){
                return response()->json(['message' => 'Unauthorized!' ],401);
            }
            $user = Auth::user();

            $listings = Listing::with('milestones')
                ->withCount([
                    'milestones as completed_milestones' => function ($q) {
                        $q->where('status', 'done');
                    },
                    'milestones as active_milestones' => function ($q) {
                        $q->where('status', '!=', 'done')
                            ->where('status', '!=', 'locked');
                    },
                ])
                ->where('user_id', $user->id)->get();

            if($listings->count() < 1){
                return response()->json(['message' => 'No listings found.'], 404);
            }

            foreach($listings as $listing){
                $listing->pending_investor_count = $listing->pending_investors()->count();
            }

            // Return as JSON or pass to view
            return response()->json([
                'listings' => $listings,
                //'milestones' => $milestones
            ], 200);
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

    public function business_stats($businessId){
        try{
            if( !Auth::check() ){
                return response()->json(['message' => 'Unauthorized!' ],401);
            }
            $user = Auth::user();

            $listing = Listing::with('milestones')
                ->withCount([
                    'milestones as completed_milestones' => function ($q) {
                        $q->where('status', 'done');
                    },
                    'milestones as active_milestones' => function ($q) {
                        $q->where('status', '!=', 'done')
                            ->where('status', '!=', 'locked');
                    },
                ])
                ->where('id', $businessId)->first();

            if(!$listing){
                return response()->json(['message' => 'No listing found.'], 404);
            }
            $listing->avg_investment = $listing->invest_count > 0
                ? ($listing->amount_collected / $listing->invest_count)
                : 0;

            // Return as JSON or pass to view
            return response()->json([
                'listing' => $listing,
                //'milestones' => $milestones
            ], 200);
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


    public function participants($businessId){
        try{
            if( !Auth::check() ){
                return response()->json(['message' => 'Unauthorized!' ],401);
            }

            $listing = Listing::with([
                'milestones'       // preloads all milestones
            ])->find($businessId);

            if(!$listing){
                return response()->json(['message' => 'No listing found.'], 404);
            }

            // Pending Investors
            $pendingInvestors = $listing->pending_investors()
                ->select('users.id', 'users.fname','users.lname','users.email')
                ->get(); // returns a Collection of User models
            //$total_investors = $investors->count();

            $pendingInvestors->map(function ($investor) use ($listing){
                $investor->amount = $investor->pivot->amount ?? 0;
                $investor->percentage = $investor->pivot->representation ?? 0;
                $investor->investment_date = $investor->pivot->date ?? null;

                // Add milestone object if ms_id exists
                $investor->milestones = $investor->pivot->ms_id
                    ? $listing->milestones->where('id', $investor->pivot->ms_id)->first()
                    : null;

                return $investor;
            });


            // Active Investors
            $investors = $listing->investors()
                ->select('users.id', 'users.fname','users.lname','users.email')
                ->get(); // returns a Collection of User models
            $total_investors = $investors->count();

            $investors->map(function ($investor) use ($listing){
                $investor->amount = $investor->pivot->amount ?? 0;
                $investor->percentage = $investor->pivot->representation ?? 0;
                $investor->investment_date = $investor->pivot->date ?? null;

                // Add milestone object if ms_id exists
                $investor->milestones = $investor->pivot->ms_id
                    ? $listing->milestones->where('id', $investor->pivot->ms_id)->first()
                    : null;

                return $investor;
            });

            $stats = collect([
                'total_investors' => $listing->invest_count,
                'total_investment' => $listing->investment_needed,
                'avg_investment' => $listing->invest_count > 0
                    ? ($listing->amount_collected / $listing->invest_count)
                    : 0,
            ]);

            // Return as JSON or pass to view
            return response()->json([
                'pending_investors' => $pendingInvestors,
                'investors' => $investors,
                'stats' => $stats
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                //'message' => 'Something went wrong, please try again later.'
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // C o m m u n i c a t i o n s
    public function communications($milestoneId)
    {
        try{
            $rmep = MilestoneCommunications::with('sender')
                ->where('milestone_id', $milestoneId)->get();

            return response()->json([
                'data'    => $rmep
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }

    public function communications_store(Request $request)
    {
        try{
            $validated = $request->validate([
                'message'     => ['required', 'string'],
                'milestone_id'   => ['required', 'integer', 'exists:milestones,id'],
                //'sender_id'   => ['required', 'integer', 'exists:users,id'],
                'sender_type' => ['nullable', 'string'],
                'type'        => ['required', 'string'],
            ]);

            $sender = Auth::user();

            $communication = MilestoneCommunications::create([
                'message'     => $validated['message'],
                'milestone_id'   => $validated['milestone_id'],
                'sender_id'   => $sender->id,
                'sender_type' => $validated['sender_type'] ?? null,
                'type'        => $validated['type'] ?? null,
            ]);

            return response()->json([
                'message' => 'Communication created successfully.',
                'data'    => $communication
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }


}
