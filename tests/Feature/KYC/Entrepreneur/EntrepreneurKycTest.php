<?php

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function entrepreneurPayload(): array
{
    return ['legal_name' => 'Jane Doe', 'id_type' => 'passport', 'id_number' => 'P1234567', 'id_issuing_country' => 'KE', 'id_expiry_date' => now()->addYear()->toDateString(), 'nationality' => 'KE', 'physical_address' => '1 Main Street', 'county_region' => 'Nairobi', 'tax_pin' => 'A123456789Z', 'is_registered_business' => false, 'legal_structure' => 'sole_proprietor', 'people' => [['full_legal_name' => 'Jane Doe', 'relationship_role' => 'owner', 'is_beneficial_owner' => true, 'requires_identity_verification' => false]]];
}

it('starts one entrepreneur draft, reuses onboarding values, and permits draft updates', function () {
    $user = User::factory()->create(['user_type_id' => 1, 'first_name' => 'Jane', 'last_name' => 'Doe', 'country' => 'KE']);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc')->assertCreated()->assertJsonPath('data.verification_type', 'entrepreneur')->assertJsonPath('data.details.legal_name', 'Jane Doe');
    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/kyc', entrepreneurPayload())->assertOk()->assertJsonPath('data.details.tax_pin', 'A123456789Z');
    expect(KycVerification::count())->toBe(1);
});

it('validates conditional entrepreneur business information and documents on submission', function () {
    Storage::fake('kyc');
    $user = User::factory()->create(['user_type_id' => 1]);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc');
    $payload = entrepreneurPayload();
    $payload['is_registered_business'] = true;
    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/kyc', $payload)->assertOk();
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc/submit')->assertUnprocessable()->assertJsonValidationErrors(['business_legal_name', 'documents']);
});

it('uploads private documents, replaces duplicates, and blocks cross-user deletion', function () {
    Storage::fake('kyc');
    $owner = User::factory()->create(['user_type_id' => 1]);
    $other = User::factory()->create(['user_type_id' => 1]);
    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/kyc');
    $response = $this->actingAs($owner, 'sanctum')->post('/api/v1/kyc/documents', ['document_type' => 'id_passport_copy', 'file' => UploadedFile::fake()->create('passport.pdf', 20, 'application/pdf')])->assertCreated();
    $id = $response->json('data.id');
    $this->actingAs($other, 'sanctum')->postJson('/api/v1/kyc')->assertCreated();
    $this->actingAs($other, 'sanctum')->deleteJson('/api/v1/kyc/documents/'.$id)->assertNotFound();
    $this->actingAs($owner, 'sanctum')->post('/api/v1/kyc/documents', ['document_type' => 'id_passport_copy', 'file' => UploadedFile::fake()->create('replacement.pdf', 20, 'application/pdf')])->assertCreated();
    expect(KycVerification::where('user_id', $owner->id)->first()->documents()->count())->toBe(1);
});
