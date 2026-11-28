<?php

use App\Models\Auth\User;
use App\Models\Capital\CapitalProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(Tests\Feature\Capital\CapitalTestCase::class);

// POST /register with user_type_id 5 creates the capital provider (owner) and its CapitalProfile
describe('Capital sign-up', function () {
    $payload = fn (array $extra = []) => $extra + [
        'user_type_id' => 5, 'first_name' => 'Grace', 'last_name' => 'Wanjiru', 'email' => 'grace@fund.test', 'password' => 'Secret123',
        'org_name' => 'Acacia Ventures', 'org_type' => 'Venture capital', 'inv_range' => ['10k-50k'], 'sectors' => ['Agriculture', 'Energy'],
        'startup_stage' => ['seed', 'early'], 'regions' => ['Kenya', 'Uganda'], 'eng_prefer' => ['equity', 'grant'],
        'turnover_range' => ['0-100k'], 'social_impact_areas' => ['Climate'],
    ];

    it('registers a capital provider with its profile and guidelines file', function () use ($payload) {
        Storage::fake('s3');
        $response = $this->postJson('/api/v1/register', $payload(['investment_guidelines' => UploadedFile::fake()->create('guide.pdf', 100, 'application/pdf')]))
            ->assertCreated()->assertJsonStructure(['token', 'user']);

        $user = User::where('email', 'grace@fund.test')->first();
        expect($user->user_type_id)->toBe(5)->and($user->isCapital())->toBeTrue()->and($user->display_name)->toBe('Acacia Ventures');
        $profile = CapitalProfile::where('user_id', $user->id)->first();
        expect($profile->role_id)->toBeNull()
            ->and($profile->org_name)->toBe('Acacia Ventures')
            ->and($profile->sectors)->toBe(['Agriculture', 'Energy'])
            ->and($profile->regions)->toBe(['Kenya', 'Uganda'])
            ->and($profile->eng_prefer)->toBe(['equity', 'grant'])
            ->and($profile->inv_range)->toBe(['10k-50k'])
            ->and($profile->social_impact_areas)->toBe(['Climate']);
        Storage::disk('s3')->assertExists($profile->investment_guidelines);
        expect($response->json('user.capital_profile.org_name'))->toBe('Acacia Ventures');
    });

    it('validates the capital fields and refuses a used email', function () use ($payload) {
        $this->postJson('/api/v1/register', ['user_type_id' => 5, 'email' => 'x@y.test'])->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'password', 'org_name', 'org_type', 'inv_range', 'sectors', 'startup_stage', 'regions', 'eng_prefer']);
        $this->postJson('/api/v1/register', $payload(['email' => $this->capitalOwner->email]))->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->postJson('/api/v1/register', $payload(['investment_guidelines' => UploadedFile::fake()->create('x.exe', 10)]))->assertUnprocessable()->assertJsonValidationErrors(['investment_guidelines']);
    });

    it('lets the new provider open its capital area right away', function () use ($payload) {
        $token = $this->postJson('/api/v1/register', $payload())->json('token');
        $this->withToken($token)->getJson('/api/v1/capital/capital-offers')->assertOk()->assertJsonPath('capital', []);
    });
});
