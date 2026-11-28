<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Milestones\NonCompliance\MilestoneNonCompliance;
use App\Service\Noncompliance\NonCompliantService;
use Illuminate\Http\Request;

/**
 * Admin panel › Non-compliance: business milestones that missed their deadline (NonCompliantService). Tujitume settles a
 * platform dispute here (investors froze or disputed the milestone, or nobody voted): let it continue with 7 more days,
 * or sanction the business now. Left alone, a dispute sanctions the business after 14 days.
 */
class NonComplianceAdminController extends Controller
{
    private const TABS = ['disputes' => ['pid'], 'open' => ['response_window', 'ipm'], 'closed' => ['resolved', 'sanctioned'], 'all' => null];

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'disputes';
        $stages = self::TABS[$tab];

        $cases = MilestoneNonCompliance::with(['milestone.listing.owner'])
            ->when($stages, fn ($q) => $q->whereIn('stage', $stages))
            ->latest('updated_at')->paginate(25)->withQueryString();
        $counts = collect(self::TABS)->map(fn ($s) => $s ? MilestoneNonCompliance::whereIn('stage', $s)->count() : MilestoneNonCompliance::count());

        return view('admin.non_compliance.index', ['cases' => $cases, 'tab' => $tab, 'counts' => $counts, 'pidDays' => NonCompliantService::PID_DAYS]);
    }

    public function resolve(Request $request, MilestoneNonCompliance $case, NonCompliantService $service)
    {
        $data = $request->validate(['outcome' => 'required|in:continue,sanction']);

        if (! $service->resolveDispute($case, $data['outcome'])) {
            return back()->with('error', 'This case is no longer an open dispute; nothing was changed.');
        }

        return back()->with('success', $data['outcome'] === 'continue'
            ? 'Dispute settled: the milestone is unlocked with 7 more days. The owner and investors were told.'
            : 'The business is sanctioned. The owner and investors were told.');
    }
}
