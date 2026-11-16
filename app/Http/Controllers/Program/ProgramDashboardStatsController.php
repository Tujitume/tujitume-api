<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Auth\OrganizationUserRole;
use App\Models\Organizations\Organization;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramWallet;
use App\Models\Programs\SupplierDirectory;
use App\Service\Misc\ErrorLogService;
use App\Service\Organization\OrganizationReviewerService;
use Illuminate\Support\Facades\Auth;

/**
 * GET programs/dashboard-stats
 *
 * One consistent snapshot for the program owner's dashboard cards (see BACKEND_DASHBOARD_STATS_AND_PROGRAM_ANALYTICS.txt §1).
 * Scoped to the organization owner (team members see their owner's data). Numbers, ISO currency and stable keys only —
 * the frontend formats everything.
 */
class ProgramDashboardStatsController extends Controller
{
    public function index(OrganizationReviewerService $reviewerService)
    {
        try {
            $user = Auth::user();
            if ((int) $user->user_type_id !== 4) {
                return response()->json(['success' => false, 'message' => 'Only program organizations have these statistics.'], 403);
            }
            $ownerId = $user->organizationOwnerId();

            $programs = Program::where('user_id', $ownerId)
                ->select(['id', 'status', 'total_program_amount', 'currency', 'application_deadline'])
                ->with('rounds:id,program_id,round_number,status,open_date,close_date')
                ->get();
            Program::annotateApplicationState($programs);
            $programIds = $programs->pluck('id');

            // Applications: one current bucket each (drafts are not submitted)
            $apps = ProgramApplication::whereIn('program_id', $programIds)->get(['id', 'status', 'round_status']);
            $status = fn ($a) => strtolower((string) $a->status);
            $round = fn ($a) => strtolower((string) $a->round_status);
            $submitted = $apps->filter(fn ($a) => $status($a) !== 'draft');

            // Program-level wallets (application wallets would count the same money twice); older data may only have one kind
            $allWallets = ProgramWallet::whereIn('program_id', $programIds)->get();
            $wallets = $allWallets->whereNull('application_id');
            if ($wallets->isEmpty()) $wallets = $allWallets;
            $currencies = $programs->pluck('currency')->filter()->unique()->values();

            // Pending invitations: team members (super admin / editor / viewer) and Business Coaches
            $organization = Organization::where('owner_user_id', $ownerId)->first();
            $pendingTeam = $organization ? OrganizationUserRole::where('organization_id', $organization->id)
                ->where('status', 'pending')
                ->whereHas('role', fn ($q) => $q->whereIn('name', ['super_admin', 'super-admin', 'editor', 'viewer']))
                ->count() : 0;
            $pendingCoaches = $organization ? collect($reviewerService->forOrganization($organization))
                ->filter(fn ($r) => (($r['membership']['status'] ?? null) === 'pending') || (($r['membership_status'] ?? null) === 'pending'))
                ->count() : 0;

            $role = $user->organizationRole?->role?->name ?? 'super-admin';

            return response()->json([
                'success' => true,
                'message' => 'Program dashboard statistics fetched successfully.',
                'data' => [
                    'currency' => $currencies->count() === 1 ? $currencies->first() : null,
                    'funding' => [
                        'program_budget' => (float) $programs->sum('total_program_amount'),
                        'wallet_deposited' => (float) $wallets->sum('total_deposited'),
                        'available' => (float) $wallets->sum('balance'),
                        'committed' => (float) $wallets->sum('total_reserved'),
                        'disbursed' => (float) $wallets->sum('total_disbursed'),
                    ],
                    'programs' => [
                        'total' => $programs->count(),
                        'published' => $programs->where('status', 'published')->count(),
                        'accepting_applications' => $programs->where('accepting_applications', true)->count(),
                        'draft' => $programs->where('status', 'draft')->count(),
                        'completed' => $programs->whereIn('status', ['closed', 'completed', 'finalized'])->count(),
                    ],
                    'applications' => [
                        'total' => $apps->count(),
                        'draft' => $apps->count() - $submitted->count(),
                        'submitted' => $submitted->count(),
                        'awaiting_review' => $submitted->filter(fn ($a) => in_array($round($a), ['submitted', ''], true) && $status($a) !== 'awarded')->count(),
                        'in_review' => $submitted->filter(fn ($a) => in_array($round($a), ['assigned', 'under_review', 'in_review'], true))->count(),
                        'scored' => $submitted->filter(fn ($a) => $round($a) === 'scored')->count(),
                        'advanced' => $submitted->filter(fn ($a) => $round($a) === 'advanced')->count(),
                        'awarded' => $submitted->filter(fn ($a) => in_array($status($a), ['awarded', 'completed'], true))->count(),
                        'not_selected' => $submitted->filter(fn ($a) => $round($a) === 'not_selected' || $status($a) === 'rejected')->count(),
                    ],
                    'requests' => [
                        'pending_team_invitations' => $pendingTeam,
                        'pending_business_coach_invitations' => $pendingCoaches,
                        'pending_total' => $pendingTeam + $pendingCoaches,
                    ],
                    'suppliers' => [
                        'active' => SupplierDirectory::where('user_id', $ownerId)->where('is_active', true)->count(),
                    ],
                    'account' => [
                        'role' => $role,
                        'access' => $user->organizationRole?->role?->access_types ?? ['all'],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['user_id' => Auth::id()]);

            return response()->json(['success' => false, 'message' => 'Something went wrong, please try again later.'], 500);
        }
    }
}
