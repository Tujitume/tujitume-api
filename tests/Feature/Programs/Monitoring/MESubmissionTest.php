<?php

use App\Models\Programs\Monitoring\MESubmission;
uses(Tests\Feature\Programs\ProgramTestCase::class);
it('creates a submitted monitoring submission', function () { expect(MESubmission::factory()->create()->status)->toBe('submitted'); });
