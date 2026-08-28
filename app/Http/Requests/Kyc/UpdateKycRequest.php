<?php

namespace App\Http\Requests\Kyc;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateKycRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $structures = ['sole_proprietor', 'partnership', 'limited_company', 'ngo', 'foundation', 'cooperative', 'other'];

        return [
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'], 'id_type' => ['sometimes', 'nullable', Rule::in(['national_id', 'passport', 'drivers_license', 'other'])], 'id_number' => ['sometimes', 'nullable', 'string', 'max:100'], 'id_issuing_country' => ['sometimes', 'nullable', 'string', 'size:2'], 'id_expiry_date' => ['sometimes', 'nullable', 'date', 'after:today'], 'nationality' => ['sometimes', 'nullable', 'string', 'size:2'], 'physical_address' => ['sometimes', 'nullable', 'string', 'max:255'], 'county_region' => ['sometimes', 'nullable', 'string', 'max:255'], 'tax_pin' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'], 'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'is_registered_business' => ['sometimes', 'boolean'], 'operates_through_business' => ['sometimes', 'boolean'], 'business_legal_name' => ['sometimes', 'nullable', 'string', 'max:255'], 'business_registration_number' => ['sometimes', 'nullable', 'string', 'max:100'], 'registration_number' => ['sometimes', 'nullable', 'string', 'max:100'], 'registration_country' => ['sometimes', 'nullable', 'string', 'size:2'], 'legal_structure' => ['sometimes', 'nullable', Rule::in($structures)], 'business_type' => ['sometimes', 'nullable', Rule::in($structures)], 'requires_professional_licence' => ['sometimes', 'boolean'],
            'authorized_representative' => ['sometimes', 'array'], 'authorized_representative.full_legal_name' => ['required_with:authorized_representative', 'string', 'max:255'], 'authorized_representative.role_title' => ['required_with:authorized_representative', 'string', 'max:255'], 'authorized_representative.id_type' => ['required_with:authorized_representative', Rule::in(['national_id', 'passport', 'drivers_license', 'other'])], 'authorized_representative.id_number' => ['required_with:authorized_representative', 'string', 'max:100'], 'authorized_representative.phone' => ['required_with:authorized_representative', 'string', 'max:50'], 'authorized_representative.email' => ['required_with:authorized_representative', 'email', 'max:255'], 'authorized_representative.authorization_confirmation' => ['required_with:authorized_representative', 'boolean'],
            'people' => ['sometimes', 'array', 'max:50'], 'people.*.full_legal_name' => ['required_with:people', 'string', 'max:255'], 'people.*.relationship_role' => ['required_with:people', Rule::in(['owner', 'partner', 'director', 'trustee', 'shareholder', 'beneficial_owner'])], 'people.*.ownership_percentage' => ['nullable', 'numeric', 'between:0,100'], 'people.*.is_beneficial_owner' => ['boolean'], 'people.*.nationality' => ['nullable', 'string', 'size:2'], 'people.*.id_type' => ['nullable', Rule::in(['national_id', 'passport', 'drivers_license', 'other'])], 'people.*.id_number' => ['nullable', 'string', 'max:100'], 'people.*.requires_identity_verification' => ['boolean'],
        ];
    }
}
