<?php

namespace App\Http\Controllers\Grant;

use App\Http\Controllers\Controller;
use App\Models\Grants\DealRoomDocument;
use App\Models\Grants\GrantApplication;
use App\Models\Grants\GrantMilestone;
use App\Service\Grant\RoundHelperService;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrantDealRoomController extends Controller
{

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, GrantMilestone $milestone)
    {
        $userId = auth()->id();

        // Authorization: grant owner, business owner, or supplier can upload
        $grantOwnerId = $milestone->application->grant_owner_id;
        $businessOwnerId = $milestone->application->user_id;

        if (!in_array($userId, [$grantOwnerId, $businessOwnerId])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                // Required fields
                'document_type' => 'required|in:approved_budget,supplier_invoice,supplier_quotation,payment_receipt,delivery_confirmation,completion_photo,completion_video,delivery_note,completion_report,communication,other',
                'file' => 'required|file|max:2048',  // 2MB max

                // Optional fields
                'description' => 'nullable|string',
                'visible_to_grant_owner' => 'nullable|boolean',
                'visible_to_business_owner' => 'nullable|boolean',
                'visible_to_supplier' => 'nullable|boolean',
            ]);

            // Handle file upload
            $file = $request->file('file');
            $path = 'files/grantDealRoom/' . $milestone->id;
            $filePath = $this->fileUpload->saveFile($file, $path);

            // Set document data
            $validated['milestone_id'] = $milestone->id;
            $validated['uploaded_by'] = $userId;
            $validated['file_name'] = $file->getClientOriginalName();
            $validated['file_path'] = $filePath;
            $validated['mime_type'] = $file->getMimeType();
            $validated['file_size_bytes'] = $file->getSize();

            // Set visibility defaults (all parties can see by default)
            $validated['visible_to_grant_owner'] = $validated['visible_to_grant_owner'] ?? true;
            $validated['visible_to_business_owner'] = $validated['visible_to_business_owner'] ?? true;
            $validated['visible_to_supplier'] = $validated['visible_to_supplier'] ?? false;

            $document = DealRoomDocument::create($validated);

            DB::commit();

            $recipients = []; $user = auth()->user();
            if ($userId !== $milestone->application->grant->owner_id) {
                $recipients[] = $milestone->application->grant->owner;
            }
            if ($userId !== $milestone->application->user_id) {
                $recipients[] = $milestone->application->user;
            }

            if (count($recipients) > 0) {
                $this->grantNotification->send('dealroom.document_uploaded', $recipients, [
                    'uploader_name' => $user->fname . ' ' . $user->lname, 'document_type' => $validated['document_type'],
                    'milestone_number' => $milestone->sequence_order, 'application_id' => $milestone->app_id,
                ]);
            }

            return response()->json([
                'message' => 'Document uploaded successfully to Deal Room',
                'data' => $document,
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token'])
            ]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /**
     * List all documents in Deal Room for milestone
     * GET /api/v1/milestones/{milestone}/deal-room
     */
    public function index(Request $request, GrantMilestone $milestone)
    {
        $userId = auth()->id();

        // Authorization check
        $grantOwnerId = $milestone->application->grant_owner_id;
        $businessOwnerId = $milestone->application->user_id;

        if (!in_array($userId, [$grantOwnerId, $businessOwnerId])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Filter by visibility based on user role
        $documents = DealRoomDocument::where('milestone_id', $milestone->id);

        if ($userId === $grantOwnerId) {
            $documents->where('visible_to_grant_owner', true);
        } elseif ($userId === $businessOwnerId) {
            $documents->where('visible_to_business_owner', true);
        }

        $documents = $documents->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $documents,
        ]);
    }

    /**
     * Download/view specific document
     * GET /api/v1/deal-room/{document}
     */
    public function show(DealRoomDocument $document)
    {
        $userId = auth()->id();

        // Authorization: check if user has visibility access
        $grantOwnerId = $document->milestone->application->grant_owner_id;
        $businessOwnerId = $document->milestone->application->user_id;

        $hasAccess = false;
        if ($userId === $grantOwnerId && $document->visible_to_grant_owner) {
            $hasAccess = true;
        } elseif ($userId === $businessOwnerId && $document->visible_to_business_owner) {
            $hasAccess = true;
        }

        if (!$hasAccess) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json([
            'data' => $document,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
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

    // dealroom application info
    public function show_application($id)
    {
        try {
            $pitch = GrantApplication::with([
                'grant_milestones',
                'grant_milestones.suppliers',
                'grant_milestones.budgetItems',
                'grant_milestones.verifications',
                'grant_milestones.dealRoomDocuments',
                'grant_milestones.completionSubmissions',
                'grant:id,grant_title,grant_type,status,total_rounds,funding_per_business,disbursement_type,total_grant_amount,available_amount',
            ])->findOrFail($id);


            return response()->json([
                'application' => [
                    ...$pitch->toArray(),
                    'status' => [
                        'value' => $pitch->status,
                        'color' => config('status.grant_application.' . $pitch->status, 'gray'),
                    ],
                    'funding_setup_status' => [
                        'value' => $pitch->funding_setup_status,
                        'color' => config('status.funding_setup.' . $pitch->funding_setup_status, 'gray'),
                    ],
                ],

            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Application not found'], 404);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


}
