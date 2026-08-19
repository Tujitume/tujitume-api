<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\MilestoneAgreementComment;
use App\Models\Programs\MilestonePreAgreement;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MilestonePreAgreementController extends Controller
{
    public function comment(Request $request, ProgramMilestone $milestone, string $type)
    {
        $userId = auth()->id();
        $application = $milestone->application;

        $isApplicant  = $application->user_id === $userId;
        $isProgramOwner = $application->program->user_id === $userId;

        if (!$isApplicant && !$isProgramOwner) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!in_array($type, ['mprv', 'mid_milestone', 'final_approval'])) {
            return response()->json(['error' => 'Invalid verification type'], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'comment' => 'required|string|max:1000',
            ]);

            // Get or create agreement record
            $agreement = MilestonePreAgreement::firstOrCreate(
                [
                    'milestone_id'      => $milestone->id,
                    'verification_type' => $type,
                ],
                [
                    'status'       => 'pending',
                    'submitted_by' => $userId,
                ]
            );

            // Block if final rejected
//            if ($agreement->isFinalRejected()) {
//                return response()->json([
//                    'error' => 'This agreement has been finally rejected and cannot be reopened.'
//                ], 422);
//            }

            // Block if already agreed
            if ($agreement->isAgreed()) {
                return response()->json([
                    'error' => 'This agreement is already approved.'
                ], 422);
            }

            // If owner rejected and applicant responds - reopen to pending
            if ($agreement->status === 'rejected' && $isApplicant) {
                $agreement->update(['status' => 'pending']);
            }

            // Store comment
            MilestoneAgreementComment::create([
                'agreement_id'    => $agreement->id,
                'user_id'         => $userId,
                'comment'         => $validated['comment'],
                'action'          => 'comment',
            ]);

            DB::commit();

            return response()->json([
                'message'   => 'Comment posted successfully',
                'data'      => $agreement->load('comments.user'),
            ], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // POST /program/milestones/{milestone}/agreements/{type}/approve --- program owner

    public function approve(Request $request, ProgramMilestone $milestone, string $type)
    {
        $userId      = auth()->id();
        $application = $milestone->application;

        if ($application->program->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'comment' => 'nullable|string|max:1000',
            ]);

            $agreement = MilestonePreAgreement::where('milestone_id', $milestone->id)
                ->where('verification_type', $type)->first();

            if (!$agreement) {
                return response()->json(['error' => 'No agreement found for this verification type'], 404);
            }

            if ($agreement->status !== 'pending') {
                return response()->json([
                    'error' => 'Agreement cannot be reviewed. Current status: ' . $agreement->status
                ], 422);
            }

            // Update agreement
            $agreement->update([
                'status'      => 'agreed', 'reviewed_by' => $userId, 'reviewed_at' => now(),
            ]);

            // Store approval comment
            MilestoneAgreementComment::create([
                'agreement_id' => $agreement->id,
                'user_id'      => $userId,
                'comment'      => $validated['comment'] ?? 'Agreement approved.',
                'action'       => 'approve',
            ]);

            // Update milestone ready flag based on type
            $readyField = match($type) {
                'mprv'           => 'mprv_ready',
                'mid_milestone'  => 'mid_milestone_ready',
                'final_approval' => 'final_approval_ready',  // ← add this field to program_milestones if not exists
                default          => null,
            };

            if ($readyField) {
                $milestone->update([$readyField => true]);
            }

            DB::commit();

            return response()->json([
                'message'   => 'Agreement approved successfully',
                'data'      => $agreement->load('comments.user'),
            ], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // ─── Reject (program owner rejects) ───────────────────────────────────
    // POST /program/milestones/{milestone}/agreements/{type}/reject

    public function reject(Request $request, ProgramMilestone $milestone, string $type)
    {
        $userId      = auth()->id();
        $application = $milestone->application;

        if ($application->program->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'rejection_reason' => 'required|string|max:1000',
            ]);

            $agreement = MilestonePreAgreement::where('milestone_id', $milestone->id)
                ->where('verification_type', $type)->first();

            if (!$agreement) {
                return response()->json(['error' => 'No agreement found for this verification type'], 404);
            }

            if ($agreement->status !== 'pending') {
                return response()->json([
                    'error' => 'Agreement cannot be reviewed. Current status: ' . $agreement->status
                ], 422);
            }

            $newRejectionCount = $agreement->rejection_count + 1;
            //$isFinalRejection  = $newRejectionCount >= 2;

            // Update agreement
            $agreement->update([
                'status'          => 'rejected',
                'rejection_count' => $newRejectionCount,
                'reviewed_at'     => now(),
            ]);

            // Store rejection comment
            MilestoneAgreementComment::create([
                'agreement_id'     => $agreement->id,
                'user_id'          => $userId,
                'comment'          => $validated['rejection_reason'],
                'action'           => 'reject',
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            DB::commit();

            return response()->json([
                'message'          => 'Agreement rejected. Applicant can respond and resubmit.',
                'rejection_count'  => $newRejectionCount,
                'data'             => $agreement->load('comments.user'),
            ], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // ─── Get all agreements for a milestone ─────────────────────────────
    // GET /program/milestones/{milestone}/agreements

    public function index(ProgramMilestone $milestone)
    {
        $userId      = auth()->id();
        $application = $milestone->application;

        $isApplicant  = $application->user_id === $userId;
        $isProgramOwner = $application->program->user_id === $userId;

        if (!$isApplicant && !$isProgramOwner) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $agreements = MilestonePreAgreement::where('milestone_id', $milestone->id)
            ->with(['comments.user', 'applicant'])
            ->get()
            ->map(function ($agreement) {
                return [
                    ...$agreement->toArray(),
                    'status' => [
                        'value' => $agreement->status,
                        'color' => config('status.milestone_pre_agreement.' . $agreement->status, 'gray'),
                    ],
                ];
            });

        $allAgreed = collect($agreements)->count() > 0 &&
            collect($agreements)->every(fn($a) => $a['status']['value'] === 'agreed');

        return response()->json([
            'agreements' => $agreements,
            'all_agreed' => $allAgreed,
        ], 200);
    }
}
