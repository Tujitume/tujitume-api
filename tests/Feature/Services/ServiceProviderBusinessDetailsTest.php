<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\Services\Services;
use App\Service\Validation\SpamImageChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Add / Edit Service sends the provider's business details. Create used to drop them (no columns),
 * and edit sent them straight into the UPDATE, which failed on the unknown columns.
 */
class ServiceProviderBusinessDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Storage::fake('public');
        $this->mock(SpamImageChecker::class, fn ($m) => $m->shouldReceive('check')->andReturn([]));
        $this->provider = User::factory()->create(['user_type_id' => 3, 'lipr_wallet' => 'W-TEST-1']);
        KycVerification::create(['user_id' => $this->provider->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Bookkeeping & Tax Filing',
            'price' => '12000',
            'category' => 'Finance, Accounting & Tax',
            'location' => 'Nairobi, Kenya',
            'lat' => '-1.2864',
            'lng' => '36.8172',
            'details' => 'Monthly bookkeeping and KRA returns.',
            'business_legal_name' => 'Pendo Accounting',
            'legal_structure' => 'sole_proprietor',
            'registration_number' => 'BN-2026-0042',
            'registration_country' => 'Kenya',
            'tax_pin' => 'A012345678Z',
            'country' => 'Kenya',
            'region' => 'Nairobi County',
            'physical_address' => 'Ngong Road',
            'operates_through_business' => 'true',
            'requires_professional_licence' => 'true',
            'image' => UploadedFile::fake()->image('service.png'),
            'identification' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'),
            'pin' => UploadedFile::fake()->create('pin.pdf', 10, 'application/pdf'),
            'document' => UploadedFile::fake()->create('reg.pdf', 10, 'application/pdf'),
            'professional_licence' => UploadedFile::fake()->create('licence.pdf', 10, 'application/pdf'),
        ];
    }

    public function test_create_keeps_the_business_details_and_the_licence(): void
    {
        $this->actingAs($this->provider, 'sanctum')->post('/api/v1/services/create-service', $this->form())->assertOk();

        $service = Services::where('user_id', $this->provider->id)->firstOrFail();
        $this->assertSame('Pendo Accounting', $service->business_legal_name);
        $this->assertSame('BN-2026-0042', $service->registration_number);
        $this->assertSame('Nairobi County', $service->region);
        $this->assertSame('Ngong Road', $service->physical_address);
        $this->assertSame('A012345678Z', $service->tax_pin);
        $this->assertTrue((bool) $service->operates_through_business);
        $this->assertTrue((bool) $service->requires_professional_licence);
        $this->assertNotEmpty($service->professional_licence);
        $this->assertNotEmpty($service->document);
        $this->assertSame('-1.2864', (string) $service->lat);
    }

    public function test_proof_of_registration_is_only_needed_for_a_registered_business(): void
    {
        $form = $this->form(['operates_through_business' => 'false', 'requires_professional_licence' => 'false']);
        unset($form['document'], $form['professional_licence']);
        $this->actingAs($this->provider, 'sanctum')->post('/api/v1/services/create-service', $form)->assertOk();

        $this->actingAs($this->provider, 'sanctum')->post('/api/v1/services/create-service', $this->form(['document' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('document');
    }

    public function test_edit_saves_the_form_including_business_details(): void
    {
        $service = Services::create(['user_id' => $this->provider->id, 'name' => 'Old', 'price' => 1000, 'category' => 'IT', 'location' => 'Nairobi', 'details' => 'Help']);

        $this->actingAs($this->provider, 'sanctum')->post('/api/v1/services/up_service', [
            'id' => $service->id, 'name' => 'New name', 'details' => 'More help', 'price' => '15000', 'category' => 'Legal',
            'location' => 'Mombasa', 'business_legal_name' => 'Pendo Accounting', 'region' => 'Mombasa County',
            'physical_address' => 'Moi Avenue', 'operates_through_business' => 'false', 'requires_professional_licence' => 'false',
            'unknown_field' => 'ignored',
        ])->assertOk();

        $service->refresh();
        $this->assertSame('New name', $service->name);
        $this->assertSame(15000, (int) $service->price);
        $this->assertSame('Legal', $service->category);
        $this->assertSame('Pendo Accounting', $service->business_legal_name);
        $this->assertSame('Moi Avenue', $service->physical_address);

        // the list the edit page reads gives the saved details back
        $row = collect($this->actingAs($this->provider, 'sanctum')->getJson('/api/v1/services/dashhome/service')->assertOk()->json('services'))->firstWhere('id', $service->id);
        $this->assertSame('Pendo Accounting', $row['business_legal_name']);
        $this->assertSame('Mombasa County', $row['region']);
        $this->assertSame('Moi Avenue', $row['physical_address']);
    }

    public function test_explore_never_shows_the_private_details_or_documents(): void
    {
        $this->actingAs($this->provider, 'sanctum')->post('/api/v1/services/create-service', $this->form())->assertOk();
        Services::where('user_id', $this->provider->id)->update(['active' => 1]);
        $customer = User::factory()->create(['user_type_id' => 1]);

        foreach (['/api/v1/latServicesAuth?all=1', '/api/v1/latServicesAuth'] as $url) {
            $row = collect($this->actingAs($customer, 'sanctum')->getJson($url)->assertOk()->json('data'))->firstWhere('user_id', $this->provider->id);
            $this->assertNotNull($row, $url);
            foreach (['tax_pin', 'registration_number', 'physical_address', 'professional_licence', 'pin', 'identification', 'document'] as $private) {
                $this->assertArrayNotHasKey($private, $row, "$url shows $private");
            }
        }

        // the provider's own paged list still has them
        $own = $this->actingAs($this->provider, 'sanctum')->getJson('/api/v1/services/dashhome/service?page=1&per_page=10')->assertOk()->json('data.items.0');
        $this->assertSame('A012345678Z', $own['tax_pin']);
    }
}
