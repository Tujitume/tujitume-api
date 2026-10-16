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
use Stripe\StripeClient;
use App\Service\Balance\BalanceService;
use App\Service\LiprMpesa\LiprW2W;

class ReviewerOrderController extends Controller
{

    private $Client;
    private $balance;
    private $liprW2W;

    public function __construct(StripeClient $client)
    {
        parent::__construct();
        $this->Client = $client;
        $this->balance = new BalanceService();
        $this->liprW2W = new LiprW2W();

    }

    // ─── List reviewer's own orders ──────────────────────────────
    // GET /api/v1/programs/reviewer/orders
    public function myOrders()
    {
        $orders = ReviewerOrder::where('reviewer_id', Auth::id())
            ->with(['program', 'round', 'siteVisit', 'applicationAssignments'])
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
            ->with([
                'applicationAssignments.application',
                'reviewer'
            ])
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

        if ($order->order_type === 'round_review' && $order->acceptance_status !== 'accepted') {
            return response()->json(['error' => 'Accept the reviewer assignment before delivering work.'], 422);
        }

        if ($order->order_type === 'round_review') {
            $assignments = $order->applicationAssignments();

            if (!$assignments->exists()) {
                return response()->json([
                    'error' => 'Cannot deliver a round review order with no assigned applications.',
                ], 422);
            }

            $incompleteCount = $order->applicationAssignments()
                ->where(function ($query) {
                    $query->where('status', '!=', 'completed')
                        ->orWhereDoesntHave('application', function ($applicationQuery) {
                            $applicationQuery->where('round_status', 'scored');
                        });
                })
                ->count();

            if ($incompleteCount > 0) {
                return response()->json([
                    'error' => 'All assigned applications must be scored before delivering this order.',
                    'incomplete_assignments' => $incompleteCount,
                ], 422);
            }
        }

        if (!in_array($order->work_status, ['in_progress', 'modification_requested'])) {
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
            $this->programNotification->send('reviewer.work_delivered', [
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
            $this->programNotification->send('reviewer.modification_requested', [
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
            //return response()->json(['error' => 'Already paid'], 422);
        }

        try {

            $transfer = null;

            if($order->fee_amount > 0) {
                $transfer = $this->initiateReviewerPayment($order);

                if (!$transfer || !($transfer['success'] ?? false)) {
                    throw new \Exception($transfer['error'] ?? $transfer['message'] ?? 'Transfer failed', 422);
                }
            }
            


            if ($order->order_type === 'round_review') {
                $order->applicationAssignments()
                    ->with('application')->get()
                    ->each(function ($assignment) use ($order) {
                        if ($assignment->application) {
                            $assignment->application->assigned_reviewer_id = $order->reviewer_id;
                            $assignment->application->save();
                        }
                    });
            }

            $program = $order->program;
            $reviewer = $order->reviewer;

            $this->programNotification->send('reviewer.work_approved', [$reviewer], [
                'program_title' => $program->program_title,
                'order_type' => $order->order_type,
                'fee' => $order->fee_amount,
                'order_id' => $order->id,
            ]);

            $this->programNotification->send('reviewer.payment_initiated', [$reviewer], [
                'program_title' => $program->program_title,
                'round_name' => $order->round?->round_name ?? $program->program_title,
                'fee' => $order->fee_amount,
                'order_id' => $order->id,
            ]);


            $round = $order->round;
            if (
                $order->order_type === 'round_review'
                && $round
                && !$round->applications()->where(function ($query) {
                    $query->whereNull('round_status')
                        ->orWhere('round_status', '!=', 'scored');
                })->exists()
            ) {
                $this->programNotification->send('round.all_applications_scored', [$program->owner], [
                    'program_title' => $program->program_title,
                    'round_name' => $round->round_name,
                    'round_id' => $round->id,
                    'program_id' => $program->id,
                ]);
            }

            return response()->json([
                'message' => 'Work approved and Payment initiated successfully.',
                'data' => $order->fresh(),
                'transfer' => $transfer,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Already paid'], 422);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['order_id' => $order->id]);
            $status = in_array($e->getCode(), [404, 422]) ? $e->getCode() : 500;
            return response()->json(['message' => $status === 500 ? 'Reviewer payment could not be completed.' : $e->getMessage()], $status);
        }
    }

    // ─── Payment initiate & status polling ───────────────────────────────────

    public function initiateReviewerPayment(ReviewerOrder $order)
    {
        return DB::transaction(function () use ($order) {
            $order = ReviewerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->payment_status === 'completed') {
                //throw ValidationException::withMessages(['payment' => 'Already paid']);
            }

            $program = $order->program()->with('owner')->firstOrFail();
            $owner = $program->owner;
            $reviewer = $order->reviewer;
            $wallet = $program->wallet()->lockForUpdate()->first();
            $amount = round((float) $order->fee_amount, 2);


            if (!$wallet || (float) $wallet->balance < $amount) {
                throw new \RuntimeException('Insufficient program wallet balance.', 422);
            }
            if ($amount <= 0) {
                throw new \RuntimeException('Reviewer payment amount must be greater than zero.', 422);
            }

            $order->update([
                'work_status' => 'approved',
                'approved_at' => now(),
                'payment_status' => 'pending',
            ]);

            // Create the transfer in the program context.
            $transfer = $this->liprW2W->sendReviewerPayment(
                $amount,
                $reviewer->lipr_wallet,
                $owner->lipr_wallet,
                $order
            );

            return $transfer;
        });
    }

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
