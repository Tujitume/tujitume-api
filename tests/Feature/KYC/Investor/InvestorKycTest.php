<?php

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

// Individual investors (type 2) sign up with a short form and verify their identity later in Settings › Verification,
// with the same person check as an entrepreneur.

function invKycUser(): User
{
    return User::factory()->create(['user_type_id' => 2, 'first_name' => 'Ivy', 'last_name' => 'Investor', 'country' => 'KE', 'city' => 'Nairobi']);
}

function invKycPending($t, User $user): array
{
    Cache::flush();

    return collect($t->actingAs($user, 'sanctum')->getJson('/api/v1/pending-tasks?fresh=1')->assertOk()->json('data.groups'))
        ->flatMap(fn ($g) => $g['items'])->keyBy('type')->all();
}

it('starts an investor draft as a person check, filled in from the account', function () {
    $user = invKycUser();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/kyc/status')->assertOk()->assertJsonPath('data.started', false);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc')->assertCreated()
        ->assertJsonPath('data.verification_type', 'entrepreneur')
        ->assertJsonPath('data.details.legal_name', 'Ivy Investor');
    // Starting twice keeps one record
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc')->assertCreated();
    expect(KycVerification::where('user_id', $user->id)->count())->toBe(1);
});

it('lets an investor save details, upload the two documents and submit for review', function () {
    Storage::fake('kyc');
    $user = invKycUser();
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc')->assertCreated();
    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/kyc', [
        'legal_name' => 'Ivy Investor', 'id_type' => 'national_id', 'id_number' => '12345678',
        'id_issuing_country' => 'KE', 'id_expiry_date' => now()->addYears(5)->toDateString(), 'nationality' => 'KE', 'county_region' => 'Nairobi',
    ])->assertOk()->assertJsonPath('data.details.id_number', '12345678');

    // Documents are still missing
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc/submit')->assertUnprocessable()->assertJsonValidationErrors(['documents']);

    foreach (['id_passport_copy', 'tax_pin_document'] as $type) {
        $this->actingAs($user, 'sanctum')->withHeader('Accept', 'application/json')
            ->post('/api/v1/kyc/documents', ['document_type' => $type, 'file' => UploadedFile::fake()->create("$type.pdf", 20, 'application/pdf')])->assertCreated();
    }
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/kyc/status')->assertOk()->assertJsonPath('data.status', 'submitted');
});

it('reminds an unverified investor to verify, linking to Settings › Verification, until they submit', function () {
    Storage::fake('kyc');
    $user = invKycUser();
    $items = invKycPending($this, $user);
    expect($items)->toHaveKey('kyc.incomplete')
        ->and($items['kyc.incomplete']['link'])->toBe('dashboard.investor.settings?tab=verification');

    KycVerification::create(['user_id' => $user->id, 'verification_type' => 'entrepreneur', 'status' => 'submitted', 'submitted_at' => now()]);
    expect(invKycPending($this, $user))->not->toHaveKey('kyc.incomplete');
});
