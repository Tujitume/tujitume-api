<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Marking a service milestone done can carry what was delivered (a note and files). The customer sees it in the
 * booking's milestones before approving; the milestone's own document is a link that opens (it was a raw path).
 */
class ServiceMilestoneDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_provider_delivers_with_a_note_and_files_and_the_customer_sees_them(): void
    {
        Storage::fake('s3');
        $provider = User::factory()->create(['user_type_id' => 3]);
        KycVerification::create(['user_id' => $provider->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);
        $customer = User::factory()->create(['user_type_id' => 1]);
        $service = Services::create(['user_id' => $provider->id, 'name' => 'Bookkeeping', 'price' => 5000, 'category' => 'Finance', 'location' => 'Nairobi', 'details' => 'Books', 'active' => 1]);
        $booking = ServiceBooking::create(['service_id' => $service->id, 'booker_id' => $customer->id, 'service_owner_id' => $provider->id, 'date' => now()->addDay()->toDateString(), 'status' => 'paid', 'paid' => 1]);
        Storage::disk('s3')->put('files/Smilestones/1/brief.pdf', 'pdf');
        $mile = ServiceBookingMilestone::create(['service_id' => $service->id, 'booker_id' => $customer->id, 'booking_id' => $booking->id, 'title' => 'Kickoff', 'amount' => 5000, 'status' => 'In Progress', 'active' => 1, 'document' => 'files/Smilestones/1/brief.pdf']);

        $this->actingAs($provider, 'sanctum')->post('/api/v1/services/mile_s_status', [
            'id' => $mile->id, 'status' => 'Done', 'note' => 'Opening balances included.',
            'files' => [UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'), UploadedFile::fake()->image('ledger.png')],
        ], ['Accept' => 'application/json'])->assertOk();

        $mile->refresh();
        $this->assertSame('Done', $mile->status);
        $this->assertSame('Opening balances included.', $mile->delivery_note);
        $this->assertCount(2, $mile->delivery_files);
        Storage::disk('s3')->assertExists($mile->delivery_files[0]['path']);

        $row = $this->actingAs($customer, 'sanctum')->getJson("/api/v1/services/bookings/{$booking->id}/flow")->assertOk()->json('data.milestones.0');
        $this->assertSame('Opening balances included.', $row['delivery']['note']);
        $this->assertSame(['report.pdf', 'ledger.png'], array_column($row['delivery']['files'], 'name'));
        $this->assertStringStartsWith('http', $row['delivery']['files'][0]['url']);
        $this->assertStringStartsWith('http', $row['document']);

        // marking done without anything still works (the note and files are optional)
        $mile->update(['status' => 'In Progress']);
        $this->actingAs($provider, 'sanctum')->postJson('/api/v1/services/mile_s_status', ['id' => $mile->id, 'status' => 'Done'])->assertOk();
        $this->assertNull($mile->fresh()->delivery_files);

        // only files of the allowed kinds
        $mile->update(['status' => 'In Progress']);
        $this->actingAs($provider, 'sanctum')->post('/api/v1/services/mile_s_status', ['id' => $mile->id, 'status' => 'Done', 'files' => [UploadedFile::fake()->create('run.exe', 5)]], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
