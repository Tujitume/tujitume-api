<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\MilestoneVerification;
use App\Models\Milestones\MilestoneCompletionSubmission;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MilestoneVerificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ProgramMilestone $milestone)
    {
        try {
            (new \App\Service\Program\ProgramAccess())->authorizeMilestone(auth()->user(), $milestone);
            $verifications = MilestoneVerification::where('milestone_id', $milestone->id)
                // The project manager auditing it, shown with their report (audit_status, audit_recommendation, audit_notes)
                ->with('auditor:id,first_name,last_name,image')
                ->orderBy('created_at', 'desc')
                ->get();
            return response()->json(['verifications' => $verifications]);
        }
        catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, ProgramMilestone $milestone)
    {
        // Authorization: only the applicant can submit MPRV
        if ($milestone->application->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $suppliersCount = $milestone->suppliers()->count();
        // if ($suppliersCount === 0) {
        //     return response()->json(['error' => 'Cannot submit MPRV. Please add suppliers first.'], 422);
        // }

        $budgetItemsCount = $milestone->budgetItems()->count();
        // if ($budgetItemsCount === 0) {
        //     return response()->json(['error' => 'Cannot submit MPRV. Please add budget items first.'], 422);
        // }

        // $budgetTotal = $milestone->budgetItems()->sum('total_cost');
        // if ($budgetTotal != $milestone->amount) {
        //     return response()->json(['error' => 'Budget items total (' . $budgetTotal . ') must match milestone amount (' . $milestone->amount . ')'], 422);
        // }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                // Required declarations
                'conflict_of_interest_confirmed' => 'required|boolean|accepted',
                'funds_usage_confirmed' => 'required|boolean|accepted',
                // Optional
                'additional_declarations' => 'nullable|string',
                'verification_type' => 'required|string|in:mprv,mid_milestone,final_approval',
                'document' => 'nullable|file|mimes:pdf,doc,docx,jpg,png,gif,jpeg|max:5120',  // 5MB
                'answers' => 'nullable|array',
            ]);

            $verificationType = $validated['verification_type'];
            $this->validateConfiguredStepAnswers(
                $milestone,
                $verificationType,
                $request->input('additional_declarations'),
                $request->hasFile('document') ? 1 : 0
            );
            $requiredFlag = match ($verificationType) {
                'mprv' => 'mprv_required',
                'mid_milestone' => 'mid_milestone_required',
                'final_approval' => 'final_approval_required',
            };
            if (!$milestone->{$requiredFlag}) {
                throw ValidationException::withMessages([
                    'verification_type' => ['This verification is not required for the milestone.'],
                ]);
            }

            // ISSUE-30: a milestone takes no checks while an earlier one is unfinished
            if ($blocker = $milestone->waitingFor()) {
                throw ValidationException::withMessages([
                    'verification_type' => ["This milestone opens once Milestone {$blocker->sequence_order} is complete."],
                ]);
            }

            // ISSUE-19 (owner decision, 27 Nov): final approval is the end of a funded milestone, so it opens only once the
            // milestone's funds have been released
            if ($verificationType === 'final_approval' && ! (int) $milestone->fund_released) {
                throw ValidationException::withMessages([
                    'verification_type' => ['Final approval opens once the funds for this milestone are released.'],
                ]);
            }

            if ($verificationType === 'mprv' && !in_array($milestone->status, ['pending', 'submitted', 'rejected'], true)) {
                throw ValidationException::withMessages([
                    'verification_type' => ['MPRV can only be submitted for pending or rejected milestones. Current status: ' . $milestone->status],
                ]);
            }

            $lastTypeVerification = MilestoneVerification::where('milestone_id', $milestone->id)
                ->where('verification_type', $verificationType)
                ->latest('id')
                ->first();
            if ($lastTypeVerification && in_array($lastTypeVerification->decision, ['pending', 'approved', 'audit_requested'], true)) {
                throw ValidationException::withMessages([
                    'verification_type' => ['This verification already has a submission that is pending or approved.'],
                ]);
            }

            $questionsByType = collect($milestone->verification_questions ?? [])
                ->firstWhere('type', $verificationType)['questions'] ?? [];
            $submittedAnswers = $request->input('answers', []);
            $uploadedAnswers = $request->allFiles()['answers'] ?? [];
            $submittedIndexes = array_unique(array_merge(
                array_map('strval', array_keys($submittedAnswers)),
                array_map('strval', array_keys($uploadedAnswers))
            ));
            $allowedIndexes = array_map('strval', array_keys($questionsByType));
            if (array_diff($submittedIndexes, $allowedIndexes)) {
                throw ValidationException::withMessages(['answers' => ['Answers may only be provided for configured questions.']]);
            }

            $answers = [];
            foreach ($questionsByType as $index => $question) {
                $key = (string) $index;
                $submitted = $submittedAnswers[$key] ?? $submittedAnswers[$index] ?? null;
                // Continue accepting the original plain-string answer format.
                if (!is_array($submitted)) {
                    $submitted = ['answer' => $submitted];
                }
                $dontHave = filter_var($submitted['dont_have'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $note = $submitted['note'] ?? null;
                $answer = $submitted['answer'] ?? null;
                $fileEntry = $uploadedAnswers[$key] ?? $uploadedAnswers[$index] ?? null;
                $file = is_array($fileEntry) ? ($fileEntry['file'] ?? null) : $fileEntry;
                $required = array_key_exists('required', $question)
                    ? filter_var($question['required'], FILTER_VALIDATE_BOOLEAN)
                    : true;

                if ($note !== null && (!is_string($note) || mb_strlen($note) > 1000)) {
                    throw ValidationException::withMessages(["answers.$key.note" => ['The note must be 1000 characters or fewer.']]);
                }
                if ($dontHave && (!is_string($note) || trim($note) === '')) {
                    throw ValidationException::withMessages(["answers.$key.note" => ['Please explain why you do not have this item.']]);
                }

                $questionType = $question['type'] ?? 'text';
                if (!$dontHave && $questionType === 'file_upload') {
                    if ($file instanceof \Illuminate\Http\UploadedFile) {
                        if (!$file->isValid() || $file->getSize() > 5120 * 1024) {
                            throw ValidationException::withMessages(["answers.$key.file" => ['The file must be valid and no larger than 5 MB.']]);
                        }
                    } elseif ($required) {
                        throw ValidationException::withMessages(["answers.$key.file" => ['A file is required for this question.']]);
                    }
                } elseif (!$dontHave && $questionType !== 'file_upload') {
                    if ($answer !== null && (!is_string($answer) || mb_strlen($answer) > 10000)) {
                        throw ValidationException::withMessages(["answers.$key.answer" => ['The answer must be text with no more than 10000 characters.']]);
                    }
                    if ($required && (!is_string($answer) || trim($answer) === '')) {
                        throw ValidationException::withMessages(["answers.$key.answer" => ['An answer is required for this question.']]);
                    }
                }

                if ($file instanceof \Illuminate\Http\UploadedFile && !$dontHave) {
                    if ($questionType !== 'file_upload') {
                        throw ValidationException::withMessages(["answers.$key.file" => ['This question does not accept a file.']]);
                    }
                    $file = $this->fileUpload->saveFile($file, 'files/programMilestoneVerifications/' . $milestone->id);
                }

                $answers[$key] = [
                    'answer' => $dontHave ? null : $answer,
                    'file' => $dontHave ? null : ($file instanceof \Illuminate\Http\UploadedFile ? null : $file),
                    'dont_have' => $dontHave,
                    'note' => $note,
                ];
            }
            $validated['answers'] = $answers;

            // Get attempt number (for resubmissions)
            $lastAttempt = MilestoneVerification::where('milestone_id', $milestone->id)
                ->where('verification_type', $verificationType)
                ->max('attempt_number');
            $validated['attempt_number'] = $lastAttempt ? $lastAttempt + 1 : 1;

            $validated['milestone_id'] = $milestone->id;
            $validated['submitted_by'] = auth()->id();
            $validated['decision'] = 'pending';
            // DECISION-8: sent back twice already (or its step agreement rejected twice): this one waits for a project
            // manager audit, picked by the owner, before anyone decides it
            $auditNeeded = \App\Service\Program\RepeatedRejectionAudit::required($milestone, $verificationType);
            if ($auditNeeded) $validated['audit_status'] = 'needed';

            // Handle file uploads
            $uploadedFiles = [];
            if ($request->hasFile('document')) {
                $path = 'files/programMilestoneVerifications/' . $milestone->id;
                $filePath = $this->fileUpload->saveFile($request->file('document'), $path);
                $validated['document'] = $filePath;

                $uploadedFiles[] = $filePath;

                if ($validated['document'] == null) {
                    return response()->json(['error' => 'File upload failed in backend.' . $request->file('document')], 422);
                }
            }

            $verification = MilestoneVerification::create($validated);

            // Update milestone status
            if ($verificationType === 'mprv') {
                $milestone->status = 'submitted';
                $milestone->mprv_ready = false;
                $milestone->save();
            }

            DB::commit();

            // ISSUE-18: each check has its own event, wording and link (MPRV / mid-milestone / final approval)
            $label = ['mprv' => 'MPRV', 'mid_milestone' => 'Mid-milestone check', 'final_approval' => 'Final approval'][$verificationType];
            $inSentence = ['mprv' => 'MPRV', 'mid_milestone' => 'mid-milestone check', 'final_approval' => 'final approval'][$verificationType];
            if ($auditNeeded) {
                $data = [
                    'business_name' => $milestone->application->startup_name, 'milestone_number' => $milestone->sequence_order,
                    'application_id' => $milestone->app_id, 'program_id' => $milestone->application->program_id, 'check' => $inSentence,
                    'step' => ['mprv' => 'mprv', 'mid_milestone' => 'mid-milestone', 'final_approval' => 'final-approval'][$verificationType],
                ];
                $this->programNotification->send('verification.audit_needed', \App\Service\Program\ProgramTeam::members($milestone->application->program)->all(), $data + [
                    'email_title' => 'Pick a project manager to audit',
                    'email_message' => $milestone->application->startup_name.' sent the '.$inSentence.' for milestone '.$milestone->sequence_order.' again after it was sent back twice. It now needs an independent project manager audit: pick a project manager in the deal room.',
                    'action_label' => 'Pick a project manager',
                ]);
                $this->programNotification->send('verification.audit_needed_applicant', [$milestone->application->user], $data + [
                    'email_title' => 'Your check goes to an independent audit',
                    'email_message' => 'Your '.$inSentence.' for milestone '.$milestone->sequence_order.' was sent back twice, so this submission goes to an independent project manager, who checks it and reports to the program. You will be told the result.',
                    'action_label' => 'Open your deal room',
                ]);

                return response()->json([
                    'message' => $label.' submitted. It was sent back twice before, so an independent project manager will audit it.',
                    'audit_needed' => true,
                    'data' => $verification,
                ], 201);
            }

            $this->programNotification->send($verificationType.'.submitted', \App\Service\Program\ProgramTeam::members($milestone->application->program)->all(), [
                'business_name' => $milestone->application->startup_name, 'milestone_number' => $milestone->sequence_order,
                'application_id' => $milestone->app_id,
                'email_title' => $label.' submitted',
                'email_message' => $milestone->application->startup_name.' submitted the '.$inSentence.' for milestone '.$milestone->sequence_order.'. Review it, then approve it or ask for changes.',
                'action_label' => 'Review '.$inSentence,
            ]);

            return response()->json([
                'message' => $label.' submitted successfully. Program owner will review.',
                'file' => $request->file('document'),
                's3' => $validated['document'] ?? 'n/a',
                'data' => $verification,
            ], 201);

        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) DB::rollBack(); // opened above; a refusal must not leave it open (ISSUE-27)
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();

//            foreach ($uploadedFiles[] as $file) {
//
//            }

            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function approve(Request $request, MilestoneVerification $verification)
    {
        // Authorization: only program owner can approve
        $milestone = $verification->milestone; $owner_id = Auth::id();
        if ($milestone->application->program_owner_id !== $owner_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check if already decided
        if (\App\Service\Program\RepeatedRejectionAudit::waiting($verification)) {
            return response()->json(['message' => 'This check was sent back twice, so it needs a project manager audit first. Pick a project manager.', 'error' => 'audit_needed'], 422);
        }
        if (! in_array($verification->decision, ['pending', 'audit_requested'], true)) {
            return response()->json([
                'error' => 'This verification has already been ' . $verification->decision
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'decision_notes' => 'nullable|string',
            ]);

            // Update verification
            $verification->decision = 'approved';
            $verification->decided_by =$owner_id;
            $verification->decided_at = now();
            $verification->decision_notes = $validated['decision_notes'] ?? null;
            $verification->save();

            // Keep the program milestone's lifecycle tied to MPRV. Later checks
            // only update their own ready flag so they cannot reset fund release state.
            $readyField = $this->readyFieldFor($verification->verification_type);
            if ($readyField) {
                $milestone->{$readyField} = true;
            }
            $canReleaseInSequence = $milestone->canReleaseInSequence();
            if ($verification->verification_type === 'mprv' && $canReleaseInSequence) {
                $milestone->status = 'approved';
                $milestone->fund_release_status = 'approved';
                $milestone->approved_by = $owner_id;
                $milestone->approved_at = now();
            }
            $milestone->save();

            // ISSUE-21: approving the final approval check finishes this milestone and opens the next one, exactly as an
            // approved completion report does (the app uses this check for final approval)
            $nextMilestone = $verification->verification_type === 'final_approval'
                ? $this->unlockNextMilestone($milestone, $owner_id)
                : null;
            DB::commit();

            // The application may now be in monitoring, or completed (ApplicationCompletion)
            if ($verification->verification_type === 'final_approval') {
                \App\Service\Program\ApplicationCompletion::refresh($milestone->application);
            }

            $this->sendVerificationNotification($verification, 'milestone_verification.approved');
            if ($nextMilestone) {
                $this->programNotification->send('milestone.unlocked', [$milestone->application->user], [
                    'milestone_number' => $nextMilestone->sequence_order, 'application_id' => $milestone->app_id,
                ]);
            }

            return response()->json([
                'message' => $verification->verification_type === 'mprv'
                    ? ($canReleaseInSequence
                        ? 'MPRV approved. Milestone ready for disbursement.'
                        : 'MPRV approved. Milestone remains locked until earlier milestones are completed.')
                    : ucfirst(str_replace('_', ' ', $verification->verification_type)) . ' approved.',
                'data' => $verification,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Reject MPRV
     * PATCH /api/v1/verifications/{verification}/reject
     */
    public function reject(Request $request, MilestoneVerification $verification)
    {
        // Authorization: only program owner can reject
        $milestone = $verification->milestone;
        if ($milestone->application->program_owner_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check if already decided
        if (\App\Service\Program\RepeatedRejectionAudit::waiting($verification)) {
            return response()->json(['message' => 'This check was sent back twice, so it needs a project manager audit first. Pick a project manager.', 'error' => 'audit_needed'], 422);
        }
        if (! in_array($verification->decision, ['pending', 'audit_requested'], true)) {
            return response()->json([
                'error' => 'This verification has already been ' . $verification->decision
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'decision_notes' => 'required|string',
            ]);

            // Update verification
            $verification->decision = 'rejected';
            $verification->decided_by = auth()->id();
            $verification->decided_at = now();
            $verification->decision_notes = $validated['decision_notes'];
            $verification->save();

            $readyField = $this->readyFieldFor($verification->verification_type);
            if ($readyField) {
                $milestone->{$readyField} = false;
            }
            if ($verification->verification_type === 'mprv') {
                $milestone->status = 'rejected';
                $milestone->rejection_reason = $validated['decision_notes'];
            }
            $milestone->save();

            DB::commit();

            $this->sendVerificationNotification(
                $verification,
                'milestone_verification.rejected',
                $validated['decision_notes']
            );

            return response()->json([
                'message' => $verification->verification_type === 'mprv'
                    ? 'MPRV rejected. Business owner can fix issues and resubmit.'
                    : ucfirst(str_replace('_', ' ', $verification->verification_type)) . ' rejected.',
                'data' => $verification,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /** Ask the applicant to update and resubmit this verification. */
    public function requestModification(Request $request, MilestoneVerification $verification)
    {
        $milestone = $verification->milestone;
        if ($milestone->application->program_owner_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        if (\App\Service\Program\RepeatedRejectionAudit::waiting($verification)) {
            return response()->json(['message' => 'This check was sent back twice, so it needs a project manager audit first. Pick a project manager.', 'error' => 'audit_needed'], 422);
        }
        if (! in_array($verification->decision, ['pending', 'audit_requested'], true)) {
            return response()->json(['error' => 'This verification has already been ' . $verification->decision], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'decision_notes' => 'required|string|max:2000',
            ]);

            $verification->update([
                'decision' => 'modification_requested',
                'decided_by' => auth()->id(),
                'decided_at' => now(),
                'decision_notes' => $validated['decision_notes'],
            ]);

            $readyField = $this->readyFieldFor($verification->verification_type);
            if ($readyField) {
                $milestone->update([$readyField => false]);
            }
            if ($verification->verification_type === 'mprv') {
                $milestone->update(['status' => 'rejected', 'rejection_reason' => $validated['decision_notes']]);
            }

            DB::commit();
            $this->sendVerificationNotification(
                $verification,
                'milestone_verification.modification_requested',
                $validated['decision_notes']
            );

            return response()->json([
                'message' => 'Modification requested. The applicant can update and resubmit this verification.',
                'data' => $verification->fresh(),
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * A milestone's final approval (by completion report, or by the final approval check) opens the next milestone:
     * its funds become releasable straight away when it needs no MPRV (or its MPRV is already approved), otherwise it
     * waits for its MPRV. Returns the next milestone, or null when this was the last.
     */
    private function unlockNextMilestone(ProgramMilestone $milestone, int $userId): ?ProgramMilestone
    {
        $nextMilestone = ProgramMilestone::where('app_id', $milestone->app_id)
            ->where('is_template', false)
            ->where('sequence_order', '>', $milestone->sequence_order)
            ->orderBy('sequence_order')
            ->first();
        if (! $nextMilestone) return null;

        if ($nextMilestone->status === 'pending') {
            // program_milestones has no `notes` column (this assignment made save() fail); the note goes to an existing one
            $nextMilestone->verification_notes = $nextMilestone->verification_notes ?: 'Unlocked: Previous milestone completed';
        }

        if ($nextMilestone->fund_release_status === 'locked'
            && (!$nextMilestone->mprv_required || $nextMilestone->mprv_ready)) {
            $nextMilestone->fund_release_status = 'approved';
            $nextMilestone->status = 'approved';
            $nextMilestone->approved_by = $userId;
            $nextMilestone->approved_at = now();
        }

        $nextMilestone->save();

        return $nextMilestone;
    }

    private function readyFieldFor(string $verificationType): ?string
    {
        return match ($verificationType) {
            'mprv' => 'mprv_ready',
            'mid_milestone' => 'mid_milestone_ready',
            'final_approval' => 'final_approval_ready',
            default => null,
        };
    }

    private function sendVerificationNotification(
        MilestoneVerification $verification,
        string $event,
        ?string $notes = null
    ): void {
        $milestone = $verification->milestone;
        $application = $milestone->application;
        $typeLabel = match ($verification->verification_type) {
            'mprv' => 'MPRV',
            'mid_milestone' => 'Mid-milestone verification',
            'final_approval' => 'Final approval',
            default => ucfirst(str_replace('_', ' ', $verification->verification_type)),
        };
        $tab = match ($verification->verification_type) {
            'mprv' => 'mprv',
            'mid_milestone' => 'mid',
            'final_approval' => 'final',
            default => 'plan',
        };

        $this->programNotification->send($event, [$application->user], [
            'application_id' => $application->id,
            'program_id' => $application->program->id,
            'program_title' => $application->program->program_title,
            'business_name' => $application->startup_name,
            'milestone_number' => $milestone->sequence_order,
            'verification_label' => $typeLabel,
            'verification_tab' => $tab,
            'decision_notes' => $notes,
        ]);
    }

    /**
     * Request Audit
     * PATCH /api/v1/verifications/{verification}/request-audit
     */
    public function requestAudit(Request $request, MilestoneVerification $verification)
    {
        // Authorization: the program's owner team (anyone of them may pick the project manager, DECISION-8)
        $milestone = $verification->milestone;
        if (! \App\Service\Program\ProgramAccess::owns($milestone->application->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check if already decided
        if ($verification->decision !== 'pending') {
            return response()->json([
                'error' => 'This verification has already been ' . $verification->decision
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                // A project manager: a service provider, who answers it in Project Manager Audits
                'auditor_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('user_type_id', 3)],
                'decision_notes' => 'nullable|string',
            ]);

            // Update verification
            $verification->decision = 'audit_requested';
            $verification->decided_by = auth()->id();
            $verification->decided_at = now();
            $verification->decision_notes = $validated['decision_notes'] ?? null;
            $verification->auditor_id = $validated['auditor_id'];
            $verification->audit_started_at = now();
            // A fresh audit: the auditor answers it in Project Manager Audits (accept / decline, then a report)
            $verification->audit_status = 'pending';
            $verification->audit_recommendation = null;
            $verification->audit_notes = null;
            $verification->audit_completed_at = null;
            $verification->save();

            // Update milestone
            $milestone->status = 'audit_requested';
            $milestone->save();

            DB::commit();

            $this->programNotification->send('mprv.audit_requested', [User::find($validated['auditor_id'])], [
                'business_name' => $verification->milestone->application->startup_name, 'milestone_number' => $verification->milestone->sequence_order,
                'application_id' => $verification->milestone->app_id,
                'audit_stage' => $verification->verification_type === 'mprv' ? 'pre_release' : $verification->verification_type,
            ]);

            return response()->json([
                'message' => 'Audit requested. Project Manager has been assigned.',
                'data' => $verification,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    # M i l e s t o n e   C o m p l e t i o n s

    public function index_completions(ProgramMilestone $milestone)
    {
        $userId = auth()->id();
        $programOwnerId = $milestone->application->program_owner_id;
        $businessOwnerId = $milestone->application->user_id;

        if (!in_array($userId, [$programOwnerId, $businessOwnerId])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get all completion submissions for this milestone
        $completions = MilestoneCompletionSubmission::where('milestone_id', $milestone->id)
            ->orderBy('attempt_number', 'desc')->get();

        return response()->json([
            'data' => $completions,
        ]);
    }

    public function store_completion(Request $request, ProgramMilestone $milestone)
    {
        $userId = auth()->id();

        // Authorization: only the applicant can submit completion
        if ($milestone->application->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check milestone status
        if (!in_array($milestone->status, ['approved', 'disbursing', 'completed', 'completion_rejected'])) {
            return response()->json([
                'error' => 'Completion can only be submitted for approved or disbursed milestones. Current status: ' . $milestone->status
            ], 422);
        }

        // Check if funds have been released
        if ($milestone->fund_release_status !== 'released') {
            return response()->json([
                'error' => 'Funds must be fully released before submitting completion. Current status: ' . $milestone->fund_release_status
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                // Required fields
                'completion_report' => 'required|string',

                // Optional fields
                'delivery_notes' => 'nullable|string',
                'proof_files' => 'nullable|array',
                'proof_files.*' => 'file|max:5120',  // 5MB per file
            ]);

            $this->validateConfiguredStepAnswers(
                $milestone,
                'final_approval',
                $request->input('delivery_notes'),
                count($request->file('proof_files', []))
            );

            // Get attempt number (for resubmissions)
            $lastAttempt = MilestoneCompletionSubmission::where('milestone_id', $milestone->id)
                ->max('attempt_number');
            $validated['attempt_number'] = $lastAttempt ? $lastAttempt + 1 : 1;

            $validated['milestone_id'] = $milestone->id;
            $validated['submitted_by'] = $userId;
            $validated['decision'] = 'pending';

            // Handle file uploads
            $uploadedFiles = [];
            if ($request->hasFile('proof_files')) {
                $path = 'files/programCompletionProofs/' . $milestone->id;

                foreach ($request->file('proof_files') as $file) {
                    $filePath = $this->fileUpload->saveFile($file, $path);
                    $uploadedFiles[] = $filePath;
                }

                $validated['proof_files'] = json_encode($uploadedFiles);
            }

            $completion = MilestoneCompletionSubmission::create($validated);

            // Update milestone status
            $milestone->status = 'completed';
            $milestone->completed_at = now();
            $milestone->save();

            DB::commit();

            $this->programNotification->send('completion.submitted', \App\Service\Program\ProgramTeam::members($milestone->application->program)->all(), [
                'business_name' => $milestone->application->startup_name, 'milestone_number' => $milestone->sequence_order,
                'application_id' => $milestone->app_id,
            ]);

            return response()->json([
                'message' => 'Completion submitted successfully. Program owner will review.',
                'data' => $completion,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /** Enforce required custom fields stored in the matching milestone *_notes column. */
    private function validateConfiguredStepAnswers(ProgramMilestone $milestone, string $type, ?string $rawSubmission, int $uploadedFileCount): void
    {
        $notesField = match ($type) {
            'mprv' => 'mprv_notes',
            'mid_milestone' => 'mid_milestone_notes',
            'final_approval' => 'final_approval_notes',
        };
        $notes = $milestone->{$notesField};
        if (is_string($notes)) $notes = json_decode($notes, true);
        $fields = is_array($notes) && ($notes['v'] ?? null) === 1 && is_array($notes['fields'] ?? null)
            ? $notes['fields']
            : [];
        if (!$fields) return;

        $submission = is_string($rawSubmission) ? json_decode($rawSubmission, true) : null;
        $answers = is_array($submission) && ($submission['v'] ?? null) === 1 && is_array($submission['answers'] ?? null)
            ? $submission['answers']
            : [];
        $requiredFileCount = 0;

        foreach ($fields as $field) {
            if (!is_array($field) || !filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOLEAN)) continue;
            $id = (string) ($field['id'] ?? '');
            $answer = $id !== '' ? ($answers[$id] ?? null) : null;
            $value = is_array($answer) && array_key_exists('value', $answer) ? $answer['value'] : null;
            $isEmpty = $value === null
                || (is_string($value) && trim($value) === '')
                || (is_array($value) && count($value) === 0);

            if (($field['type'] ?? null) === 'file_upload') {
                $fileNames = is_array($value) ? count($value) : 0;
                if ($id === '' || $fileNames === 0) {
                    throw ValidationException::withMessages(["answers.$id" => ['A file is required for this question.']]);
                }
                $requiredFileCount++;
                continue;
            }

            if ($id === '' || $isEmpty) {
                throw ValidationException::withMessages(["answers.$id" => ['An answer is required for this question.']]);
            }
        }

        if ($uploadedFileCount < $requiredFileCount) {
            throw ValidationException::withMessages(['document' => ['Upload a file for each required file question.']]);
        }
    }

    /**
     * Approve completion
     * PATCH /api/v1/completions/{completion}/approve
     */
    public function approve_completion(Request $request, MilestoneCompletionSubmission $completion)
    {
        $userId = auth()->id();

        // Authorization: only program owner can approve
        $milestone = $completion->milestone;
        if ($milestone->application->program_owner_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check if already decided
        if ($completion->decision !== 'pending') {
            return response()->json([
                'error' => 'This completion has already been ' . $completion->decision
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'decision_notes' => 'nullable|string',
            ]);

            // Update completion
            $completion->decision = 'approved';
            $completion->decided_by = $userId;
            $completion->decided_at = now();
            $completion->decision_notes = $validated['decision_notes'] ?? null;
            $completion->save();

            // Update milestone
            $milestone->status = 'completion_approved';
            $milestone->completion_approved_by = $userId;
            $milestone->completion_approved_at = now();
            $milestone->save();

            // The next milestone may start only after this milestone's completion
            // is approved. It still needs MPRV approval if MPRV is required.
            $nextMilestone = $this->unlockNextMilestone($milestone, $userId);

            DB::commit();

            \App\Service\Program\ApplicationCompletion::refresh($milestone->application);

            $this->programNotification->send('completion.approved', [$completion->milestone->application->user], [
                'milestone_number' => $completion->milestone->sequence_order, 'next_milestone' => $nextMilestone ? true : false,
                'application_id' => $completion->milestone->app_id,
            ]);

            // The next milestone can begin its required MPRV step, if any.
            if ($nextMilestone) {
                $this->programNotification->send('milestone.unlocked', [$completion->milestone->application->user], [
                    'milestone_number' => $nextMilestone->sequence_order, 'application_id' => $completion->milestone->app_id,
                ]);
            }

            return response()->json([
                'message' => 'Completion approved. Milestone complete! ' .
                    (!$nextMilestone
                        ? 'All milestones complete!'
                        : ($nextMilestone->fund_release_status === 'approved'
                            ? 'Next milestone unlocked.'
                            : 'Next milestone unlocked for MPRV; funds remain locked until MPRV is approved.')),
                'data' => $completion,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Reject completion
     * PATCH /api/v1/completions/{completion}/reject
     */
    public function reject_completion(Request $request, MilestoneCompletionSubmission $completion)
    {
        $userId = auth()->id();

        // Authorization: only program owner can reject
        $milestone = $completion->milestone;
        if ($milestone->application->program_owner_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check if already decided
        if ($completion->decision !== 'pending') {
            return response()->json([
                'error' => 'This completion has already been ' . $completion->decision
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'decision_notes' => 'required|string',
            ]);

            // Update completion
            $completion->decision = 'rejected';
            $completion->decided_by = $userId;
            $completion->decided_at = now();
            $completion->decision_notes = $validated['decision_notes'];
            $completion->save();

            // Update milestone
            $milestone->status = 'completion_rejected';
            $milestone->save();

            DB::commit();

            $this->programNotification->send('completion.rejected', [$completion->milestone->application->user], [
                'milestone_number' => $completion->milestone->sequence_order, 'reason' => $validated['decision_notes'],
                'application_id' => $completion->milestone->app_id,
            ]);

            return response()->json([
                'message' => 'Completion rejected. Business owner can fix issues and resubmit.',
                'data' => $completion,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

}
