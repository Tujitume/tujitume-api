<?php

use App\Models\Programs\Monitoring\MESiteVisit;
uses(Tests\Feature\Programs\ProgramTestCase::class);
it('creates a scheduled site visit', function () { expect(MESiteVisit::factory()->create()->status)->toBe('scheduled'); });
