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
            } elseif ($isApplicant && $agreement->status !== 'final_rejected') {
                // An applicant response reopens a rejected agreement for owner review (not one rejected twice, DECISION-8).
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
            $this->broadcastUpdate($milestone, $type, 'comment');

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

    /** ISSUE-42: tell every open drawer of this step at once; a websocket that cannot be reached never fails the save */
    private function broadcastUpdate(ProgramMilestone $milestone, string $type, string $action): void
    {
        try {
            broadcast(new \App\Events\StepAgreementUpdated((int) $milestone->id, $type, $action, auth()->id()))->toOthers();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Step conversation broadcast failed', ['milestone_id' => $milestone->id, 'error' => $e->getMessage()]);
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
                // the whole owner program team (ISSUE-42), not only the program's creator
                $notification->send('agreement.agreed', \App\Service\Program\ProgramTeam::members($program)->all(), $data);
            } catch (\Throwable $e) {
                ErrorLogService::report($e, ['notification_event' => 'agreement.agreed']);
            }
            return;
        }

        // Ordinary agreement messages go to the other side: the applicant, or everyone on the owner's program team (ISSUE-42).
        // They are throttled per conversation and recipient (at most one notice / email every 10 minutes), a digest
        // rather than one per message; the open drawer updates live (StepAgreementUpdated).
        $others = $isApplicant
            ? \App\Service\Program\ProgramTeam::members($program)->reject(fn ($u) => (int) $u->id === (int) auth()->id())
            : collect([$applicant])->filter();
        $recipients = $others->filter(fn ($u) => Cache::add('agreement-message:' . $agreement->id . ':' . $u->id, true, now()->addMinutes(10)))->values();
        if ($recipients->isEmpty()) {
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
            $notification->send('agreement.message', $recipients->all(), $data);
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
            $this->broadcastUpdate($milestone, $type, 'approve');

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
            // DECISION-8: rejected twice, the agreement is settled as final_rejected (no endless loop); the step goes
            // ahead, and its checks are audited by a project manager (RepeatedRejectionAudit)
            $isFinalRejection = $newRejectionCount >= \App\Service\Program\RepeatedRejectionAudit::LIMIT;

            // Update agreement
            $agreement->update([
                'status'          => $isFinalRejection ? 'final_rejected' : 'rejected',
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
            $this->broadcastUpdate($milestone, $type, 'reject');

            $application = $milestone->application;
            $data = ['business_name' => $application->startup_name, 'milestone_number' => $milestone->sequence_order,
                'application_id' => $application->id, 'program_id' => $application->program_id,
                'check' => ['mprv' => 'MPRV', 'mid_milestone' => 'mid-milestone check', 'final_approval' => 'final approval'][$type] ?? $type,
                'step' => ['mprv' => 'mprv', 'mid_milestone' => 'mid-milestone', 'final_approval' => 'final-approval'][$type] ?? 'mprv'];

            // ISSUE-42: a first rejection tells the applicant what to change (in-app + email, link to the step)
            if (! $isFinalRejection) {
                try {
                    app(\App\Service\Notification\ProgramNotificationService::class)->send('agreement.changes_requested', [$application->user], $data + [
                        'reason' => $validated['rejection_reason'],
                        'email_title' => 'Changes asked for on the '.$data['check'].' instructions',
                        'email_message' => 'The program team asked for changes to the '.$data['check'].' instructions for milestone '.$milestone->sequence_order.': "'.$validated['rejection_reason'].'". Reply in the step conversation and agree again.',
                        'action_label' => 'Open the step',
                    ]);
                } catch (\Throwable $e) {
                    ErrorLogService::report($e, ['agreement_id' => $agreement->id]);
                }
            }

            if ($isFinalRejection) {
                try {
                    $notify = app(\App\Service\Notification\ProgramNotificationService::class);
                    $notify->send('verification.audit_needed_applicant', [$application->user], $data + [
                        'email_title' => 'Your check goes to an independent audit',
                        'email_message' => 'The instructions for the '.$data['check'].' of milestone '.$milestone->sequence_order.' were not agreed after two rounds, so an independent project manager will audit that check when you submit it.',
                        'action_label' => 'Open your deal room',
                    ]);
                    $notify->send('verification.audit_needed', \App\Service\Program\ProgramTeam::members($application->program)->all(), $data + [
                        'email_title' => 'A project manager audit will be needed',
                        'email_message' => 'The '.$data['check'].' instructions for milestone '.$milestone->sequence_order.' were rejected twice. That check will need a project manager audit: pick one when it is submitted.',
                        'action_label' => 'Open the deal room',
                    ]);
                } catch (\Throwable $e) {
                    ErrorLogService::report($e, ['agreement_id' => $agreement->id]);
                }
            }

            return response()->json([
                'message'          => $isFinalRejection
                    ? 'Agreement rejected a second time. It is settled, and this check will be audited by a project manager.'
                    : 'Agreement rejected. Applicant can respond and resubmit.',
                'final'            => $isFinalRejection,
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

    /**
     * DECISION-8 change: an agreement rejected twice (final_rejected) can be reopened by the owner team, who then review
     * the applicant's instructions themselves (approve or reject) instead of sending the step's check to an audit.
     * POST milestones/{milestone}/agreements/{type}/reopen
     */
    public function reopen(Request $request, ProgramMilestone $milestone, string $type)
    {
        if (! \App\Service\Program\ProgramAccess::owns($milestone->application->program)) {
            return response()->json(['message' => 'Only the program team can reopen this agreement.'], 403);
        }
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);
        $agreement = MilestonePreAgreement::where('milestone_id', $milestone->id)->where('verification_type', $type)->first();
        if (! $agreement) return response()->json(['message' => 'No agreement found for this check.'], 404);
        if ($agreement->status !== 'final_rejected') {
            return response()->json(['message' => 'Only an agreement rejected twice can be reopened.'], 409);
        }

        DB::transaction(function () use ($agreement, $data) {
            // Back to waiting for the owner's decision on the applicant's latest instructions
            $agreement->update(['status' => 'submitted', 'reviewed_at' => null]);
            MilestoneAgreementComment::create([
                'agreement_id' => $agreement->id,
                'user_id' => auth()->id(),
                'comment' => 'Reopened by the program team to review it themselves (no project manager audit).'.(filled($data['reason'] ?? null) ? ' '.trim($data['reason']) : ''),
                'action' => 'comment',
            ]);
        });
        $this->broadcastUpdate($milestone, $type, 'reopen');

        return response()->json(['message' => 'Agreement reopened. Approve or reject it yourself.', 'data' => $agreement->fresh()]);
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
                    'status' => \App\Support\ApiContract::statusMeta('milestone_pre_agreement', $agreement->status),
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
