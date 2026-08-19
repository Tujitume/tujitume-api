<?php
namespace App\Http\Controllers\Program\Rounds;

use App\Http\Controllers\Controller;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundRequiredDocument;
use App\Service\File\FileUploadService;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;


class RoundDocumentController extends Controller
{
    protected $fileUpload;

    public function __construct(FileUploadService $fileUpload)
    {
        $this->fileUpload = $fileUpload;
        parent::__construct();
    }

    /**
     * Upload required document for a round
     * POST /program/applications/{application_id}/rounds/{round_id}/documents
     */
    public function store(Request $request, $applicationId, $roundId)
    {
        $userId = auth()->id();
        $uploadedFile = null;

        DB::beginTransaction();
        try {
            $application = ProgramApplication::findOrFail($applicationId);
            $round = ProgramRound::findOrFail($roundId);

            // Authorization: Only application owner
            if ($application->user_id !== $userId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Verify application is in this round
            if ($application->current_round_id !== $round->id) {
                return response()->json(['error' => 'Application is not in this round'], 400);
            }

            $validated = $request->validate([
                'document_type' => 'required|string|max:100',
                'file' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240', // 10MB
            ]);

            // Check if document type is in round's required_documents
            $requiredDocs = $round->required_documents ?? [];
            if (!in_array($validated['document_type'], $requiredDocs)) {
                throw ValidationException::withMessages([
                    'document_type' => [ $validated['document_type'] . ': This document type is not required for this round']
                ]);
            }

            // Check if document already uploaded
            $existing = RoundRequiredDocument::where('application_id', $applicationId)
                ->where('round_id', $roundId)
                ->where('document_type', $validated['document_type'])
                ->first();

            if ($existing) {
                // Delete old file
                if (file_exists($existing->file_path)) {
                    unlink($existing->file_path);
                }
                $existing->delete();
            }

            // Upload file
            $filePath = null;
            if($request->hasFile('file')) {
                $file = $request->file('file');
                $path = 'files/roundDocuments/' . $applicationId . '/' . $roundId;
                $filePath = $this->fileUpload->saveFile($file, $path);
                $uploadedFile = $filePath;
            }


            // Create document record
            $document = RoundRequiredDocument::create([
                'application_id' => $applicationId,
                'round_id' => $roundId,
                'document_type' => $validated['document_type'],
                'file_path' => $filePath ?? null,
                'original_filename' => $file->getClientOriginalName() ?? null,
                'file_size' => $file->getSize() ?? null,
                'mime_type' => $file->getMimeType() ?? null,
                'verification_status' => 'pending',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Document uploaded successfully',
                'data' => [
                    'id' => $document->id,
                    'document_type' => $document->document_type,
                    'original_filename' => $document->original_filename,
                    'file_size' => $document->file_size,
                    'verification_status' => $document->verification_status,
                    'uploaded_at' => $document->created_at,
                ],
            ], 201);

        } catch (ValidationException $e) {
            if ($uploadedFile && file_exists($uploadedFile)) {
                unlink($uploadedFile);
            }
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            if ($uploadedFile && file_exists($uploadedFile)) {
                unlink($uploadedFile);
            }
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * List all documents for an application in a round
     * GET /program/applications/{application_id}/rounds/{round_id}/documents
     */
    public function index($applicationId, $roundId)
    {
        $userId = auth()->id();

        try {
            $application = ProgramApplication::findOrFail($applicationId);
            $round = ProgramRound::findOrFail($roundId);

            // Authorization: Application owner or program owner
            if ($application->user_id !== $userId && $application->program->user_id !== $userId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $documents = RoundRequiredDocument::where('application_id', $applicationId)
                ->where('round_id', $roundId)
                ->orderBy('document_type')
                ->get()
                ->map(function ($doc) {
                    return [
                        'id' => $doc->id,
                        'document_type' => $doc->document_type,
                        'original_filename' => $doc->original_filename,
                        'file_size' => $doc->file_size,
                        'verification_status' => $doc->verification_status,
                        'verification_notes' => $doc->verification_notes,
                        'uploaded_at' => $doc->created_at,
                        'verified_at' => $doc->verified_at,
                    ];
                });

            // Get round's required documents - handle JSON field properly
            $requiredDocs = $round->required_documents;
            $requiredDocTypes = collect($requiredDocs)
                ->map(fn($doc) => is_array($doc) ? ($doc['type'] ?? null) : $doc)
                ->filter()
                ->toArray();

            $uploadedTypes = $documents->pluck('document_type')->toArray();
            $missingDocs = array_diff($requiredDocTypes, $uploadedTypes);

            return response()->json([
                'data' => $documents,
                'required_documents' => $requiredDocs,
                'missing_documents' => array_values($missingDocs),
                'all_uploaded' => empty($missingDocs),
            ], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Verify a document (Program owner/reviewer only)
     * PATCH /program/documents/{document_id}/verify
     */
    public function verify(Request $request, $documentId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $document = RoundRequiredDocument::findOrFail($documentId);

            // Authorization: Only program owner
            if ($document->application->program->user_id !== $userId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $validated = $request->validate([
                'verification_notes' => 'nullable|string|max:500',
            ]);

            $document->markAsVerified($userId, $validated['verification_notes'] ?? null);

            DB::commit();

            return response()->json([
                'message' => 'Document verified successfully',
                'data' => [
                    'id' => $document->id,
                    'verification_status' => $document->verification_status,
                    'verified_at' => $document->verified_at,
                ],
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Reject a document (Program owner/reviewer only)
     * PATCH /program/documents/{document_id}/reject
     */
    public function reject(Request $request, $documentId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $document = RoundRequiredDocument::findOrFail($documentId);

            // Authorization: Only program owner
            if ($document->application->program->user_id !== $userId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $validated = $request->validate([
                'verification_notes' => 'required|string|max:500',
            ]);

            $document->markAsRejected($validated['verification_notes']);

            DB::commit();

            return response()->json([
                'message' => 'Document rejected',
                'data' => [
                    'id' => $document->id,
                    'verification_status' => $document->verification_status,
                    'verification_notes' => $document->verification_notes,
                ],
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Delete a document (Application owner only, before verification)
     * DELETE /program/documents/{document_id}
     */
    public function destroy($documentId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $document = RoundRequiredDocument::findOrFail($documentId);

            // Authorization: Only document owner
            if ($document->application->user_id !== $userId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Can't delete if already verified
            if ($document->verification_status === 'verified') {
                return response()->json(['error' => 'Cannot delete verified document'], 400);
            }

            // Delete file
            if (file_exists($document->file_path)) {
                unlink($document->file_path);
            }

            $document->delete();

            DB::commit();

            return response()->json([
                'message' => 'Document deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }
}
