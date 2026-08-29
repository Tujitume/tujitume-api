<?php

namespace App\Services\Kyc;

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KycService
{
    public function typeFor(User $user): string
    {
        return match ((int) $user->user_type_id) {
            1 => 'entrepreneur', 3 => 'service_provider', 4 => 'organization', default => abort(403, 'KYC is only available to business owners, service providers, and organization users.'),
        };
    }

    public function organizationFor(User $user): ?int
    {
        if ((int) $user->user_type_id !== 4) {
            return null;
        }
        $organization = $user->organization;
        if (! $organization) {
            throw ValidationException::withMessages(['organization' => ['An organization account must belong to an organization.']]);
        }
        if ($organization->owner_user_id === $user->id) {
            return $organization->id;
        }
        $membership = OrganizationUserRole::with('role')->where('organization_id', $organization->id)->where('user_id', $user->id)->where('status', 'active')->first();
        if (! $membership || ! in_array('kyc', $membership->role?->access_types ?? [])) {
            abort(403, 'You are not authorized to manage this organization’s KYC.');
        }

        return $organization->id;
    }

    public function current(User $user): ?KycVerification
    {
        $type = $this->typeFor($user);
        $organizationId = $this->organizationFor($user);

        return KycVerification::where('user_id', $user->id)->where('verification_type', $type)->where('organization_id', $organizationId)->with($this->relations())->first();
    }

    public function start(User $user): KycVerification
    {
        $type = $this->typeFor($user);
        $organizationId = $this->organizationFor($user);

        return DB::transaction(function () use ($user, $type, $organizationId) {
            $verification = KycVerification::firstOrCreate(['user_id' => $user->id, 'verification_type' => $type], ['organization_id' => $organizationId, 'status' => 'draft']);
            if ($verification->status === 'verified') {
                abort(409, 'A verified KYC record cannot be changed.');
            }
            $relation = match ($type) {
                'entrepreneur' => 'entrepreneurDetails', 'service_provider' => 'serviceProviderDetails', 'organization' => 'organizationDetails'
            };
            if (! $verification->$relation) {
                $verification->$relation()->create($this->prefill($user, $type));
            }

            return $verification->load($this->relations());
        });
    }

    public function update(KycVerification $verification, array $data): KycVerification
    {
        if (! in_array($verification->status, ['draft', 'rejected'])) {
            abort(409, 'Only draft or rejected KYC records can be updated.');
        }
        $allowed = match ($verification->verification_type) {
            'entrepreneur' => ['legal_name', 'id_type', 'id_number', 'id_issuing_country', 'id_expiry_date', 'nationality', 'physical_address', 'county_region', 'tax_pin', 'is_registered_business', 'business_legal_name', 'business_registration_number', 'registration_country', 'legal_structure', 'people'],
            'service_provider' => ['legal_name', 'id_type', 'id_number', 'phone', 'email', 'physical_address', 'tax_pin', 'operates_through_business', 'business_legal_name', 'business_type', 'business_registration_number', 'requires_professional_licence', 'people'],
            'organization' => ['legal_name', 'registration_number', 'registration_country', 'legal_structure', 'tax_pin', 'physical_address', 'county_region', 'authorized_representative', 'people'],
        };
        $invalid = array_diff(array_keys($data), $allowed);
        if ($invalid) {
            throw ValidationException::withMessages(array_fill_keys($invalid, 'This field is not applicable to this KYC flow.'));
        }

        return DB::transaction(function () use ($verification, $data) {
            $people = Arr::pull($data, 'people');
            $representative = Arr::pull($data, 'authorized_representative');
            $relation = match ($verification->verification_type) {
                'entrepreneur' => 'entrepreneurDetails', 'service_provider' => 'serviceProviderDetails', 'organization' => 'organizationDetails'
            };
            $detail = $verification->$relation;
            if ($verification->verification_type === 'organization' && $representative !== null) {
                $data = array_merge($data, collect($representative)->mapWithKeys(fn ($value, $key) => [$key === 'authorization_confirmation' ? $key : 'representative_'.$key => $value])->all());
            }
            $detail->fill($data)->save();
            if ($people !== null) {
                $verification->people()->delete();
                foreach ($people as $person) {
                    $verification->people()->create($person);
                }
            }
            if ($verification->status === 'rejected') {
                $verification->update(['status' => 'draft', 'rejection_reason' => null, 'reviewed_at' => null, 'reviewed_by_user_id' => null]);
            }

            return $verification->fresh($this->relations());
        });
    }

    public function submit(KycVerification $verification): KycVerification
    {
        if (! in_array($verification->status, ['draft', 'rejected'])) {
            abort(409, 'Only draft or rejected KYC records can be submitted.');
        }
        $this->validateComplete($verification->fresh($this->relations()));
        $verification->update(['status' => 'submitted', 'submitted_at' => now(), 'rejection_reason' => null]);

        return $verification->fresh($this->relations());
    }

    private function validateComplete(KycVerification $v): void
    {
        $d = match ($v->verification_type) {
            'entrepreneur' => $v->entrepreneurDetails, 'service_provider' => $v->serviceProviderDetails, 'organization' => $v->organizationDetails
        };
        $required = match ($v->verification_type) {
            'entrepreneur' => [
                'legal_name', 
                'id_type', 
                'id_number',
                'id_issuing_country', 
                'id_expiry_date', 
                'nationality',
                'physical_address', 
                'county_region',
                'tax_pin'
            ],

            'service_provider' => [
                'legal_name', 
                'id_type', 
                'id_number', 
                'phone', 
                'email', 
                'physical_address', 
                'tax_pin'
            ],

            'organization' => [
                'legal_name',
                'registration_number',
                'registration_country',
                'legal_structure', 
                'tax_pin', 
                'physical_address',
                'county_region', 
                'representative_full_legal_name',
                'representative_role_title', 
                'representative_id_type',
                'representative_id_number',
                'representative_phone', 
                'representative_email'
            ],
        };
        $errors = [];
        foreach ($required as $field) {
            if (blank($d?->$field)) {
                $errors[$field][] = 'This field is required before submission.';
            }
        }
        if ($v->verification_type === 'entrepreneur' && $d->is_registered_business) {
            foreach (['business_legal_name', 'business_registration_number', 'registration_country', 'legal_structure'] as $field) {
                if (blank($d->$field)) {
                    $errors[$field][] = 'This field is required for a registered business.';
                }
            }
        }
        if ($v->verification_type === 'service_provider' && $d->operates_through_business) {
            foreach (['business_legal_name', 'business_type', 'business_registration_number'] as $field) {
                if (blank($d->$field)) {
                    $errors[$field][] = 'This field is required when operating through a business.';
                }
            }
        }
        if ($v->verification_type === 'organization' && ! $d->authorization_confirmation) {
            $errors['authorized_representative.authorization_confirmation'][] = 'Authorization confirmation is required.';
        }
        $documents = $v->documents->pluck('document_type')->all();
        $documentsRequired = match ($v->verification_type) {
            'entrepreneur' => ['id_passport_copy', 'proof_of_address', 'tax_pin_document'], 'service_provider' => ['id_passport_copy', 'proof_of_address'], 'organization' => ['registration_certificate', 'tax_compliance_certificate', 'proof_of_address', 'directors_trustees_document', 'authorization_letter_resolution']
        };
        if ($v->verification_type === 'entrepreneur' && $d->is_registered_business) {
            $documentsRequired[] = 'business_registration_certificate';
        }
        if ($v->verification_type === 'service_provider' && $d->operates_through_business) {
            $documentsRequired[] = 'business_registration_certificate';
        }
        if ($v->verification_type === 'service_provider' && $d->requires_professional_licence) {
            $documentsRequired[] = 'professional_licence';
        }
        foreach ($documentsRequired as $doc) {
            if (! in_array($doc, $documents, true)) {
                $errors['documents'][] = "The {$doc} document is required before submission.";
            }
        }
        $structure = $d->legal_structure ?? $d->business_type ?? null;
        if ($structure) {
            $this->validatePeople($v, $structure, $errors);
        }
        foreach ($v->people->where('requires_identity_verification', true) as $person) {
            if (! $person->id_type || ! $person->id_number || ! $person->documents()->where('document_type', 'person_identity')->exists()) {
                $errors['people'][] = "Identity verification is incomplete for {$person->full_legal_name}.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validatePeople(KycVerification $v, string $structure, array &$errors): void
    {
        $roles = $v->people->pluck('relationship_role')->all();
        if ($structure === 'sole_proprietor' && ! in_array('owner', $roles)) {
            $errors['people'][] = 'A sole proprietor requires an owner.';
        }
        if ($structure === 'limited_company' && ! in_array('director', $roles)) {
            $errors['people'][] = 'A limited company requires at least one director.';
        }
        if (in_array($structure, ['ngo', 'foundation']) && ! in_array('trustee', $roles) && ! in_array('director', $roles)) {
            $errors['people'][] = 'An NGO or foundation requires a director or trustee.';
        }
        if (in_array($structure, ['limited_company', 'partnership'])) {
            foreach ($v->people as $person) {
                if (in_array($person->relationship_role, ['owner', 'partner', 'shareholder', 'beneficial_owner']) && $person->ownership_percentage === null) {
                    $errors['people'][] = 'Ownership percentage is required for owners, partners, shareholders, and beneficial owners.';
                }
            }
        }
    }

    private function prefill(User $user, string $type): array
    {
        if ($type === 'entrepreneur') {
            $listing = $user->listings()->first();

            return ['legal_name' => trim($user->first_name.' '.$user->last_name), 'nationality' => $user->country, 'county_region' => $user->city, 'tax_pin' => $listing?->tax_pin, 'business_legal_name' => $listing?->name, 'business_registration_number' => $listing?->identification];
        }
        if ($type === 'service_provider') {
            return ['legal_name' => trim($user->first_name.' '.$user->last_name), 'phone' => $user->phone, 'email' => $user->email];
        }
        $o = $user->organization;

        return ['legal_name' => $o->legal_name ?: $o->name, 'registration_country' => $o->country, 'county_region' => $o->region, 'physical_address' => $o->city, 'legal_structure' => $o->organization_type, 'representative_full_legal_name' => trim($user->first_name.' '.$user->last_name), 'representative_phone' => $user->phone, 'representative_email' => $user->email];
    }

    private function relations(): array
    {
        return ['entrepreneurDetails', 'serviceProviderDetails', 'organizationDetails', 'people.documents', 'documents'];
    }
}
