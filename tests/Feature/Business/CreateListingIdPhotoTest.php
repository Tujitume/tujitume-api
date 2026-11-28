<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * POST business/create-listing: the Personal ID may be a photo (jpg/png), as the form offers and up_listing already
 * allows; it used to accept only pdf/docx, so a business could not be created with a photographed ID.
 */
class CreateListingIdPhotoTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create(['user_type_id' => 1, 'lipr_wallet' => 'W-TEST-1']);
        KycVerification::create(['user_id' => $owner->id, 'verification_type' => 'entrepreneur', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);

        return $owner;
    }

    public function test_a_photo_of_the_id_passes_validation(): void
    {
        $response = $this->actingAs($this->owner(), 'sanctum')->post('/api/v1/business/create-listing', [
            'identification' => UploadedFile::fake()->image('id.png'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertArrayNotHasKey('identification', $response->json('errors') ?? []);
        $this->assertArrayHasKey('title', $response->json('errors'));
    }

    public function test_another_file_type_is_still_refused(): void
    {
        $response = $this->actingAs($this->owner(), 'sanctum')->post('/api/v1/business/create-listing', [
            'identification' => UploadedFile::fake()->create('id.txt', 4, 'text/plain'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertArrayHasKey('identification', $response->json('errors'));
    }
}
