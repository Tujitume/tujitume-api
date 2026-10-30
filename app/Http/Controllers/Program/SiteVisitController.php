<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponseResource;
use App\Http\Resources\Program\Monitoring\MESiteVisitResource;
use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\Programs\Monitoring\MESiteVisitFile;
use App\Models\ReviewerOrder;
use App\Service\Program\ReviewerPaymentService;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SiteVisitController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }
    // ─── SITE VISIT ──────────────────────────────────────────────────────

    // POST /api/v1/programs/monitoring/checkpoints/{checkpoint}/site-visit/assign
    public function assignSiteVisit(Request $request, MECheckpoint $checkpoint)
    {
        if (!\App\Service\Program\ProgramAccess::owns($checkpoint->application->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!$checkpoint->require_site_visit) {
            return response()->json(['error' => 'This checkpoint does not require a site visit'], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'reviewer_id' => 'required|exists:users,id',
                'start_date'  => 'required|date',
                'assign_type' => 'required|in:internal,external,third_party_audit',
                'email'       => 'nullable|email|required_if:assign_type,external',
                'data_collection_fields' => 'nullable|array',
                'location'    => 'nullable|string',
                'gps_lat'    => 'nullable|numeric',
                'gps_lng'    => 'nullable|numeric',
                'confirm_location' => 'sometimes|boolean',
                'inspector'   => 'nullable|string',
                'objective'   => 'nullable|string',
                'kpi_targets' => 'nullable|array',
                'reviewer_fee' => 'nullable|numeric|min:0',
            ]);

            $validated['checkpoint_id'] = $checkpoint->id;
            $validated['app_id']        = $checkpoint->app_id;
            $business = $checkpoint->application->business;
            $validated['objective'] = $validated['objective'] ?? $checkpoint->requirement;
            $validated['kpi_targets'] = $validated['kpi_targets'] ?? $checkpoint->kpis_to_track;
            $validated['data_collection_fields'] = $validated['data_collection_fields'] ?? array_merge($checkpoint->submission_fields ?? [], $checkpoint->evidence_required ?? []);
            $validated['location'] = $validated['location'] ?? $business?->location;
            $validated['gps_lat'] = $validated['gps_lat'] ?? $business?->lat;
            $validated['gps_lng'] = $validated['gps_lng'] ?? $business?->lng;
            $validated['confirm_location'] = $validated['confirm_location'] ?? false;

            $existingVisit = MESiteVisit::where('checkpoint_id', $checkpoint->id)->first();
            $order = $existingVisit ? ReviewerOrder::where('site_visit_id', $existingVisit->id)->first() : null;
            if ($order && (in_array($order->payment_status, ['pending', 'leg1_processing', 'completed'], true)
                || $order->acceptance_status === 'accepted'
                || in_array($order->work_status, ['delivered', 'approved'], true))) {
                DB::rollBack();
                return response()->json(['error' => 'An accepted, delivered, approved, or paid site visit cannot be reassigned.'], 422);
            }

            $validated['status'] = 'scheduled';
            $siteVisit = MESiteVisit::updateOrCreate(
                ['checkpoint_id' => $checkpoint->id],
                $validated
            );

            $order = ReviewerOrder::updateOrCreate(
                ['site_visit_id' => $siteVisit->id],
                [
                    'organization_id' => $checkpoint->application->program->owner->organization_id ?? null,
                    'reviewer_id'     => $validated['reviewer_id'],
                    'program_id'      => $checkpoint->application->program_id,
                    'order_type'      => 'site_visit',
                    'site_visit_id'   => $siteVisit->id,
                    'fee_amount'      => $validated['reviewer_fee'] ?? 0,
                    'currency'        => $checkpoint->application->program->currency ?: config('app.currency', 'KES'),
                    'work_status'     => 'assigned',
                    'acceptance_status' => 'pending',
                    'accepted_at'     => null,
                    'payment_status'  => 'unpaid',
                    'deadline'        => $validated['start_date'] ?? null,
                    'proposed_fee' => null,
                    'fee_status' => null,
                    'fee_proposal_note' => null,
                    'fee_proposed_at' => null,
                ]
            );

            // commit the transaction
            DB::commit();

            $this->programNotification->send('reviewer.site_visit_assigned', [$order->reviewer], [
                'program_id' => $order->program_id,
                'program_title' => $order->program->program_title,
                'order_id' => $order->id,
                'site_visit_id' => $siteVisit->id,
                'checkpoint_name' => $checkpoint->title ?? ('Checkpoint ' . $checkpoint->id),
                'fee' => $order->fee_amount,
                'currency' => $order->currency,
            ]);

            return new ApiResponseResource('Site visit assigned successfully', new MESiteVisitResource($siteVisit->load(['checkpoint', 'files', 'reviewerOrder'])), 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /** Reviewer accepts a checkpoint-linked site visit assignment. */
    public function acceptSiteVisit(MESiteVisit $visit)
    {
        if ((int) $visit->reviewer_id !== (int) auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        $order = ReviewerOrder::where('site_visit_id', $visit->id)->first();
        if (!$order || $order->order_type !== 'site_visit' || $order->acceptance_status !== 'pending') {
            return response()->json(['error' => 'This site visit assignment cannot be accepted.'], 422);
        }
        if ($order->fee_status === 'pending_owner') {
            return response()->json(['error' => 'Wait for the organization to answer your proposed fee, or the visit would be accepted at the original fee.'], 422);
        }
        if ((float) $order->fee_amount > 0) {
            if (empty(auth()->user()->lipr_wallet)) {
                return response()->json(['error' => 'Set up your wallet before accepting a paid site visit, so you can be paid when your report is approved.'], 422);
            }
            $program = $order->program;
            $required = (float) ReviewerOrder::where('program_id', $program->id)
                ->whereIn('order_type', ['site_visit', 'round_review'])
                ->where('acceptance_status', 'accepted')
                ->where('payment_status', '!=', 'completed')
                ->where('work_status', '!=', 'rejected')
                ->where('id', '!=', $order->id)->sum('fee_amount') + (float) $order->fee_amount;
            $available = (float) ($program->wallet?->balance ?? 0);
            if ($available < $required) {
                $shortfall = $required - $available;
                $this->programNotification->send('reviewer.accepted_insufficient_funds', [$program->owner], [
                    'program_id' => $program->id, 'program_title' => $program->program_title,
                    'round_name' => $visit->checkpoint->checkpoint_name, 'required' => $required,
                    'available' => $available, 'shortfall' => $shortfall, 'currency' => $order->currency,
                ]);
                return response()->json(['error' => 'The program wallet cannot cover this visit fee yet. The program owner has been told to top it up.', 'required' => $required, 'available' => $available, 'shortfall' => $shortfall], 422);
            }
        }
        $order->update(['acceptance_status' => 'accepted', 'accepted_at' => now(), 'work_status' => 'in_progress']);
        $this->programNotification->send('reviewer.site_visit_accepted', [$order->program->owner], [
            'program_id' => $order->program_id, 'program_title' => $order->program->program_title,
            'order_id' => $order->id, 'site_visit_id' => $visit->id,
            'reviewer_name' => trim(auth()->user()->first_name . ' ' . auth()->user()->last_name),
        ]);
        return response()->json(['message' => 'Site visit assignment accepted.', 'data' => $order->fresh()], 200);
    }

    /** Reviewer declines a checkpoint-linked site visit assignment. */
    public function declineSiteVisit(Request $request, MESiteVisit $visit)
    {
        if ((int) $visit->reviewer_id !== (int) auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        $validated = $request->validate(['reason' => 'nullable|string|max:1000']);
        $order = ReviewerOrder::where('site_visit_id', $visit->id)->first();
        if (!$order || $order->order_type !== 'site_visit' || $order->acceptance_status !== 'pending') {
            return response()->json(['error' => 'This site visit assignment cannot be declined.'], 422);
        }
        $order->update(['acceptance_status' => 'declined', 'work_status' => 'rejected', 'rejection_reason' => $validated['reason'] ?? null]);
        $visit->update(['status' => 'cancelled']);
        $this->programNotification->send('reviewer.site_visit_declined', [$order->program->owner], [
            'program_id' => $order->program_id, 'program_title' => $order->program->program_title,
            'order_id' => $order->id, 'site_visit_id' => $visit->id,
            'reviewer_name' => trim(auth()->user()->first_name . ' ' . auth()->user()->last_name),
            'reason' => $validated['reason'] ?? '',
        ]);
        return response()->json(['message' => 'Site visit assignment declined.', 'data' => $order->fresh()], 200);
    }

    /** Owner requests corrections to submitted site visit work. */
    public function requestChanges(Request $request, ReviewerOrder $order)
    {
        if (!$this->isSiteVisitOrder($order)) return response()->json(['error' => 'This is not a checkpoint site visit order.'], 422);
        if (!\App\Service\Program\ProgramAccess::owns($order->program)) return response()->json(['error' => 'Unauthorized'], 403);
        if ($order->work_status !== 'delivered') return response()->json(['error' => 'Can only request changes to delivered work.'], 422);
        $validated = $request->validate([
            'modification_note' => 'required|string|max:1000',
            'flagged_items' => 'nullable|array|max:100',
            'flagged_items.*.field_id' => 'required|string|max:100',
            'flagged_items.*.label' => 'required|string|max:500',
            'flagged_items.*.note' => 'required|string|max:240',
        ]);
        $order->update(['work_status' => 'modification_requested', 'modification_note' => $validated['modification_note'], 'flagged_items' => $validated['flagged_items'] ?? null]);
        $order->siteVisit?->update(['status' => 'scheduled']);
        $order->siteVisit?->checkpoint?->update(['status' => 'changes_requested']);
        $this->programNotification->send('reviewer.modification_requested', [$order->reviewer], [
            'program_title' => $order->program->program_title, 'modification_note' => $validated['modification_note'], 'order_id' => $order->id,
        ]);
        return response()->json(['message' => 'Changes requested. Reviewer notified.', 'data' => $order->fresh()], 200);
    }

    public function proposeFee(Request $request, MESiteVisit $visit)
    {
        if ((int) $visit->reviewer_id !== (int) auth()->id()) return response()->json(['error' => 'Unauthorized'], 403);
        $validated = $request->validate(['proposed_fee' => 'required|numeric|min:0|max:99999999.99', 'note' => 'nullable|string|max:500']);
        $order = ReviewerOrder::where('site_visit_id', $visit->id)->first();
        if (!$order || $order->order_type !== 'site_visit' || $order->acceptance_status !== 'pending') return response()->json(['error' => 'A fee can only be proposed before the visit is accepted.'], 422);
        $order->update(['proposed_fee' => $validated['proposed_fee'], 'fee_status' => 'pending_owner', 'fee_proposal_note' => $validated['note'] ?? null, 'fee_proposed_at' => now()]);
        $program = $order->program;
        $this->programNotification->send('reviewer.fee_proposed', [$program->owner], [
            'program_id' => $program->id, 'program_title' => $program->program_title, 'order_id' => $order->id,
            'site_visit_id' => $visit->id, 'app_id' => $visit->app_id,
            'checkpoint_name' => $visit->checkpoint->checkpoint_name,
            'reviewer_name' => trim(auth()->user()->first_name . ' ' . auth()->user()->last_name),
            'fee' => $order->fee_amount, 'proposed_fee' => $order->proposed_fee, 'currency' => $order->currency,
        ]);
        return response()->json(['message' => 'Fee proposal sent to the program owner.', 'data' => $order->fresh()], 200);
    }

    public function answerFee(Request $request, ReviewerOrder $order)
    {
        if (!\App\Service\Program\ProgramAccess::owns($order->program)) return response()->json(['error' => 'Unauthorized'], 403);
        if (!$this->isSiteVisitOrder($order)) return response()->json(['error' => 'This is not a checkpoint site visit order.'], 422);
        $validated = $request->validate(['decision' => 'required|in:accept,decline']);
        if ($order->fee_status !== 'pending_owner' || $order->acceptance_status !== 'pending') return response()->json(['error' => 'There is no proposed fee waiting for an answer.'], 422);
        $accepted = $validated['decision'] === 'accept';
        $order->update(array_filter(['fee_status' => $accepted ? 'accepted' : 'declined', 'fee_amount' => $accepted ? $order->proposed_fee : null], fn ($value) => $value !== null));
        $this->programNotification->send('reviewer.fee_decided', [$order->reviewer], [
            'program_id' => $order->program_id, 'program_title' => $order->program->program_title,
            'order_id' => $order->id, 'site_visit_id' => $order->site_visit_id, 'accepted' => $accepted,
            'fee' => $order->fee_amount, 'proposed_fee' => $order->proposed_fee, 'currency' => $order->currency,
        ]);
        return response()->json(['message' => 'Fee proposal answered.', 'data' => $order->fresh()], 200);
    }

    /** Owner rejects delivered site visit work; distinct from round review decisions. */
    public function rejectOrder(Request $request, ReviewerOrder $order)
    {
        if (!$this->isSiteVisitOrder($order)) return response()->json(['error' => 'This is not a checkpoint site visit order.'], 422);
        if (!\App\Service\Program\ProgramAccess::owns($order->program)) return response()->json(['error' => 'Unauthorized'], 403);
        if ($order->work_status !== 'delivered') return response()->json(['error' => 'Only delivered site visit work can be rejected.'], 422);
        $validated = $request->validate(['rejection_reason' => 'required|string|max:1000']);
        $order->update(['work_status' => 'rejected', 'rejection_reason' => $validated['rejection_reason']]);
        $order->siteVisit?->update(['status' => 'cancelled']);
        $this->programNotification->send('reviewer.work_rejected', [$order->reviewer], [
            'program_title' => $order->program->program_title, 'reason' => $validated['rejection_reason'], 'order_id' => $order->id,
        ]);
        return response()->json(['message' => 'Site visit work rejected.', 'data' => $order->fresh()], 200);
    }

    /** Owner approves delivered site visit work and starts its reviewer payment. */
    public function approveOrder(ReviewerOrder $order, ReviewerPaymentService $payments)
    {
        if (!$this->isSiteVisitOrder($order)) 
            return response()->json(['error' => 'This is not a checkpoint site visit order.'], 422);
        
        if (!\App\Service\Program\ProgramAccess::owns($order->program)) 
            return response()->json(['error' => 'Unauthorized'], 403);
        
        if ($order->work_status !== 'delivered') 
            return response()->json(['error' => 'Can only approve delivered site visit work.'], 422);
        
        $order->update(['work_status' => 'approved', 'approved_at' => now()]);
        $order->siteVisit?->checkpoint?->update(['status' => 'verified']);
        $this->programNotification->send('reviewer.work_approved', [$order->reviewer], [
            'program_title' => $order->program->program_title, 'order_type' => 'site_visit', 'order_id' => $order->id,
        ]);
        $payment = ['ok' => true, 'message' => 'No fee is due for this site visit.'];
        if ((float) $order->fee_amount > 0) {
            $payment = $payments->pay($order->fresh());
            if ($payment['ok']) {
                $this->programNotification->send('reviewer.payment_initiated', [$order->reviewer], [
                    'program_title' => $order->program->program_title, 'round_name' => 'site visit',
                    'fee' => $order->fee_amount, 'currency' => $order->currency, 'order_id' => $order->id,
                ]);
            }
        }
        return response()->json([
            'message' => $payment['ok'] ? 'Site visit approved. ' . $payment['message'] : 'Site visit approved, but payment could not be started: ' . $payment['message'],
            'payment' => $payment,
            'data' => $order->fresh(),
        ], 200);
    }

    /** Retry a site visit payment after the work has been approved. */
    public function retryPayment(ReviewerOrder $order, ReviewerPaymentService $payments)
    {
        if (!$this->isSiteVisitOrder($order)) return response()->json(['error' => 'This is not a checkpoint site visit order.'], 422);
        if (!\App\Service\Program\ProgramAccess::owns($order->program)) return response()->json(['error' => 'Unauthorized'], 403);
        if ($order->work_status !== 'approved' || !in_array($order->payment_status, ['unpaid', 'failed'], true)) {
            return response()->json(['error' => 'Only approved site visits with an unpaid or failed payment can be retried.'], 422);
        }
        $payment = $payments->pay($order);
        if ($payment['ok']) {
            $this->programNotification->send('reviewer.payment_initiated', [$order->reviewer], [
                'program_title' => $order->program->program_title, 'round_name' => 'site visit',
                'fee' => $order->fee_amount, 'currency' => $order->currency, 'order_id' => $order->id,
            ]);
        }
        return response()->json(['message' => $payment['message'], 'payment' => $payment, 'data' => $order->fresh()], $payment['ok'] ? 200 : 422);
    }

    private function isSiteVisitOrder(ReviewerOrder $order): bool
    {
        $visit = $order->siteVisit;
        return $order->order_type === 'site_visit'
            && $visit !== null
            && (int) $visit->reviewer_id === (int) $order->reviewer_id;
    }

    // POST /api/v1/programs/monitoring/site-visits/{visit}/submit
    public function submitSiteVisit(Request $request, MESiteVisit $visit)
    {
        if ($visit->reviewer_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $order = ReviewerOrder::where('site_visit_id', $visit->id)->first();
        if (!$order || $order->order_type !== 'site_visit' || $order->acceptance_status !== 'accepted'
            || !in_array($order->work_status, ['in_progress', 'modification_requested'], true)) {
            return response()->json(['error' => 'Accept this site visit assignment before submitting its report.'], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'objectives_assessment'  => 'nullable|string',
                'observed_actions'       => 'nullable|string',
                'evidence_found'         => 'nullable|string',
                'risk_notes'             => 'nullable|string',
                'recommendation_notes'   => 'nullable|string',
                'visit_comments'         => 'nullable|string',
                'kpi_targets'            => 'nullable|array',
                'data_collection_fields' => 'nullable|array',
                'gps_lat'                => 'nullable|numeric',
                'gps_lng'                => 'nullable|numeric',
                'files'                  => 'nullable|array',
                'files.*'                => 'file|max:20480',
            ]);

            // Handle files
            $files = $request->file('files', []);
            foreach ($files as $file) {
                $filePath = $this->fileUpload->saveFile($file, "files/program/me/site-visits/{$visit->id}");

                MESiteVisitFile::create([
                    'site_visit_id'     => $visit->id,
                    'file_path'         => $filePath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type'         => $file->getMimeType(),
                ]);
            }

            unset($validated['files']);

            $visit->update(array_merge($validated, ['status' => 'completed']));

            // Auto-mark reviewer order as delivered when site visit submitted
            $order->update([
                'work_status'  => 'delivered',
                'delivered_at' => now(),
            ]);
            $visit->checkpoint()->update(['status' => 'submitted']);

            $this->programNotification->send('reviewer.work_delivered', [
                $visit->checkpoint->application->program->owner
            ], [
                'program_title' => $visit->checkpoint->application->program->program_title,
                'reviewer_name' => Auth::user()->first_name . ' ' . Auth::user()->last_name,
                'order_type'    => 'site visit',
                'round_name'    => null,
                'order_id'      => $order->id,
            ]);

            DB::commit();

            return new ApiResponseResource('Site visit report submitted successfully', new MESiteVisitResource($visit->load(['checkpoint', 'files', 'reviewerOrder'])), 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // GET /api/v1/programs/monitoring/site-visits/{visit}
    public function showSiteVisit(MESiteVisit $visit)
    {
        $userId      = auth()->id();
        $application = $visit->checkpoint->application;

        $isProgramOwner = $application->program->user_id === $userId;
        $isReviewer   = $visit->reviewer_id === $userId;
        $isApplicant  = $application->user_id === $userId;

        if (!$isProgramOwner && !$isReviewer && !$isApplicant) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return new ApiResponseResource('Site visit details fetched successfully', new MESiteVisitResource($visit->load(['checkpoint', 'files', 'reviewerOrder'])), 200);
    }

    public function SiteVisits(Request $request, $reviewer_id)
    {
        $userId = auth()->id();

        if ($userId != $reviewer_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $siteVisits = MESiteVisit::where('reviewer_id', $reviewer_id)
            ->with(['checkpoint.application', 'checkpoint', 'files', 'reviewerOrder'])
            ->orderBy('start_date', 'desc')
            ->get();

        return new ApiResponseResource('Site visits fetched successfully', MESiteVisitResource::collection($siteVisits), 200);
    }

    /** List checkpoint site-visit orders for the owning program organization. */
    public function ownerOrders(\App\Models\Programs\Program $program)
    {
        if (!\App\Service\Program\ProgramAccess::owns($program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        $orders = ReviewerOrder::with(['program', 'reviewer', 'siteVisit.checkpoint'])
            ->where('program_id', $program->id)
            ->where('order_type', 'site_visit')
            ->whereNotNull('site_visit_id')
            ->latest()
            ->get();
        return response()->json(['data' => $orders], 200);
    }
}
