<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\Services\ServiceBookingMilestone;
use App\Service\Audits\ProjectManagerAudits;
use App\Service\Misc\ErrorLogService;
use App\Service\Services\ServiceBookingFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

/**
 * A booking's milestones and payments (the Milestones & payments panel of the bookings pages):
 *   GET  services/bookings/{booking}/flow                                  customer or provider
 *   POST services/bookings/{booking}/milestones/{milestone}/approve        customer: releases the milestone's payment
 *   POST services/bookings/{booking}/milestones/{milestone}/request-changes customer: back to In Progress with a note
 *   POST services/bookings/{booking}/cancel                                customer: cancels a booking not yet paid
 *   POST services/bookings/{booking}/milestones/{milestone}/audit[/pay|/cancel] customer: optional paid PM audit (DECISION-9)
 * Paying the service fee goes through checkout (purpose s_mile); see ServiceBookingFlow.
 */
class ServiceBookingFlowController extends Controller
{
    public function show(int $booking): JsonResponse
    {
        return $this->answer(fn () => ['data' => ServiceBookingFlow::view(ServiceBookingFlow::forViewer($booking, Auth::user()), Auth::user())]);
    }

    public function approve(int $booking, int $milestone): JsonResponse
    {
        return $this->answer(function () use ($booking, $milestone) {
            [$b, $m] = $this->find($booking, $milestone);
            (new ServiceBookingFlow())->approve($b, $m, Auth::user());

            return ['message' => 'Milestone approved: its payment is on its way to the provider.', 'data' => ServiceBookingFlow::view($b->refresh(), Auth::user())];
        });
    }

    public function requestChanges(Request $request, int $booking, int $milestone): JsonResponse
    {
        $note = trim((string) $request->validate(['note' => 'required|string|min:3|max:1000'])['note']);

        return $this->answer(function () use ($booking, $milestone, $note) {
            [$b, $m] = $this->find($booking, $milestone);
            (new ServiceBookingFlow())->requestChanges($b, $m, Auth::user(), $note);

            return ['message' => 'Changes requested: the provider has been told.', 'data' => ServiceBookingFlow::view($b->refresh(), Auth::user())];
        });
    }

    /** POST services/bookings/{booking}/cancel: the customer cancels a booking they have not paid for. */
    public function cancel(int $booking): JsonResponse
    {
        return $this->answer(function () use ($booking) {
            (new ServiceBookingFlow())->cancel(ServiceBookingFlow::forViewer($booking, Auth::user()), Auth::user());

            return ['message' => 'Booking cancelled: the provider has been told.'];
        });
    }

    /** DECISION-9: the customer asks a project manager to audit a Done milestone before approving it. */
    public function requestAudit(Request $request, int $booking, int $milestone): JsonResponse
    {
        $data = $request->validate(['auditor_id' => 'required|integer', 'notes' => 'nullable|string|max:2000']);

        return $this->answer(function () use ($booking, $milestone, $data) {
            [$b, $m] = $this->find($booking, $milestone);
            (new ProjectManagerAudits())->serviceRequest($b, $m, Auth::user(), (int) $data['auditor_id'], trim((string) ($data['notes'] ?? '')) ?: null);

            return ['message' => 'Audit requested: the project manager will answer with their fee.', 'data' => ServiceBookingFlow::view($b->refresh(), Auth::user())];
        });
    }

    public function payAudit(int $booking, int $milestone): JsonResponse
    {
        return $this->answer(function () use ($booking, $milestone) {
            [$b, $m] = $this->find($booking, $milestone);
            (new ProjectManagerAudits())->servicePay($b, $m, Auth::user());

            return ['message' => 'Audit fee paid. It is held until the report is in.', 'data' => ServiceBookingFlow::view($b->refresh(), Auth::user())];
        });
    }

    public function cancelAudit(int $booking, int $milestone): JsonResponse
    {
        return $this->answer(function () use ($booking, $milestone) {
            [$b, $m] = $this->find($booking, $milestone);
            (new ProjectManagerAudits())->serviceCancel($b, $m, Auth::user());

            return ['message' => 'Audit cancelled. Any fee held was refunded.', 'data' => ServiceBookingFlow::view($b->refresh(), Auth::user())];
        });
    }

    private function find(int $bookingId, int $milestoneId): array
    {
        $booking = ServiceBookingFlow::forViewer($bookingId, Auth::user());
        $milestone = ServiceBookingMilestone::where('booking_id', $booking->id)->find($milestoneId);
        if (! $milestone) ServiceBookingFlow::refuse('Milestone not found.', 404);

        return [$booking, $milestone];
    }

    private function answer(callable $work): JsonResponse
    {
        try {
            return response()->json($work());
        } catch (RuntimeException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        } catch (Throwable $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }
}
