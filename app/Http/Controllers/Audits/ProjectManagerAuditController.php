<?php

namespace App\Http\Controllers\Audits;

use App\Http\Controllers\Controller;
use App\Models\Capital\CapitalMilestone;
use App\Models\Programs\MilestoneVerification;
use App\Models\Services\ServiceBookingMilestone;
use App\Service\Audits\CapitalAudits;
use App\Service\Audits\ProjectManagerAudits;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Project Manager Audits: one list per stage (pre-release, mid-milestone, final approval) of every audit the signed-in
 * project manager was asked to do: business (investors chose them) and program (a program owner asked them).
 * Business audits are answered and decided through MilestonePMController (audit-request, audits/{id}/review);
 * program audits are answered and reported here.
 */
class ProjectManagerAuditController extends Controller
{
    public function __construct(private ProjectManagerAudits $audits) {}

    public function summary(Request $request)
    {
        return $this->guard(fn () => response()->json(['data' => $this->audits->summary($request->user())]));
    }

    /** Program owners: who they can ask to audit (service providers, project managers first). */
    public function candidates(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:100']);

        return $this->guard(fn () => response()->json(['data' => $this->audits->candidates($request->user(), $data['search'] ?? null)]));
    }

    public function index(Request $request, string $stage)
    {
        return $this->guard(function () use ($request, $stage) {
            $rows = $this->audits->list($request->user(), $stage);
            $source = $request->query('source');
            if (in_array($source, ProjectManagerAudits::SOURCES, true)) $rows = $rows->where('source', $source)->values();

            return response()->json(['data' => $rows]);
        });
    }

    public function programRespond(Request $request, MilestoneVerification $verification)
    {
        // DECISION-9: accepting quotes the fee (0 = free); with a fee the audit starts once the program team pays it
        $data = $request->validate(['action' => 'required|in:accept,decline', 'fee' => 'nullable|numeric|min:0|max:10000000']);

        return $this->guard(function () use ($request, $verification, $data) {
            $fee = (float) ($data['fee'] ?? 0);
            $this->audits->programRespond($verification, $request->user(), $data['action'], $fee);

            return response()->json(['message' => match (true) {
                $data['action'] !== 'accept' => 'Audit declined. The program owner was told.',
                $fee > 0 => 'Audit accepted. The program team was asked to pay your fee; you can start once it is paid.',
                default => 'Audit accepted.',
            }]);
        });
    }

    /** POST pm-audits/{source}/{id}/retry-fee: the project manager asks again for a fee whose payout failed. */
    public function retryFee(Request $request, string $source, int $id)
    {
        return $this->guard(function () use ($request, $source, $id) {
            $status = \App\Service\Audits\AuditFee::retry($source, $id, $request->user());
            $paid = in_array($status, ['released', 'deducted'], true); // deducted: a business fee already taken off the release

            return response()->json([
                'message' => $paid
                    ? 'Your audit fee was sent to your M-Pesa (LIPR) wallet.'
                    : 'The payout did not go through again. Check your M-Pesa (LIPR) wallet and try later.',
                'data' => ['fee_status' => $status],
            ], $paid ? 200 : 502);
        });
    }

    /** PATCH verifications/{verification}/pay-audit: the program team pays the quoted fee into escrow (organization wallet). */
    public function programPay(Request $request, MilestoneVerification $verification)
    {
        return $this->guard(function () use ($request, $verification) {
            $this->audits->programPay($verification, $request->user());

            return response()->json(['message' => 'Audit fee paid. It is held until the report is in, then paid to the project manager.', 'data' => $verification->fresh()]);
        });
    }

    /** PATCH verifications/{verification}/skip-audit: DECISION-8 change, the owner team reviews it themselves. */
    public function programSkip(Request $request, MilestoneVerification $verification)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $this->guard(function () use ($request, $verification, $data) {
            $this->audits->skip($verification, $request->user(), trim((string) ($data['reason'] ?? '')) ?: null);

            return response()->json(['message' => 'Audit skipped. You can now decide this check yourself.', 'data' => $verification->fresh()]);
        });
    }

    /** PATCH verifications/{verification}/cancel-audit: before the report; a held fee is refunded. */
    public function programCancel(Request $request, MilestoneVerification $verification)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $this->guard(function () use ($request, $verification, $data) {
            $this->audits->programCancel($verification, $request->user(), trim((string) ($data['reason'] ?? '')) ?: null);

            return response()->json(['message' => 'Audit cancelled. Any fee held was refunded; you can decide this check yourself.', 'data' => $verification->fresh()]);
        });
    }

    /** POST pm-audits/service/{milestone}/respond: the PM answers a service booking audit (accept with a fee, or decline). */
    public function serviceRespond(Request $request, ServiceBookingMilestone $milestone)
    {
        $data = $request->validate(['action' => 'required|in:accept,decline', 'fee' => 'nullable|numeric|min:0|max:10000000']);

        return $this->guard(function () use ($request, $milestone, $data) {
            $this->audits->serviceRespond($milestone, $request->user(), $data['action'], (float) ($data['fee'] ?? 0));

            return response()->json(['message' => $data['action'] === 'accept' ? 'Audit accepted. The customer was told.' : 'Audit declined. The customer was told.']);
        });
    }

    public function serviceReport(Request $request, ServiceBookingMilestone $milestone)
    {
        $data = $request->validate(['recommendation' => 'required|in:approve,reject', 'notes' => 'required|string|min:10|max:5000']);

        return $this->guard(function () use ($request, $milestone, $data) {
            $this->audits->serviceReport($milestone, $request->user(), $data['recommendation'], $data['notes']);

            return response()->json(['message' => 'Report sent. The customer will decide.']);
        });
    }

    /** POST pm-audits/capital/{milestone}/respond: the PM answers a capital audit (accept with a fee, or decline). */
    public function capitalRespond(Request $request, CapitalMilestone $milestone)
    {
        $data = $request->validate(['action' => 'required|in:accept,decline', 'fee' => 'nullable|numeric|min:0|max:10000000']);

        return $this->guard(function () use ($request, $milestone, $data) {
            $fee = (float) ($data['fee'] ?? 0);
            app(CapitalAudits::class)->respond($milestone, $request->user(), $data['action'], $fee);

            return response()->json(['message' => match (true) {
                $data['action'] !== 'accept' => 'Audit declined. The capital provider was told.',
                $fee > 0 => 'Audit accepted. The capital provider was asked to pay your fee; you can start once it is paid.',
                default => 'Audit accepted. The capital provider was told.',
            }]);
        });
    }

    /**
     * POST pm-audits/capital/{milestone}/report (multipart): recommendation approve (release) | reject (changes first),
     * notes, evidence[] (up to 5 files). The fee is paid to the PM; the capital provider decides the release.
     */
    public function capitalReport(Request $request, CapitalMilestone $milestone)
    {
        $data = $request->validate([
            'recommendation' => 'required|in:approve,reject',
            'notes' => 'required|string|min:10|max:5000',
            'evidence' => 'nullable|array|max:5',
            'evidence.*' => 'file|mimes:pdf,doc,docx,xls,xlsx,csv,png,jpg,jpeg,webp|max:10240',
        ]);

        return $this->guard(function () use ($request, $milestone, $data) {
            app(CapitalAudits::class)->report($milestone, $request->user(), $data['recommendation'], $data['notes'], $request->file('evidence') ?? []);

            return response()->json(['message' => 'Report sent. The capital provider will decide the release.']);
        });
    }

    public function programReport(Request $request, MilestoneVerification $verification)
    {
        $data = $request->validate([
            'recommendation' => 'required|in:approve,reject',
            'notes' => 'required|string|min:10|max:5000',
        ]);

        return $this->guard(function () use ($request, $verification, $data) {
            $this->audits->programReport($verification, $request->user(), $data['recommendation'], $data['notes']);

            return response()->json(['message' => 'Report sent. The program owner will decide.']);
        });
    }

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code < 500 ? $code : 422);
        } catch (Throwable $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }
}
