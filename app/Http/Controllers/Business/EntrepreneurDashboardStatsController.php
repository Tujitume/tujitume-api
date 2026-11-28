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
            // Released to the owner: the milestone money really paid out to them (75% / 25% releases), not what investors put in
            $received = (float) \App\Models\Finance\Transactions::where('user_id', $userId)->where('type', 'business_milestone')->sum('gross_amount');

            return response()->json([
                'success' => true,
                'message' => 'Entrepreneur dashboard statistics fetched successfully.',
                'data' => [
                    // Business money (raised, released) is stored in USD; program amounts carry their own currency below
                    'currency' => 'USD',
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
                        'awarded' => $submitted->filter(fn ($a) => in_array($status($a), ['awarded', 'in_monitoring', 'completed'], true))->count(),
                    ],
                    'capital' => [
                        'total_raised' => (float) $listings->sum(fn ($l) => (float) $l->amount_collected),
                        'disbursed' => $received,
                        'pending_review' => StartupPitches::where('user_id', $userId)->where('status', 0)->count(),
                    ],
                    // The applications ring and the recent applications table under the cards
                    'application_statuses' => $apps->groupBy($status)->map->count()->sortDesc()->map(fn ($c, $s) => ['status' => $s ?: 'unknown', 'count' => $c])->values(),
                    'capital_application_statuses' => $this->capitalStatuses($userId),
                    'recent_applications' => $this->recentApplications($userId),
                ],
            ]);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['user_id' => Auth::id()]);

            return response()->json(['success' => false, 'message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /** Capital applications (startup pitches) by status: pending | accepted | rejected (StartupPitches::STATUS_*). */
    private function capitalStatuses(int $userId): array
    {
        return StartupPitches::where('user_id', $userId)->get(['status'])
            ->groupBy(fn ($p) => $this->capitalStatus($p->status))
            ->map->count()->sortDesc()
            ->map(fn ($c, $s) => ['status' => $s, 'count' => $c])->values()->all();
    }

    private function capitalStatus(mixed $status): string
    {
        return match ((int) $status) {
            StartupPitches::STATUS_ACCEPTED => 'accepted',
            StartupPitches::STATUS_REJECTED => 'rejected',
            default => 'pending',
        };
    }

    /**
     * The 8 newest applications across Programs and Capital (program drafts included, so unfinished ones are easy to
     * find). Each row says where it came from: source = program | capital.
     */
    private function recentApplications(int $userId): array
    {
        $programs = ProgramApplication::with('program:id,program_title,currency')
            ->where('user_id', $userId)
            ->latest()
            ->limit(8)
            ->get(['id', 'program_id', 'status', 'startup_name', 'total_amount_requested', 'awarded_amount', 'created_at'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'source' => 'program',
                'title' => $a->program?->program_title,
                'business_name' => $a->startup_name,
                'status' => strtolower((string) $a->status),
                'requested' => (float) $a->total_amount_requested,
                'awarded' => (float) $a->awarded_amount,
                'currency' => $a->program?->currency,
                'created_at' => $a->created_at?->toIso8601String(),
            ]);

        $capital = StartupPitches::with('capital_offer:id,offer_title')
            ->where('user_id', $userId)
            ->latest()
            ->limit(8)
            ->get(['id', 'capital_id', 'status', 'startup_name', 'total_amount_requested', 'created_at'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'source' => 'capital',
                'title' => $p->capital_offer?->offer_title,
                'business_name' => $p->startup_name,
                'status' => $this->capitalStatus($p->status),
                'requested' => (float) $p->total_amount_requested,
                'awarded' => 0.0,
                'currency' => null,
                'created_at' => $p->created_at?->toIso8601String(),
            ]);

        return $programs->concat($capital)->sortByDesc('created_at')->take(8)->values()->all();
    }
}
