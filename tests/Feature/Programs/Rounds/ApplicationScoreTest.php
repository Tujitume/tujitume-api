<?php

use App\Models\Programs\Rounds\ApplicationScore;
uses(Tests\Feature\Programs\ProgramTestCase::class);
it('persists criterion scores as an array', function () { $score = ApplicationScore::factory()->create(); expect($score->criterion_scores)->toBeArray(); });
