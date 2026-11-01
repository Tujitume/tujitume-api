<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

use App\Models\Auth\User;

describe('Monitoring submissions', function () {
    it('requires authentication to submit evidence', function () {
        assertProgramUnauthenticated('POST', 'monitoring/checkpoints/999999/submit');
    });
});

describe('Checkpoint answers reach the right person', function () {
    it('tells the program owner when work is submitted and the submitter how it was answered', function () {
        $base = '/api/v1/programs/monitoring';
        $notes = fn (User $user) => \App\Models\Communication\Notifications::where('receiver_id', $user->id)->count();
        $checkpoint = $this->actingAsOrgOwner()->postJson("$base/applications/{$this->application->id}/checkpoints", [
            'checkpoint_name' => 'Stock check', 'type' => 'monitoring', 'respondent' => 'applicant', 'send' => true,
        ])->assertStatus(201)->json('data.id');
        $applicantBefore = $notes($this->applicantUser);
        $ownerBefore = $notes($this->orgUser);

        $submissionId = $this->actingAsApplicant()->postJson("$base/checkpoints/$checkpoint/submit", ['written_report' => 'Done'])
            ->assertStatus(201)->json('data.id');
        expect($notes($this->orgUser))->toBe($ownerBefore + 1);

        $this->actingAsOrgOwner()->postJson("$base/submissions/$submissionId/request-changes", ['reviewer_note' => 'Add photos'])->assertOk();
        expect($notes($this->applicantUser))->toBe($applicantBefore + 1);

        $this->actingAsOrgOwner()->postJson("$base/submissions/$submissionId/verify")->assertOk();
        expect($notes($this->applicantUser))->toBe($applicantBefore + 2);
    });
});
