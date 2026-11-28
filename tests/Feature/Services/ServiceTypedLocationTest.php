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
 * A location the provider typed (instead of picking a suggestion) has no coordinates. The service used to get the
 * column's default London lat/lng, so it showed in London on the Explore map.
 */
class ServiceTypedLocationTest extends TestCase
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

    private function form(array $extra = []): array
    {
        return $extra + [
            'name' => 'Brand Strategy Workshop',
            'price' => '9000',
            'category' => 'Marketing',
            'location' => 'Kisumu',
            'details' => 'Two-day brand workshop.',
            'image' => UploadedFile::fake()->image('service.png'),
            'identification' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'),
            'pin' => UploadedFile::fake()->create('pin.pdf', 10, 'application/pdf'),
        ];
    }

    public static function typedLocations(): array
    {
        return ['coordinates left out' => [[]], 'coordinates sent empty' => [['lat' => '', 'lng' => '']]];
    }

    /** @dataProvider typedLocations */
    public function test_a_typed_location_stores_no_coordinates(array $coords): void
    {
        $this->actingAs($this->provider, 'sanctum')->post('/api/v1/services/create-service', $this->form($coords))->assertOk();

        $service = Services::where('user_id', $this->provider->id)->firstOrFail();
        $this->assertNull($service->lat);
        $this->assertNull($service->lng);
        $this->assertSame('Kisumu', $service->location);
    }

    public function test_a_picked_place_keeps_its_coordinates(): void
    {
        $this->actingAs($this->provider, 'sanctum')->post('/api/v1/services/create-service', $this->form(['lat' => '-0.0917', 'lng' => '34.7680']))->assertOk();

        $service = Services::where('user_id', $this->provider->id)->firstOrFail();
        $this->assertSame('-0.0917', (string) $service->lat);
        $this->assertSame('34.7680', (string) $service->lng);
    }
}
