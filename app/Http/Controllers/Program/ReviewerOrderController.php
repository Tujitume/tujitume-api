<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\ReviewerOrder;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\ProgramNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewerOrderController extends Controller
{
    public function __construct(
        private ProgramNotificationService $notification,
    ) {}

    // ─── List reviewer's own orders ──────────────────────────────
    // GET /api/v1/programs/reviewer/orders
    public function myOrders()
    {
        $orders = ReviewerOrder::where('reviewer_id', Auth::id())
            ->with(['program', 'round', 'siteVisit'])
            ->latest()
            ->get()
            ->map(fn($order) => [
                ...$order->toArray(),
                'work_status' => [
                    'value' => $order->work_status,
                    'color' => config('status.reviewer_order.' . $order->work_status, 'gray'),
                ],
                'payment_status' => [
                    'value' => $order->payment_status,
                    'color' => config('status.reviewer_payment.' . $order->payment_status, 'gray'),
                ],
            ]);

        return response()->json(['data' => $orders], 200);
    }

    // ─── PO views all orders for a round ─────────────────────────
    // GET /api/v1/programs/rounds/{round}/reviewer-orders
    public function roundOrders(ProgramRound $round)
    {
        if ($round->program->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $orders = ReviewerOrder::where('round_id', $round->id)
            ->with(['reviewer'])
            ->get()
            ->map(fn($order) => [
                ...$order->toArray(),
                'work_status' => [
                    'value' => $order->work_status,
                    'color' => config('status.reviewer_order.' . $order->work_status, 'gray'),
                ],
                'payment_status' => [
                    'value' => $order->payment_status,
                    'color' => config('status.reviewer_payment.' . $order->payment_status, 'gray'),
                ],
            ]);

        return response()->json(['data' => $orders], 200);
    }

    // ─── Reviewer marks work as delivered ────────────────────────
    // POST /api/v1/programs/reviewer-orders/{order}/deliver
    public function deliver(Request $request, ReviewerOrder $order)
    {
        if ($order->reviewer_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!in_array($order->work_status, ['assigned', 'in_progress', 'modification_requested'])) {
            return response()->json([
                'error' => 'Cannot deliver from current status: ' . $order->work_status
            ], 422);
        }

        try {
            $validated = $request->validate([
                'delivery_note' => 'nullable|string|max:1000',
            ]);

            $order->update([
                'work_status'   => 'delivered',
                'delivery_note' => $validated['delivery_note'] ?? null,
                'delivered_at'  => now(),
            ]);

            // Notify program owner
            $this->notification->send('reviewer.work_delivered', [
                $order->program->owner
            ], [
                'program_title'  => $order->program->program_title,
                'reviewer_name'  => Auth::user()->first_name . ' ' . Auth::user()->last_name,
                'order_type'     => $order->order_type,
                'round_name'     => $order->round?->round_name,
                'order_id'       => $order->id,
            ]);

            return response()->json([
                'message' => 'Work marked as delivered. Program owner notified.',
                'data'    => $order->fresh(),
            ], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // ─── PO requests modification ────────────────────────────────
    // POST /api/v1/programs/reviewer-orders/{order}/request-modification
    public function requestModification(Request $request, ReviewerOrder $order)
    {
        if ($order->program->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($order->work_status !== 'delivered') {
            return response()->json([
                'error' => 'Can only request modification on delivered work'
            ], 422);
        }

        try {
            $validated = $request->validate([
                'modification_note' => 'required|string|max:1000',
            ]);

            $order->update([
                'work_status'       => 'modification_requested',
                'modification_note' => $validated['modification_note'],
            ]);

            // Notify reviewer
            $this->notification->send('reviewer.modification_requested', [
                $order->reviewer
            ], [
                'program_title'     => $order->program->program_title,
                'modification_note' => $validated['modification_note'],
                'order_id'          => $order->id,
            ]);

            return response()->json([
                'message' => 'Modification requested. Reviewer notified.',
                'data'    => $order->fresh(),
            ], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // ─── PO approves work (for site visit — triggers payment) ────
    // POST /api/v1/programs/reviewer-orders/{order}/approve
    public function approve(Request $request, ReviewerOrder $order)
    {
        if ($order->program->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($order->work_status !== 'delivered') {
            return response()->json([
                'error' => 'Can only approve delivered work'
            ], 422);
        }

        if ($order->isPaid()) {
            return response()->json(['error' => 'Already paid'], 422);
        }

        $order->update([
            'work_status'  => 'approved',
            'approved_at'  => now(),
        ]);

        // Notify reviewer
        $this->notification->send('reviewer.work_approved', [
            $order->reviewer
        ], [
            'program_title' => $order->program->program_title,
            'order_type'    => $order->order_type,
            'fee'           => $order->fee_usd,
            'order_id'      => $order->id,
        ]);

        return response()->json([
            'message' => 'Work approved. Proceed to initiate payment.',
            'data'    => $order->fresh(),
        ], 200);
    }

    // ─── Payment status polling ───────────────────────────────────
    // GET /api/v1/programs/reviewer-orders/{order}/payment-status
    public function paymentStatus(ReviewerOrder $order)
    {
        $userId = Auth::id();

        $isOwner    = $order->program->user_id === $userId;
        $isReviewer = $order->reviewer_id === $userId;

        if (!$isOwner && !$isReviewer) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json([
            'payment_status' => $order->payment_status,
            'message'        => match($order->payment_status) {
                'unpaid'           => 'Payment not initiated.',
                'pending'          => 'Processing payment...',
                'leg1_processing'  => 'Payment received. Transferring to reviewer...',
                'completed'        => 'Payment completed successfully.',
                'failed'           => 'Payment failed. Please retry.',
                default            => 'Processing...',
            },
            'updated_at' => $order->updated_at,
        ], 200);
    }
}
