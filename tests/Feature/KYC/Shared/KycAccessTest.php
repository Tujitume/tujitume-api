<?php

use App\Models\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('requires Sanctum authentication for every KYC endpoint', function () {
    $this->getJson('/api/v1/kyc')->assertUnauthorized();
    $this->getJson('/api/v1/kyc/status')->assertUnauthorized();
    $this->postJson('/api/v1/kyc')->assertUnauthorized();
    $this->patchJson('/api/v1/kyc', [])->assertUnauthorized();
    $this->postJson('/api/v1/kyc/submit')->assertUnauthorized();
});

it('rejects user types that are not eligible for KYC', function () {
    $this->actingAs(User::factory()->create(['user_type_id' => 2]), 'sanctum')
        ->postJson('/api/v1/kyc')->assertForbidden();
});

it('returns current status and does not expose a KYC record before it is started', function () {
    $user = User::factory()->create(['user_type_id' => 1]);
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/kyc')->assertNotFound();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/kyc/status')->assertOk()->assertJsonPath('data.started', false);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc')->assertCreated();
    $this->getJson('/api/v1/kyc')->assertOk()->assertJsonPath('data.verification_type', 'entrepreneur');
});

it('rejects unsafe, oversized, and malformed person document uploads', function () {
    Storage::fake('kyc');
    $user = User::factory()->create(['user_type_id' => 1]);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc');
    $this->withHeader('Accept', 'application/json')->post('/api/v1/kyc/documents', ['document_type' => 'id_passport_copy', 'file' => UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream')])->assertUnprocessable();
    $this->withHeader('Accept', 'application/json')->post('/api/v1/kyc/documents', ['document_type' => 'proof_of_address', 'file' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')])->assertUnprocessable();
    $this->withHeader('Accept', 'application/json')->post('/api/v1/kyc/documents', ['document_type' => 'person_identity', 'file' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf')])->assertUnprocessable();
});
