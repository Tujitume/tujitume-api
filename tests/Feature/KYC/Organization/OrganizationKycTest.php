<?php

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\Organizations\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function organizationPayload(): array
{
    return ['legal_name' => 'Community Foundation', 'registration_number' => 'NGO-123', 'registration_country' => 'KE', 'legal_structure' => 'foundation', 'tax_pin' => 'A123456789Z', 'physical_address' => 'Nairobi', 'county_region' => 'Nairobi', 'authorized_representative' => ['full_legal_name' => 'Org Owner', 'role_title' => 'Director', 'id_type' => 'national_id', 'id_number' => '12345678', 'phone' => '+254700000000', 'email' => 'owner@example.test', 'authorization_confirmation' => true], 'people' => [['full_legal_name' => 'Trustee One', 'relationship_role' => 'trustee', 'is_beneficial_owner' => false, 'requires_identity_verification' => false]]];
}

it('requires an organization relationship before an organization KYC can start', function () {
    $user = User::factory()->create(['user_type_id' => 4]);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc')->assertUnprocessable()->assertJsonValidationErrors(['organization']);
});

it('uses organization onboarding data and requires KYB records and authorization documents', function () {
    Storage::fake('kyc');
    $owner = User::factory()->create(['user_type_id' => 4, 'first_name' => 'Org', 'last_name' => 'Owner']);
    $organization = Organization::factory()->create(['owner_user_id' => $owner->id, 'legal_name' => 'Community Foundation', 'organization_type' => 'foundation']);
    $owner->update(['organization_id' => $organization->id]);
    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/kyc')->assertCreated()->assertJsonPath('data.details.legal_name', 'Community Foundation');
    $this->patchJson('/api/v1/kyc', organizationPayload())->assertOk();
    $this->postJson('/api/v1/kyc/submit')->assertUnprocessable()->assertJsonValidationErrors(['documents']);
    foreach (['registration_certificate', 'tax_compliance_certificate', 'proof_of_address', 'directors_trustees_document', 'authorization_letter_resolution'] as $type) {
        $this->post('/api/v1/kyc/documents', ['document_type' => $type, 'file' => UploadedFile::fake()->create($type.'.pdf', 20, 'application/pdf')])->assertCreated();
    }
    $this->postJson('/api/v1/kyc/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
    expect(KycVerification::first()->organization_id)->toBe($organization->id);
});

it('rejects updates once a KYC record is submitted', function () {
    $owner = User::factory()->create(['user_type_id' => 4]);
    $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
    $owner->update(['organization_id' => $organization->id]);
    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/kyc');
    KycVerification::first()->update(['status' => 'under_review']);
    $this->patchJson('/api/v1/kyc', organizationPayload())->assertConflict();
});
