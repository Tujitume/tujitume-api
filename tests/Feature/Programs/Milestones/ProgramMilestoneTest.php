<?php

use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
uses(Tests\Feature\Programs\ProgramTestCase::class);
it('identifies editable hybrid templates', function () { $application = ProgramApplication::factory()->create(['planning_mode' => 'hybrid']); $milestone = ProgramMilestone::factory()->create(['app_id' => $application->id, 'allowed_edits' => ['title']]); expect($milestone->canApplicantEdit('title'))->toBeTrue()->and($milestone->canApplicantEdit('amount'))->toBeFalse(); });
