<?php

use App\Models\Auth\User;
use App\Models\Users\ServiceProviderProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function serviceProviderPayload(bool $entity = false, bool $licensed = false): array
{
    return ['legal_name' => 'Provider Person', 'id_type' => 'national_id', 'id_number' => '12345678', 'phone' => '+254700000000', 'email' => 'provider@example.test', 'physical_address' => 'Nairobi', 'tax_pin' => 'A123456789Z', 'operates_through_business' => $entity, 'requires_professional_licence' => $licensed] + ($entity ? ['business_legal_name' => 'Provider Ltd', 'business_type' => 'limited_company', 'business_registration_number' => 'C-1'] : []);
}

function uploadKycDocument($test, string $type): void
{
    $test->post('/api/v1/kyc/documents', ['document_type' => $type, 'file' => UploadedFile::fake()->create($type.'.pdf', 20, 'application/pdf')])->assertCreated();
}

it('supports a service provider and requires a licence only when declared necessary', function () {
    Storage::fake('kyc');
    $user = User::factory()->create(['user_type_id' => 3]);
    ServiceProviderProfile::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc')->assertCreated();
    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/kyc', serviceProviderPayload(false, true))->assertOk();
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc/submit')->assertUnprocessable()->assertJsonValidationErrors(['documents']);
    uploadKycDocument($this, 'id_passport_copy');
    uploadKycDocument($this, 'proof_of_address');
    $this->postJson('/api/v1/kyc/submit')->assertUnprocessable()->assertJsonValidationErrors(['documents']);
    uploadKycDocument($this, 'professional_licence');
    $this->postJson('/api/v1/kyc/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
});

it('requires entity registration information only for service providers operating through an entity', function () {
    $user = User::factory()->create(['user_type_id' => 3]);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc');
    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/kyc', ['operates_through_business' => true])->assertOk();
    $this->postJson('/api/v1/kyc/submit')->assertUnprocessable()->assertJsonValidationErrors(['business_legal_name', 'business_type', 'business_registration_number']);
});

it('does not require a service provider profile and rejects entrepreneur-only fields', function () {
    $user = User::factory()->create(['user_type_id' => 3]);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc')->assertCreated();
    $this->patchJson('/api/v1/kyc', ['id_expiry_date' => now()->addYear()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors(['id_expiry_date']);
});
