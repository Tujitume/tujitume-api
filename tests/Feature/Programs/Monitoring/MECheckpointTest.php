<?php

use App\Models\Programs\Monitoring\MECheckpoint;
uses(Tests\Feature\Programs\ProgramTestCase::class);
it('creates a pending monitoring checkpoint', function () { expect(MECheckpoint::factory()->create()->status)->toBe('pending'); });
