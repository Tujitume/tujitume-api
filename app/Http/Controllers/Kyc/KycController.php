<?php

namespace App\Http\Controllers\Kyc;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kyc\UpdateKycRequest;
use App\Http\Requests\Kyc\UploadKycDocumentRequest;
use App\Http\Resources\ApiResponseResource;
use App\Http\Resources\Kyc\KycVerificationResource;
use App\Models\Kyc\KycDocument;
use App\Services\Kyc\KycService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KycController extends Controller
{
    public function __construct(private KycService $kyc) {}

    public function show(Request $request)
    {
        $verification = $this->kyc->current($request->user());
        if (! $verification) {
            return ApiResponseResource::error('KYC has not been started.', null, 404);
        }

        return new KycVerificationResource($verification);
    }

    public function status(Request $request)
    {
        $verification = $this->kyc->current($request->user());

        return ApiResponseResource::success('KYC status retrieved.', ['started' => (bool) $verification, 'status' => $verification?->status, 'verification_type' => $verification?->verification_type]);
    }

    public function start(Request $request)
    {
        $verification = $this->kyc->start($request->user());

        return ApiResponseResource::success('KYC draft prepared.', (new KycVerificationResource($verification))->resolve(), 201);
    }

    public function update(UpdateKycRequest $request)
    {
        $verification = $this->requireCurrent($request);
        $verification = $this->kyc->update($verification, $request->validated());

        return ApiResponseResource::success('KYC draft updated.', (new KycVerificationResource($verification))->resolve());
    }

    public function submit(Request $request)
    {
        $verification = $this->requireCurrent($request);
        $verification = $this->kyc->submit($verification);

        return ApiResponseResource::success('KYC submitted for review.', (new KycVerificationResource($verification))->resolve());
    }

    public function upload(UploadKycDocumentRequest $request)
    {
        $verification = $this->requireCurrent($request);
        if (! in_array($verification->status, ['draft', 'rejected'])) {
            abort(409, 'Documents can only be changed while KYC is a draft or rejected.');
        } $personId = $request->filled('person_id') ? $request->integer('person_id') : null;
        if ($personId && ! $verification->people()->whereKey($personId)->exists()) {
            abort(404, 'KYC person not found.');
        } if ($request->input('document_type') === 'person_identity' && ! $personId) {
            abort(422, 'A person_id is required for a person identity document.');
        } if ($request->input('document_type') !== 'person_identity' && $personId) {
            abort(422, 'person_id is only valid for person identity documents.');
        } $file = $request->file('file');
        $extension = $file->extension() ?: 'bin';
        $path = $verification->id.'/'.Str::uuid().'.'.$extension;
        Storage::disk('kyc')->putFileAs($verification->id, $file, basename($path));
        try {
            $existing = KycDocument::where('kyc_verification_id', $verification->id)->where('document_type', $request->document_type)->where('kyc_person_id', $personId)->first();
            $document = KycDocument::updateOrCreate(['kyc_verification_id' => $verification->id, 'document_type' => $request->document_type, 'kyc_person_id' => $personId], ['disk' => 'kyc', 'path' => $path, 'original_filename' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize(), 'uploaded_at' => now()]);
            if ($existing) {
                Storage::disk('kyc')->delete($existing->path);
            }

            return ApiResponseResource::success('KYC document uploaded.', ['id' => $document->id, 'document_type' => $document->document_type, 'original_filename' => $document->original_filename], 201);
        } catch (\Throwable $e) {
            Storage::disk('kyc')->delete($path);
            throw $e;
        }
    }

    public function destroyDocument(Request $request, KycDocument $document)
    {
        $verification = $this->requireCurrent($request);
        abort_unless($document->kyc_verification_id === $verification->id, 404);
        if (! in_array($verification->status, ['draft', 'rejected'])) {
            abort(409, 'Documents can only be deleted while KYC is a draft or rejected.');
        } Storage::disk($document->disk)->delete($document->path);
        $document->delete();

        return ApiResponseResource::success('KYC document deleted.');
    }

    private function requireCurrent(Request $request)
    {
        $verification = $this->kyc->current($request->user());
        abort_unless($verification, 404, 'KYC has not been started.');

        return $verification;
    }
}
