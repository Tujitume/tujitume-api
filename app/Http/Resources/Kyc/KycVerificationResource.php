<?php

namespace App\Http\Resources\Kyc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KycVerificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = match ($this->verification_type) {
            'entrepreneur' => $this->entrepreneurDetails, 'service_provider' => $this->serviceProviderDetails, 'organization' => $this->organizationDetails
        };

        return ['id' => $this->id, 'verification_type' => $this->verification_type, 'status' => $this->status, 'submitted_at' => $this->submitted_at?->toISOString(), 'rejection_reason' => $this->status === 'rejected' ? $this->rejection_reason : null, 'details' => $detail, 'people' => $this->people->map(fn ($p) => ['id' => $p->id, 'full_legal_name' => $p->full_legal_name, 'relationship_role' => $p->relationship_role, 'ownership_percentage' => $p->ownership_percentage, 'is_beneficial_owner' => $p->is_beneficial_owner, 'nationality' => $p->nationality, 'id_type' => $p->id_type, 'id_number' => $p->id_number, 'requires_identity_verification' => $p->requires_identity_verification]), 'documents' => $this->documents->map(fn ($d) => ['id' => $d->id, 'person_id' => $d->kyc_person_id, 'document_type' => $d->document_type, 'original_filename' => $d->original_filename, 'mime_type' => $d->mime_type, 'file_size' => $d->file_size, 'uploaded_at' => $d->uploaded_at?->toISOString()]), 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
