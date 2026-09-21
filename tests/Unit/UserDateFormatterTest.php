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
}
