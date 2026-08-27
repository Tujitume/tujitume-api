<?php

use App\Models\Programs\ProgramApplication;

uses(Tests\Feature\Programs\ProgramTestCase::class);

it('links an application to its program and applicant', function () {
    $application = ProgramApplication::factory()->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id]);
    expect($application->program->is($this->program))->toBeTrue()
        ->and($application->user->is($this->applicantUser))->toBeTrue();
});
