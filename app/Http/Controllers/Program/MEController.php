<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Http\Resources\Program\Monitoring\MECheckpointResource;
use App\Http\Resources\Program\Monitoring\MESubmissionResource;
use App\Http\Resources\ApiResponseResource;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\Monitoring\MESubmission;
use App\Models\Programs\Monitoring\MESubmissionFile;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MEController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    // ─── CHECKPOINTS ────────────────────────────────────────────────────

    // GET /program/applications/{app}/me/checkpoints
    public function indexCheckpoints(ProgramApplication $app)
    {
        $userId = auth()->id();
        $isProgramOwner = $app->program->user_id === $userId;
        $isApplicant  = $app->user_id === $userId;

        if (!$isProgramOwner && !$isApplicant) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $checkpoints = MECheckpoint::where('app_id', $app->id)
            ->with(['submission.files', 'siteVisit.checkpoint', 'siteVisit.files'])
            ->orderBy('display_order')
            ->get();

        $business = $app->business()->select('id','location', 'lat', 'lng')->first();
        $businessLocation = $business ? [
            'id' => $business->id,
            'location' => $business->location,
            'lat' => $business->lat,
            'lng' => $business->lng,
        ] : null;

        return new ApiResponseResource(
            'Checkpoints fetched successfully',
            [
                'business_location' => $businessLocation,
                'checkpoints' => MECheckpointResource::collection($checkpoints),
            ],
            200
        );
    }

    // POST /program/applications/{app}/me/checkpoints
    public function storeCheckpoint(Request $request, ProgramApplication $app)
    {
        if (!\App\Service\Program\ProgramAccess::owns($app->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'checkpoint_name'          => 'required|string|max:255',
                'type'                     => 'required|in:monitoring,reporting,meeting',
                'due_date'                 => 'nullable|date',
                'requirement'              => 'nullable|string',
                'checkpoint_description'   => 'nullable|string|max:500',
                'require_site_visit'       => 'boolean',
                'meeting_required'         => 'boolean',
                'meeting_id'               => 'required_if:meeting_required,true|exists:meetings,id',
                'kpis_to_track'            => 'nullable|array',
                'evidence_required'        => 'nullable|array',
                'submission_fields'        => 'nullable|array',
                //'custom_submission_fields' => 'nullable|array',
                'display_order'            => 'nullable|integer',
                'should_notify_applicant'  => 'boolean|required_with:require_site_visit',
            ]);

            $validated['app_id']   = $app->id;
            $validated['program_id'] = $app->program_id;

            $checkpoint = MECheckpoint::create($validated);

            DB::commit();

            return new ApiResponseResource('Checkpoint created successfully', new MECheckpointResource($checkpoint), 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // PATCH /program/me/checkpoints/{checkpoint}
    public function updateCheckpoint(Request $request, MECheckpoint $checkpoint)
    {
        if (!\App\Service\Program\ProgramAccess::owns($checkpoint->application->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'checkpoint_name'          => 'sometimes|string|max:255',
                'type'                     => 'sometimes|in:monitoring,reporting',
                'due_date'                 => 'nullable|date',
                'requirement'              => 'nullable|string',
                'require_site_visit'       => 'sometimes|boolean',
                'kpis_to_track'            => 'nullable|array',
                'evidence_required'        => 'nullable|array',
                'submission_fields'        => 'nullable|array',
                'custom_submission_fields' => 'nullable|array',
                'display_order'            => 'nullable|integer',
            ]);

            $checkpoint->update($validated);
            DB::commit();

            return new ApiResponseResource('Checkpoint updated successfully', new MECheckpointResource($checkpoint), 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // DELETE /program/me/checkpoints/{checkpoint}
    public function deleteCheckpoint(MECheckpoint $checkpoint)
    {
        if (!\App\Service\Program\ProgramAccess::owns($checkpoint->application->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $checkpointId = $checkpoint->id;
        $checkpoint->delete();

        return new ApiResponseResource('Checkpoint deleted successfully', ['checkpoint_id' => $checkpointId], 200);
    }

    // ─── SUBMISSIONS ─────────────────────────────────────────────────────

    // POST /program/me/checkpoints/{checkpoint}/submit
    public function submit(Request $request, MECheckpoint $checkpoint)
    {
        $userId = auth()->id();

        if ($checkpoint->application->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($checkpoint->status === 'verified') {
            return response()->json(['error' => 'Checkpoint already verified'], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'written_report'            => 'nullable|string',
                'kpi_actual_values'         => 'nullable|array',
                'beneficiary_list'          => 'nullable|array',
                'custom_field_values'       => 'nullable|array',
                'files'                     => 'nullable|array',
                'files.*.file'              => 'required_with:files|file|max:20480',
                'files.*.file_type'         => 'required_with:files|in:document,photo_video,beneficiary_list,other',
                'files.*.original_filename' => 'nullable|string',
            ]);

            // Create or update submission
            $submission = MESubmission::updateOrCreate(
                [
                    'checkpoint_id' => $checkpoint->id,
                    'app_id'        => $checkpoint->app_id,
                ],
                [
                    'submitted_by'        => $userId,
                    'written_report'      => $validated['written_report'] ?? null,
                    'kpi_actual_values'   => $validated['kpi_actual_values'] ?? null,
                    'beneficiary_list'    => $validated['beneficiary_list'] ?? null,
                    'custom_field_values' => $validated['custom_field_values'] ?? null,
                    'status'              => 'submitted',
                    'submitted_at'        => now(),
                ]
            );

            // Handle file uploads
            if ($request->has('files')) {
                $files = collect($request->input('files'))
                    ->map(function ($fileData, $index) use ($request, $submission, $checkpoint) {
                        $file = $request->file("files.{$index}.file");
                        if (!$file) return null;

                        $filePath = $this->fileUpload->saveFile($file, "files/program/me/{$checkpoint->id}");

                        return [
                            'submission_id'     => $submission->id,
                            'file_type'         => $fileData['file_type'],
                            'file_path'         => $filePath,
                            'original_filename' => $fileData['original_filename'] ?? $fileData['file_type'] ?? $file->getClientOriginalName(),
                            'file_size'         => $file->getSize(),
                            'mime_type'         => $file->getMimeType(),
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ];
                    })
                    ->filter()
                    ->values()
                    ->toArray();

                if (!empty($files)) {
                    MESubmissionFile::insert($files);
                }
            }

            // Update checkpoint status
            $checkpoint->update(['status' => 'submitted']);

            DB::commit();

            return new ApiResponseResource(
                'Submission created successfully',
                new MESubmissionResource($submission->fresh()->load('files')),
                201
            );
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // ─── REVIEW ──────────────────────────────────────────────────────────

    // POST /program/me/submissions/{submission}/verify
    public function verify(Request $request, MESubmission $submission)
    {
        if (!\App\Service\Program\ProgramAccess::owns($submission->checkpoint->application->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'reviewer_note' => 'nullable|string',
            ]);

            $submission->update([
                'status'        => 'verified',
                'reviewer_note' => $validated['reviewer_note'] ?? null,
                'reviewed_by'   => auth()->id(),
                'reviewed_at'   => now(),
            ]);

            $submission->checkpoint->update(['status' => 'verified']);

            DB::commit();

            return new ApiResponseResource('Submission verified successfully', new MESubmissionResource($submission->fresh()->load('files')), 200);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // POST /program/me/submissions/{submission}/request-changes
    public function requestChanges(Request $request, MESubmission $submission)
    {
        if (!\App\Service\Program\ProgramAccess::owns($submission->checkpoint->application->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'reviewer_note' => 'required|string',
            ]);

            $submission->update([
                'status'        => 'changes_requested',
                'reviewer_note' => $validated['reviewer_note'],
                'reviewed_by'   => auth()->id(),
                'reviewed_at'   => now(),
            ]);

            $submission->checkpoint->update(['status' => 'changes_requested']);

            DB::commit();

            return new ApiResponseResource('Changes requested successfully', new MESubmissionResource($submission->fresh()->load('files')), 200);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // DELETE /program/monitoring/checkpoints/{checkpoint}/submissions
    public function deleteSubmissions(MECheckpoint $checkpoint)
    {
        if (!\App\Service\Program\ProgramAccess::owns($checkpoint->application->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            // Get submissions for this checkpoint
            $submissions = MESubmission::where('checkpoint_id', $checkpoint->id)->get();
            $submissionIds = $submissions->pluck('id')->toArray();

            if (!empty($submissionIds)) {
                // Delete associated files from storage and DB
                $files = MESubmissionFile::whereIn('submission_id', $submissionIds)->get();
                foreach ($files as $file) {
                    if ($file->file_path) {
                        try {
                            Storage::disk('s3')->delete($file->file_path);
                        } catch (\Exception $e) {
                            // continue even if delete fails for a path; we'll still remove DB records
                        }
                    }
                }

                MESubmissionFile::whereIn('submission_id', $submissionIds)->delete();
                MESubmission::whereIn('id', $submissionIds)->delete();
            }

            // Reset checkpoint status to pending
            $checkpoint->update(['status' => 'pending']);

            DB::commit();

            return new ApiResponseResource('Submissions deleted successfully', ['checkpoint_id' => $checkpoint->id], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


}
