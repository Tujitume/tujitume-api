<?php

namespace App\Http\Controllers\Capital;

use App\Http\Controllers\Controller;
use App\Models\Capital\CapitalMilestone;
use App\Service\Audits\CapitalAudits;
use App\Service\Capital\CapitalAccess;
use App\Service\Capital\CapitalPortfolioList;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * The capital provider's side of a project manager audit before a release (DECISION-9, CapitalAudits), and the
 * portfolio of funded businesses.
 *   POST capital/milestones/{milestone}/audit          {auditor_id, notes?}  ask a project manager (owner / super admin)
 *   POST capital/milestones/{milestone}/audit/pay                            pay the quoted fee into escrow
 *   POST capital/milestones/{milestone}/audit/cancel   {reason?}             cancel before the report (fee refunded)
 *   GET  capital/portfolio                                                   CapitalPortfolioList (owner or team)
 */
class CapitalAuditController extends Controller
{
    public function __construct(private CapitalAudits $audits) {}

    public function request(Request $request, CapitalMilestone $milestone): JsonResponse
    {
        $data = $request->validate(['auditor_id' => 'required|integer', 'notes' => 'nullable|string|max:2000']);

        return $this->guard(function () use ($request, $milestone, $data) {
            $this->audits->request($milestone, $request->user(), (int) $data['auditor_id'], trim((string) ($data['notes'] ?? '')) ?: null);

            return response()->json(['message' => 'Audit requested. The project manager was asked to accept it with their fee. Release waits for the report, or until you cancel the audit.']);
        });
    }

    public function pay(Request $request, CapitalMilestone $milestone): JsonResponse
    {
        return $this->guard(function () use ($request, $milestone) {
            $this->audits->pay($milestone, $request->user());

            return response()->json(['message' => 'Audit fee paid. It is held until the report is in, then paid to the project manager.']);
        });
    }

    public function cancel(Request $request, CapitalMilestone $milestone): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $this->guard(function () use ($request, $milestone, $data) {
            $this->audits->cancel($milestone, $request->user(), trim((string) ($data['reason'] ?? '')) ?: null);

            return response()->json(['message' => 'Audit cancelled. Any fee held was refunded; you can release the milestone yourself.']);
        });
    }

    public function portfolio(Request $request): JsonResponse
    {
        if (! CapitalAccess::isCapital($request->user())) {
            return response()->json(['message' => 'Only capital providers have a portfolio.'], 403);
        }

        return (new CapitalPortfolioList($request->user()))->respond($request);
    }

    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            $code = $e->getCode();

            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code < 600 ? $code : 422);
        } catch (Throwable $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }
}
