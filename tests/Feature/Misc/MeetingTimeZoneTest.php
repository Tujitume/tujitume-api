<?php

namespace Tests\Feature\Misc;

use App\Models\Auth\User;
use App\Models\Auth\UserSetting;
use App\Models\Communication\Meeting;
use App\Models\Misc\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** A meeting is one moment: each person sees it in their own Settings time zone, never as the same bare clock time. */
class MeetingTimeZoneTest extends TestCase
{
    use RefreshDatabase;

    private function personIn(string $zone): User
    {
        $user = User::factory()->create(['user_type_id' => 1]);
        UserSetting::query()->updateOrCreate(['user_id' => $user->id], ['timezone' => $zone]);

        return $user;
    }

    public function test_a_meeting_set_for_7am_in_nairobi_is_the_evening_before_in_new_york(): void
    {
        Mail::fake();
        $host = $this->personIn('Africa/Nairobi');
        $guest = $this->personIn('America/New_York');

        $this->actingAs($host, 'sanctum')->postJson('/api/v1/create-meeting', [
            'client_id' => $guest->id, 'date' => '2026-12-01', 'time' => '07:00', 'timezone' => 'Africa/Nairobi',
            'title' => 'Intro call', 'link' => 'https://meet.example.com/abc',
        ])->assertCreated();

        $meeting = Meeting::firstOrFail();
        $this->assertSame('Africa/Nairobi', $meeting->timezone);
        $this->assertSame('2026-12-01 04:00:00', (string) $meeting->getRawOriginal('starts_at'));

        // The list gives the exact moment (ISO with offset), so each side shows it in their own zone
        $row = $this->actingAs($guest, 'sanctum')->getJson('/api/v1/meetings')->assertOk()->json('meetings.0');
        $this->assertSame('2026-12-01T04:00:00+00:00', $row['starts_at']);
        $this->assertSame('Africa/Nairobi', $row['timezone']);
    }

    public function test_without_a_zone_the_hosts_settings_time_zone_is_used(): void
    {
        Mail::fake();
        $host = $this->personIn('America/New_York');
        $guest = $this->personIn('Africa/Nairobi');

        $this->actingAs($host, 'sanctum')->postJson('/api/v1/create-meeting', [
            'client_id' => $guest->id, 'date' => '2026-12-01', 'time' => '09:00', 'title' => 'Sync', 'link' => 'https://meet.example.com/x',
        ])->assertCreated();

        $this->assertSame('2026-12-01 14:00:00', (string) Meeting::firstOrFail()->getRawOriginal('starts_at'));
    }

    public function test_an_unknown_time_zone_is_refused(): void
    {
        $host = $this->personIn('Africa/Nairobi');
        $guest = $this->personIn('Africa/Nairobi');

        $this->actingAs($host, 'sanctum')->postJson('/api/v1/create-meeting', [
            'client_id' => $guest->id, 'date' => '2026-12-01', 'time' => '09:00', 'timezone' => 'Mars/Olympus', 'title' => 'x', 'link' => 'https://meet.example.com/x',
        ])->assertStatus(422);
    }

    public function test_an_event_gives_the_exact_moments_of_its_typed_clock_times(): void
    {
        $event = new Event(['start_date' => '2026-12-01 09:00:00', 'end_date' => '2026-12-01 17:00:00', 'timezone' => 'Africa/Nairobi']);

        $this->assertSame('2026-12-01T06:00:00+00:00', $event->starts_at);
        $this->assertSame('2026-12-01T14:00:00+00:00', $event->ends_at);
    }
}
