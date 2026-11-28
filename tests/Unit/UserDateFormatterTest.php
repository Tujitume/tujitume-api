<?php

namespace Tests\Unit;

use App\Support\UserDateFormatter;
use PHPUnit\Framework\TestCase;

class UserDateFormatterTest extends TestCase
{
    public function test_it_formats_dates_and_preserves_datetime_time(): void
    {
        $payload = UserDateFormatter::transform([
            'dob' => '1990-01-31',
            'program' => ['application_deadline' => '2026-09-25'],
            'round' => ['open_date' => '2026-10-01T09:30:00.000000Z'],
            'note' => '2026-10-01',
        ], 'MM-DD-YYYY');

        $this->assertSame('01-31-1990', $payload['dob']);
        $this->assertSame('09-25-2026', $payload['program']['application_deadline']);
        $this->assertSame('10-01-2026 09:30:00', $payload['round']['open_date']);
        $this->assertSame('2026-10-01', $payload['note']);
    }

    public function test_timestamps_are_shown_in_the_readers_own_time_zone(): void
    {
        $payload = ['created_at' => '2026-11-26T04:00:00.000000Z', 'scored_at' => '2026-11-26 04:00:00'];

        $nairobi = UserDateFormatter::transform($payload, 'DD/MM/YYYY', null, 'Africa/Nairobi');
        $newYork = UserDateFormatter::transform($payload, 'MM/DD/YYYY', null, 'America/New_York');

        $this->assertSame('26/11/2026 07:00:00', $nairobi['created_at']);
        $this->assertSame('26/11/2026 07:00:00', $nairobi['scored_at']);
        $this->assertSame('11/25/2026 23:00:00', $newYork['created_at']);
    }

    public function test_event_clock_times_and_exact_moments_are_not_moved(): void
    {
        $payload = UserDateFormatter::transform([
            'event' => ['timezone' => 'Africa/Nairobi', 'start_date' => '2026-12-01 09:00:00', 'created_at' => '2026-11-26 04:00:00'],
            'meeting' => ['starts_at' => '2026-12-01T04:00:00+00:00', 'date' => '2026-12-01'],
        ], 'DD/MM/YYYY', null, 'America/New_York');

        $this->assertSame('01/12/2026 09:00:00', $payload['event']['start_date']);
        $this->assertSame('25/11/2026 23:00:00', $payload['event']['created_at']);
        $this->assertSame('2026-12-01T04:00:00+00:00', $payload['meeting']['starts_at']);
        $this->assertSame('01/12/2026', $payload['meeting']['date']);
    }

    public function test_a_calendar_date_stays_on_its_day_for_readers_west_of_utc(): void
    {
        // `date` columns serialize as midnight UTC; that is a day, not a moment
        $payload = UserDateFormatter::transform([
            'application_deadline' => '2026-12-01T00:00:00.000000Z',
            'open_date' => '2026-12-01 00:00:00',
            'created_at' => '2026-12-01T00:00:05.000000Z',
        ], 'DD/MM/YYYY', null, 'America/New_York');

        $this->assertSame('01/12/2026 00:00:00', $payload['application_deadline']);
        $this->assertSame('01/12/2026 00:00:00', $payload['open_date']);
        $this->assertSame('30/11/2026 19:00:05', $payload['created_at']);
    }

    public function test_an_unknown_time_zone_falls_back_to_nairobi(): void
    {
        $this->assertSame('Africa/Nairobi', UserDateFormatter::zone('Mars/Olympus'));
        $this->assertSame('Europe/London', UserDateFormatter::zone('Europe/London'));
    }
}
