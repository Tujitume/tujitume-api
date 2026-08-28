<?php

namespace App\Http\Requests\Kyc;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadKycDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['document_type' => ['required', Rule::in(['id_passport_copy', 'proof_of_address', 'tax_pin_document', 'business_registration_certificate', 'professional_licence', 'portfolio_work_sample', 'reference', 'registration_certificate', 'tax_compliance_certificate', 'directors_trustees_document', 'authorization_letter_resolution', 'person_identity'])], 'person_id' => ['nullable', 'integer'], 'file' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'mimes:pdf,jpg,jpeg,png', 'max:10240']];
    }
}
