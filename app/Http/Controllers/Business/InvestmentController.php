<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Service\Misc\ErrorLogService;
use Illuminate\Support\Facades\Auth;

class InvestmentController extends Controller
{
    public function index(){
        try{
            if( !Auth::check() ){
                return response()->json(['message' => 'Unauthorized!' ],401);
            }
            $user = Auth::user();
            $user_id = $user->id;

            $pendingInvestments = BusinessBids::with('milestone.listing')
                ->where('investor_id', $user_id)->latest()->get();

            $investments = AcceptedBids::with('milestone.listing')
                ->where('investor_id', $user_id)->latest()->get();
            $awaiting_payment = $investments->where('status', 'awaiting_payment');

            return response()->json([
                'pending_investments' => $pendingInvestments,
                'investments' => $investments,
                'awaiting_payment' => $awaiting_payment,
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);

            return response()->json([
                //'message' => 'Something went wrong, please try again later.',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
