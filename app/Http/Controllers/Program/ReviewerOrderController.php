<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\ReviewerOrder;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\ProgramNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ReviewerOrderController extends Controller
{

    public function __construct()
    {
        parent::__construct();
    }

    // ─── List reviewer's own orders ──────────────────────────────
    // GET /api/v1/programs/reviewer/orders
    public function myOrders(Request $request)
    {
        $base = ReviewerOrder::where('reviewer_id', Auth::id())
            // A site visit assigned to them is theirs to see only once the program owner has sent its checkpoint
            ->where(fn ($query) => $query->where('order_type', '!=', 'site_visit')
                ->orWhereHas('siteVisit.checkpoint', fn ($checkpoint) => $checkpoint->whereNotNull('sent_at')))
            ->with(['program', 'round', 'siteVisit.checkpoint', 'applicationAssignments.application.business:id,name']);

        // page / per_page / search / filters / sort / export: the paged Orders table (the old full list stays for the other readers)
        if (\App\Service\Program\ReviewerLists::wanted($request)) {
            return (new \App\Service\Program\ReviewerLists((int) Auth::id()))->orders($request, $base, fn ($orders) => $this->orderRows($orders));
        }

        return response()->json(['data' => $this->orderRows($base->latest()->get())], 200);
    }

    /** The rows the reviewer's order lists show; the scores of every order's applications are read in one query. */
    private function orderRows($orders)
    {
        $scores = $this->scoresFor($orders);

        return $orders->map(fn ($order) => [
            ...$order->toArray(),
            'fee_amount' => $order->fee_amount,
            'applications' => $this->applicationSummaries($order, $scores),
            'completed_application_count' => $order->applicationAssignments->where('status', 'completed')->count(),
            'minimum_apps_to_get_paid' => $order->minimumPaidApplications() ?: null,
            'paid_threshold_met' => $order->minimumPaidApplications()
                ? $order->applicationAssignments->where('status', 'completed')->count() >= $order->minimumPaidApplications()
                : false,
            'work_status' => \App\Support\ApiContract::statusMeta('reviewer_order', $order->work_status),
            'payment_status' => \App\Support\ApiContract::statusMeta('reviewer_payment', $order->payment_status),
        ])->values();
    }

    /** Scores keyed "reviewer:round:application" for the applications of these orders (one query). */
    private function scoresFor($orders): \Illuminate\Support\Collection
    {
        $assignments = $orders->flatMap(fn ($order) => $order->applicationAssignments);
        if ($assignments->isEmpty()) {
            return collect();
        }

        return ApplicationScore::query()
            ->whereIn('reviewer_id', $orders->pluck('reviewer_id')->unique())
            ->whereIn('round_id', $orders->pluck('round_id')->filter()->unique())
            ->whereIn('application_id', $assignments->pluck('application_id')->unique())
            ->get(['reviewer_id', 'round_id', 'application_id', 'total_score'])
            ->mapWithKeys(fn ($score) => [$score->reviewer_id.':'.$score->round_id.':'.$score->application_id => $score->total_score]);
    }

    // ─── PO views all orders for a round ─────────────────────────
    // GET /api/v1/programs/rounds/{round}/reviewer-orders
    public function roundOrders(ProgramRound $round)
    {
        if (!\App\Service\Program\ProgramAccess::owns($round->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $orders = ReviewerOrder::where('round_id', $round->id)
            ->where('order_type', 'round_review')
            ->with([
                'applicationAssignments.application.business:id,name',
                'reviewer',
                'round:id,review_period_end,close_date',
            ])
            ->get()
            ->map(fn($order) => [
                ...$order->toArray(),
                'fee_amount' => $order->fee_amount,
                'applications' => $this->applicationSummaries($order),
                'completed_application_count' => $order->applicationAssignments->where('status', 'completed')->count(),
                'minimum_apps_to_get_paid' => $order->minimumPaidApplications() ?: null,
                'paid_threshold_met' => $order->minimumPaidApplications()
                    ? $order->applicationAssignments->where('status', 'completed')->count() >= $order->minimumPaidApplications()
                    : false,
                'work_status' => \App\Support\ApiContract::statusMeta('reviewer_order', $order->work_status),
                'payment_status' => \App\Support\ApiContract::statusMeta('reviewer_payment', $order->payment_status),
            ]);

        return response()->json(['data' => $orders], 200);
    }

    // ─── PO views every Business Coach order of their programs ───
    // GET /api/v1/programs/owner/reviewer-orders
    // One server list (search, filters, sort, page, export, facets, stats) for the Orders and Site visits pages;
    // who sees what is the same as roundOrders (round reviews) and SiteVisitController::ownerOrders (site visits).
    public function ownerOrders(Request $request)
    {
        return (new \App\Service\Program\OwnerOrderList(Auth::user()))->respond($request);
    }

    // ─── Reviewer marks work as delivered ────────────────────────
    // POST /api/v1/programs/reviewer-orders/{order}/deliver
    public function deliver(Request $request, ReviewerOrder $order)
    {
        // if ($order->order_type === 'round_review') {
        //     return response()->json(['error' => 'Round review orders are submitted automatically when the minimum paid application count is reached.'], 422);
        // }

        if ($order->reviewer_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($order->order_type === 'site_visit') {
            return response()->json(['error' => 'Submit the site visit report to deliver this order.'], 422);
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
        if (!$this->canDecide($order)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        if ($order->order_type === 'site_visit') {
            if ($order->work_status !== 'delivered') return response()->json(['error' => 'Can only request modification on delivered work'], 422);
            $validated = $request->validate([
                'modification_note' => 'required|string|max:1000',
                'flagged_items' => 'nullable|array|max:100',
                'flagged_items.*.field_id' => 'required|string|max:100',
                'flagged_items.*.label' => 'required|string|max:500',
                'flagged_items.*.note' => 'required|string|max:240',
            ]);
            $order->update(['work_status' => 'modification_requested', 'modification_note' => $validated['modification_note'], 'flagged_items' => $validated['flagged_items'] ?? null]);
            $order->siteVisit?->update(['status' => 'scheduled']);
            // Same as SiteVisitController::requestChanges: the checkpoint shows the visit was sent back
            $order->siteVisit?->checkpoint?->update(['status' => 'changes_requested']);
            $this->programNotification->send('reviewer.modification_requested', [$order->reviewer], [
                'program_title' => $order->program->program_title,
                'modification_note' => $validated['modification_note'], 'order_id' => $order->id,
            ]);
            return response()->json(['message' => 'Changes requested.', 'data' => $order->fresh()], 200);
        }

        // if ($order->order_type === 'round_review') {
        //     return response()->json(['error' => 'Round review work is scored in the application workflow; modification requests are not supported for these orders.'], 422);
        // }

        if ($order->work_status !== 'delivered') {
            return response()->json([
                'error' => 'Can only request modification on delivered work'
            ], 422);
        }

        try {
            $validated = $request->validate([
                'modification_note' => 'required|string|max:1000',
                'flagged_items' => 'nullable|array|max:100',
                'flagged_items.*.field_id' => 'required|string|max:100',
                'flagged_items.*.label' => 'required|string|max:500',
                'flagged_items.*.note' => 'required|string|max:240',
            ]);

            $order->update([
                'work_status'       => 'modification_requested',
                'modification_note' => $validated['modification_note'],
                'flagged_items' => $validated['flagged_items'] ?? null,
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
                'message' => 'Modification requested. The Business Coach has been told.',
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
        if (!$this->canDecide($order)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        if ($order->order_type === 'site_visit') {
            return response()->json(['error' => 'Use the site visit approval action so payment is started correctly.'], 422);
        }

        if ($order->work_status !== 'delivered') {
            return response()->json([
                'error' => 'Can only approve delivered work'
            ], 422);
        }

        if ($order->order_type === 'round_review') {
            if (!$this->reviewPeriodHasEnded($order)) {
                return response()->json(['error' => 'Business Coach work can only be approved after the review period ends.'], 422);
            }

            $completed = $order->applicationAssignments()->where('status', 'completed')->count();
            if ((float) $order->fee_amount > 0 && (!$order->minimumPaidApplications() || $completed < $order->minimumPaidApplications())) {
                return response()->json(['error' => 'The Business Coach has not reached the minimum paid application count.'], 422);
            }
        }

        try {
            $order->update(['work_status' => 'approved', 'approved_at' => now()]);

            $program = $order->program;
            $reviewer = $order->reviewer;

            $this->programNotification->send('reviewer.work_approved', [$reviewer], [
                'program_title' => $program->program_title,
                'order_type' => $order->order_type,
                'order_id' => $order->id,
            ]);

            return response()->json([
                'message' => 'Work approved successfully.',
                'data' => $order->fresh(),
            ], 200);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['order_id' => $order->id]);
            return response()->json(['message' => 'Work approval could not be completed.'], 500);
        }
    }

    /** Owner rejects submitted reviewer work after the review period has closed. */
    public function reject(Request $request, ReviewerOrder $order)
    {
        if (!$this->canDecide($order)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($order->order_type !== 'round_review' || !in_array($order->work_status, ['delivered', 'in_progress', 'assigned'], true)) {
            return response()->json(['error' => 'This Business Coach order cannot be rejected from its current status.'], 422);
        }

        if (!$this->reviewPeriodHasEnded($order)) {
            return response()->json(['error' => 'Business Coach work can only be rejected after the review period ends.'], 422);
        }

        $validated = $request->validate(['rejection_reason' => 'required|string|max:1000']);
        $order->update([
            'work_status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason'],
        ]);

        return response()->json(['message' => 'Business Coach work rejected.', 'data' => $order->fresh()], 200);
    }

    /**
     * What the order drawer lists: each application handed to the reviewer, where it stands, when it was assigned,
     * started and finished, and the score the reviewer gave it.
     */
    private function applicationSummaries(ReviewerOrder $order, ?\Illuminate\Support\Collection $preloaded = null): array
    {
        $assignments = $order->applicationAssignments;

        if ($assignments->isEmpty()) {
            return [];
        }

        // Scores read once for a whole list of orders, or here for one order
        $scores = $preloaded
            ? $assignments->mapWithKeys(fn ($a) => [$a->application_id => $preloaded->get($order->reviewer_id.':'.$order->round_id.':'.$a->application_id)])
            : ApplicationScore::query()
                ->where('reviewer_id', $order->reviewer_id)
                ->where('round_id', $order->round_id)
                ->whereIn('application_id', $assignments->pluck('application_id'))
                ->pluck('total_score', 'application_id');

        return $assignments
            ->sortBy('assigned_at')
            ->map(fn ($assignment) => [
                'application_id' => $assignment->application_id,
                'business_name'  => $assignment->application?->business?->name ?? $assignment->application?->startup_name,
                'status'         => $assignment->status,
                'assigned_at'    => $assignment->assigned_at,
                'started_at'     => $assignment->started_at,
                'completed_at'   => $assignment->completed_at,
                'total_score'    => $scores[$assignment->application_id] ?? null,
            ])
            ->values()
            ->all();
    }

    private function reviewPeriodHasEnded(ReviewerOrder $order): bool
    {
        $reviewPeriodEnd = $order->round?->review_period_end ?? $order->round?->close_date;

        return $reviewPeriodEnd !== null
            && now()->startOfDay()->gt($reviewPeriodEnd->copy()->startOfDay());
    }

    // ─── Payment initiate & status polling ───────────────────────────────────

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

    /** The program's owner and program-team members who may edit (OrganizationPermission 'edit') decide on coach work */
    private function canDecide(ReviewerOrder $order): bool
    {
        return \App\Service\Program\ProgramAccess::owns($order->program)
            && \App\Service\Organization\OrganizationPermission::can(Auth::user(), 'edit');
    }
}
