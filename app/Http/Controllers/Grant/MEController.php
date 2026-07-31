<?php

namespace App\Http\Controllers\Grant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Grant\Monitoring\MECheckpointResource;
use App\Http\Resources\Grant\Monitoring\MESiteVisitResource;
use App\Http\Resources\Grant\Monitoring\MESubmissionResource;
use App\Http\Resources\ApiResponseResource;
use App\Models\Grants\GrantApplication;
use App\Models\Grants\Monitoring\MECheckpoint;
use App\Models\Grants\Monitoring\MESiteVisit;
use App\Models\Grants\Monitoring\MESiteVisitFile;
use App\Models\Grants\Monitoring\MESubmission;
use App\Models\Grants\Monitoring\MESubmissionFile;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MEController extends Controller
{
    // ─── CHECKPOINTS ────────────────────────────────────────────────────

    // GET /grant/applications/{app}/me/checkpoints
    public function indexCheckpoints(GrantApplication $app)
    {
        $userId = auth()->id();
        $isGrantOwner = $app->grant->user_id === $userId;
        $isApplicant  = $app->user_id === $userId;

        if (!$isGrantOwner && !$isApplicant) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $checkpoints = MECheckpoint::where('app_id', $app->id)
            ->with(['submission.files', 'siteVisit.files',])
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

    // POST /grant/applications/{app}/me/checkpoints
    public function storeCheckpoint(Request $request, GrantApplication $app)
    {
        if ($app->grant->user_id !== auth()->id()) {
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
            $validated['grant_id'] = $app->grant_id;

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

    // PATCH /grant/me/checkpoints/{checkpoint}
    public function updateCheckpoint(Request $request, MECheckpoint $checkpoint)
    {
        if ($checkpoint->application->grant->user_id !== auth()->id()) {
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

    // DELETE /grant/me/checkpoints/{checkpoint}
    public function deleteCheckpoint(MECheckpoint $checkpoint)
    {
        if ($checkpoint->application->grant->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $checkpointId = $checkpoint->id;
        $checkpoint->delete();

        return new ApiResponseResource('Checkpoint deleted successfully', ['checkpoint_id' => $checkpointId], 200);
    }

    // ─── SUBMISSIONS ─────────────────────────────────────────────────────

    // POST /grant/me/checkpoints/{checkpoint}/submit
    public function submit(Request $request, MECheckpoint $checkpoint)
    {
        $userId = auth()->id();

        if ($checkpoint->application->user_id !== $userId) {
            //return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($checkpoint->status === 'verified') {
            return response()->json(['error' => 'Checkpoint already verified'], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'written_report'      => 'nullable|string',
                'kpi_actual_values'   => 'nullable|array',
                'beneficiary_list'    => 'nullable|array',
                'custom_field_values' => 'nullable|array',
                'files'               => 'nullable|array',
                'files.*.file'        => 'required_with:files|file|max:20480',
                'files.*.file_type'   => 'required_with:files|in:document,photo_video,beneficiary_list,other',
            ]);

            // Create or update submission
            $submission = MESubmission::updateOrCreate(
                ['checkpoint_id' => $checkpoint->id, 'app_id' => $checkpoint->app_id],
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
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $index => $fileData) {
                    $file     = $fileData['file'];
                    $filePath = $this->fileUpload->saveFile($file, "files/grant/me/{$checkpoint->id}");

                    MESubmissionFile::create([
                        'submission_id'     => $submission->id,
                        'file_type'         => $fileData['file_type'],
                        'file_path'         => $filePath,
                        'original_filename' => $file->getClientOriginalName(),
                        'file_size'         => $file->getSize(),
                        'mime_type'         => $file->getMimeType(),
                    ]);
                }
            }

            // Update checkpoint status
            $checkpoint->update(['status' => 'submitted']);

            DB::commit();

            return new ApiResponseResource('Submission created successfully', new MESubmissionResource($submission->fresh()->load('files')), 201);

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

    // POST /grant/me/submissions/{submission}/verify
    public function verify(Request $request, MESubmission $submission)
    {
        if ($submission->checkpoint->application->grant->user_id !== auth()->id()) {
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

    // POST /grant/me/submissions/{submission}/request-changes
    public function requestChanges(Request $request, MESubmission $submission)
    {
        if ($submission->checkpoint->application->grant->user_id !== auth()->id()) {
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

    // ─── SITE VISIT ──────────────────────────────────────────────────────

    // POST /grant/me/checkpoints/{checkpoint}/site-visit/assign
    public function assignSiteVisit(Request $request, MECheckpoint $checkpoint)
    {
        if ($checkpoint->application->grant->user_id !== auth()->id()) {
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
                'inspector'   => 'nullable|string',
                'objective'   => 'nullable|string',
                'kpi_targets' => 'nullable|array',
            ]);

            $validated['checkpoint_id'] = $checkpoint->id;
            $validated['app_id']        = $checkpoint->app_id;

            $siteVisit = MESiteVisit::updateOrCreate(
                ['checkpoint_id' => $checkpoint->id],
                $validated
            );

            // commit the transaction
            DB::commit();

            return new ApiResponseResource('Site visit assigned successfully', new MESiteVisitResource($siteVisit->load('files')), 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // POST /grant/me/site-visits/{visit}/submit
    public function submitSiteVisit(Request $request, MESiteVisit $visit)
    {
        if ($visit->reviewer_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
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
                $filePath = $this->fileUpload->saveFile($file, "files/grant/me/site-visits/{$visit->id}");

                MESiteVisitFile::create([
                    'site_visit_id'     => $visit->id,
                    'file_path'         => $filePath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type'         => $file->getMimeType(),
                ]);
            }

            unset($validated['files']);

            $visit->update(array_merge($validated, ['status' => 'completed']));

            DB::commit();

            return new ApiResponseResource('Site visit report submitted successfully', new MESiteVisitResource($visit->load('files')), 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // GET /grant/me/site-visits/{visit}
    public function showSiteVisit(MESiteVisit $visit)
    {
        $userId      = auth()->id();
        $application = $visit->checkpoint->application;

        $isGrantOwner = $application->grant->user_id === $userId;
        $isReviewer   = $visit->reviewer_id === $userId;
        $isApplicant  = $application->user_id === $userId;

        if (!$isGrantOwner && !$isReviewer && !$isApplicant) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return new ApiResponseResource('Site visit details fetched successfully', new MESiteVisitResource($visit->load('files')), 200);
    }

    public function SiteVisits(Request $request, $reviewer_id)
    {
        $userId = auth()->id();

        if ($userId != $reviewer_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $siteVisits = MESiteVisit::where('reviewer_id', $reviewer_id)
            ->with(['checkpoint', 'checkpoint.application', 'files'])
            ->orderBy('start_date', 'desc')
            ->get();

        return new ApiResponseResource('Site visits fetched successfully', MESiteVisitResource::collection($siteVisits), 200);
    }
}
