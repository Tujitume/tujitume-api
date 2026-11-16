<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Listing;
use App\Models\Capital\StartupPitches;
use App\Models\Milestones\Milestones;
use App\Models\Programs\ProgramApplication;
use App\Service\Misc\ErrorLogService;
use Illuminate\Support\Facades\Auth;

/**
 * GET dashboard/entrepreneur/stats
 *
 * The three entrepreneur dashboard cards (Businesses, Programs, Capital) in one snapshot, scoped to the signed-in user
 * (see BACKEND_DASHBOARD_STATS_AND_PROGRAM_ANALYTICS.txt §2). Numbers only; empty categories are zero, never null.
 */
class EntrepreneurDashboardStatsController extends Controller
{
    public function index()
    {
        try {
            $userId = Auth::id();

            // Businesses
            $listings = Listing::where('user_id', $userId)->get(['id', 'active', 'amount_collected']);
            $listingIds = $listings->pluck('id');
            $activeMilestones = $listingIds->isEmpty() ? 0 : Milestones::whereIn('listing_id', $listingIds)
                ->where('status', '!=', 'done')
                ->where(fn ($q) => $q->where('active', 1)->orWhereNull('active'))
                ->count();
            $pendingBids = BusinessBids::where('owner_id', $userId)->distinct('id')->count('id');

            // Programs: one application per program counts once; drafts are not submitted
            $apps = ProgramApplication::where('user_id', $userId)->get(['id', 'program_id', 'status']);
            $status = fn ($a) => strtolower((string) $a->status);
            $submitted = $apps->filter(fn ($a) => $status($a) !== 'draft');
            $live = $submitted->filter(fn ($a) => in_array($status($a), ['pending', 'submitted', 'under_review', 'in_review', 'approved', 'awarded'], true));

            // Capital: raised from investors on the user's businesses, released to them, and capital applications awaiting a decision
            $received = $listingIds->isEmpty() ? 0 : (float) AcceptedBids::whereIn('business_id', $listingIds)
                ->whereIn('status', ['Confirmed', 'verified', 'equipment_released'])
                ->sum('amount');

            return response()->json([
                'success' => true,
                'message' => 'Entrepreneur dashboard statistics fetched successfully.',
                'data' => [
                    'currency' => config('app.currency', env('CURRENCY')),
                    'businesses' => [
                        'total' => $listings->count(),
                        'active' => $listings->filter(fn ($l) => (bool) $l->active)->count(),
                        'active_milestones' => $activeMilestones,
                        'investment_bids' => $pendingBids,
                    ],
                    'programs' => [
                        'enrolled' => $submitted->pluck('program_id')->unique()->count(),
                        'applications' => $apps->count(),
                        'draft_applications' => $apps->count() - $submitted->count(),
                        'submitted_applications' => $submitted->count(),
                        'active_programs' => $live->pluck('program_id')->unique()->count(),
                        'awarded' => $submitted->filter(fn ($a) => in_array($status($a), ['awarded', 'completed'], true))->count(),
                    ],
                    'capital' => [
                        'total_raised' => (float) $listings->sum(fn ($l) => (float) $l->amount_collected),
                        'disbursed' => $received,
                        'pending_review' => StartupPitches::where('user_id', $userId)->where('status', 0)->count(),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['user_id' => Auth::id()]);

            return response()->json(['success' => false, 'message' => 'Something went wrong, please try again later.'], 500);
        }
    }
}
