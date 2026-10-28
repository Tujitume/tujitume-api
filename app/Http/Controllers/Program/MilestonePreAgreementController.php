<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\MilestoneAgreementComment;
use App\Models\Programs\MilestonePreAgreement;
use App\Service\Misc\ErrorLogService;
use App\Service\Validation\SpamWordChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class MilestonePreAgreementController extends Controller
{
    public function comment(Request $request, ProgramMilestone $milestone, string $type, SpamWordChecker $spam)
    {
        $userId = auth()->id();
        $application = $milestone->application;

        $isApplicant  = (int) $application->user_id === (int) $userId;
        $isProgramOwner = \App\Service\Program\ProgramAccess::owns($application->program);

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
            $validated['comment'] = $this->sanitizeAgreementText($validated['comment'], $spam);

            $agreement = MilestonePreAgreement::firstOrNew([
                'milestone_id' => $milestone->id,
                'verification_type' => $type,
            ]);

            if ($agreement->exists && $isApplicant && $agreement->isAgreed()
                && $application->funding_setup_status !== 'awaiting_applicant_revision') {
                DB::rollBack();
                return response()->json(['error' => 'This agreement has already been approved.'], 422);
            }

            if (!$agreement->exists) {
                $agreement->status = $isApplicant ? 'submitted' : 'pending';
                $agreement->submitted_by = $userId;
            } elseif ($isApplicant) {
                // An applicant response reopens a rejected agreement for owner review.
                $agreement->status = 'submitted';
                $agreement->submitted_by = $userId;
                $agreement->reviewed_by = null;
                $agreement->reviewed_at = null;
            }

            $agreement->save();

            // Store comment
            MilestoneAgreementComment::create([
                'agreement_id'    => $agreement->id,
                'user_id'         => $userId,
                'comment'         => $validated['comment'],
                'action'          => 'comment',
            ]);

            DB::commit();

            $this->notifyAgreementAction($agreement, $milestone, $application, $isApplicant, $validated['comment']);

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

    private function notifyAgreementAction(MilestonePreAgreement $agreement, ProgramMilestone $milestone, $application, bool $isApplicant, string $comment): void
    {
        $program = $application->program;
        $number = $milestone->sequence_order ?? $milestone->id;
        $milestoneHash = 'milestone-' . $number;
        $applicant = $application->user;
        $owner = $program->owner;
        $commentData = json_decode($comment, true);
        $isAgreement = is_array($commentData) && ($commentData['tj'] ?? null) === 'agree';

        $notification = app(\App\Service\Notification\ProgramNotificationService::class);
        $data = [
            'application_id' => $application->id,
            'program_id' => $program->id,
            'program_title' => $program->program_title,
            'business_name' => $application->startup_name,
            'milestone_number' => $number,
            'milestone_id' => $milestone->id,
            'milestone_title' => $milestone->title,
            'milestone_hash' => $milestoneHash,
            'agreement_id' => $agreement->id,
            'verification_type' => $agreement->verification_type,
            'verification_label' => match ($agreement->verification_type) {
                'mprv' => 'MPRV',
                'mid_milestone' => 'mid-milestone review',
                'final_approval' => 'final approval',
                default => 'verification',
            },
        ];

        if ($isApplicant && $isAgreement) {
            $data['request_count'] = count($commentData['q'] ?? []);
            try {
                $notification->send('agreement.agreed', [$owner], $data);
            } catch (\Throwable $e) {
                ErrorLogService::report($e, ['notification_event' => 'agreement.agreed']);
            }
            return;
        }

        // Ordinary agreement messages are throttled per conversation and recipient.
        $recipient = $isApplicant ? $owner : $applicant;
        if (!$recipient || !Cache::add('agreement-message:' . $agreement->id . ':' . $recipient->id, true, now()->addMinutes(10))) {
            return;
        }
        $data['actor_side'] = $isApplicant ? 'applicant' : 'owner';
        $data['chat_field_id'] = is_array($commentData) && ($commentData['tj'] ?? null) === 'msg'
            ? ($commentData['f'] ?? null)
            : null;
        $data['comment'] = is_array($commentData) && ($commentData['tj'] ?? null) === 'msg'
            ? (string) ($commentData['t'] ?? $commentData['m'] ?? '')
            : $comment;
        try {
            $notification->send('agreement.message', [$recipient], $data);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['notification_event' => 'agreement.message']);
        }
    }

    // POST /program/milestones/{milestone}/agreements/{type}/approve --- program owner

    public function approve(Request $request, ProgramMilestone $milestone, string $type, SpamWordChecker $spam)
    {
        $userId      = auth()->id();
        $application = $milestone->application;

        if (!\App\Service\Program\ProgramAccess::owns($application->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'comment' => 'nullable|string|max:1000',
            ]);
            if (isset($validated['comment'])) $validated['comment'] = $this->sanitizeAgreementText($validated['comment'], $spam);

            $agreement = MilestonePreAgreement::where('milestone_id', $milestone->id)
                ->where('verification_type', $type)->first();

            if (!$agreement) {
                DB::rollBack();
                return response()->json(['error' => 'No agreement found for this verification type'], 404);
            }

            if ($agreement->status !== 'submitted') {
                DB::rollBack();
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

    public function reject(Request $request, ProgramMilestone $milestone, string $type, SpamWordChecker $spam)
    {
        $userId      = auth()->id();
        $application = $milestone->application;

        if (!\App\Service\Program\ProgramAccess::owns($application->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'rejection_reason' => 'required|string|max:1000',
            ]);
            $validated['rejection_reason'] = $this->sanitizeAgreementText($validated['rejection_reason'], $spam);

            $agreement = MilestonePreAgreement::where('milestone_id', $milestone->id)
                ->where('verification_type', $type)->first();

            if (!$agreement) {
                return response()->json(['error' => 'No agreement found for this verification type'], 404);
            }

            if ($agreement->status !== 'submitted') {
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

    public function markRead(Request $request, ProgramMilestone $milestone, string $type)
    {
        $application = $milestone->application;
        $userId = auth()->id();
        if ((int) $application->user_id !== (int) $userId
            && !\App\Service\Program\ProgramAccess::owns($application->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        if (!in_array($type, ['mprv', 'mid_milestone', 'final_approval'], true)) {
            return response()->json(['error' => 'Invalid verification type'], 422);
        }

        $validated = $request->validate(['last_read_comment_id' => 'required|integer|min:1']);
        $agreement = MilestonePreAgreement::where('milestone_id', $milestone->id)
            ->where('verification_type', $type)->first();
        if (!$agreement) return response()->json(['error' => 'No agreement found for this verification type'], 404);

        $commentId = (int) $validated['last_read_comment_id'];
        if (!$agreement->comments()->whereKey($commentId)->exists()) {
            return response()->json(['error' => 'Comment does not belong to this agreement'], 422);
        }

        DB::transaction(function () use ($agreement, $userId, $commentId) {
            $read = $agreement->reads()->where('user_id', $userId)->lockForUpdate()->first();
            if ($read && (int) $read->last_read_comment_id >= $commentId) return;

            $values = ['last_read_comment_id' => $commentId, 'read_at' => now()];
            if ($read) $read->update($values);
            else $agreement->reads()->create(['user_id' => $userId] + $values);
        });

        return response()->noContent();
    }

    private function sanitizeAgreementText(string $text, SpamWordChecker $spam): string
    {
        $clean = function (string $value) use ($spam): string {
            $value = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}/', '***@***.***', $value);
            $value = preg_replace('/(\\+\\d{1,3}[-.\\s]?)?\\(?\\d{3}\\)?[-.\\s]?\\d{3}[-.\\s]?\\d{4}/', '***-***-****', $value);
            return $spam->censorText($value);
        };

        $data = json_decode($text, true);
        if (is_array($data) && ($data['tj'] ?? null) === 'msg') {
            $textKey = isset($data['t']) && is_string($data['t']) ? 't' : 'm';
            if (isset($data[$textKey]) && is_string($data[$textKey])) $data[$textKey] = $clean($data[$textKey]);
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $text;
        }
        if (is_array($data) && ($data['tj'] ?? null) === 'agree') {
            if (isset($data['m']) && is_string($data['m'])) $data['m'] = $clean($data['m']);
            foreach (is_array($data['q'] ?? null) ? $data['q'] : [] as $index => $request) {
                if (!is_array($request)) continue;
                if (isset($request['n']) && is_string($request['n'])) $data['q'][$index]['n'] = $clean($request['n']);
            }
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $text;
        }
        return $clean($text);
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
            ->with(['comments.user', 'applicant', 'reads'])
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
