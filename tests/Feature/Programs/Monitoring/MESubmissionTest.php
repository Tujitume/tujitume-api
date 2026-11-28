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

        // the owner's list says who handed it in
        $list = $this->actingAsOrgOwner()->getJson("$base/applications/{$this->application->id}/checkpoints")->assertOk();
        expect($list->json('data.0.submission.submitted_by.id') ?? $list->json('data.checkpoints.0.submission.submitted_by.id'))->toBe($this->applicantUser->id);

        $this->actingAsOrgOwner()->postJson("$base/submissions/$submissionId/request-changes", ['reviewer_note' => 'Add photos'])->assertOk();
        expect($notes($this->applicantUser))->toBe($applicantBefore + 1);

        $this->actingAsOrgOwner()->postJson("$base/submissions/$submissionId/verify")->assertOk();
        expect($notes($this->applicantUser))->toBe($applicantBefore + 2);
    });
});

describe('Resubmitting after changes are requested', function () {
    $base = '/api/v1/programs/monitoring';

    // A checkpoint the applicant answered with one photo, then sent back with changes requested
    $answered = function () use ($base) {
        $checkpoint = $this->actingAsOrgOwner()->postJson("$base/applications/{$this->application->id}/checkpoints", [
            'checkpoint_name' => 'Stock check', 'type' => 'monitoring', 'respondent' => 'applicant', 'send' => true,
        ])->assertStatus(201)->json('data.id');
        $submission = $this->actingAsApplicant()->post("$base/checkpoints/$checkpoint/submit", [
            'written_report' => 'Done',
            'files' => [['file' => \Illuminate\Http\UploadedFile::fake()->image('shop.jpg'), 'file_type' => 'photo', 'original_filename' => 'Shop photo']],
        ], ['Accept' => 'application/json'])->assertStatus(201);
        return [$checkpoint, $submission->json('data.id'), $submission->json('data.files.0.id')];
    };

    it('accepts photo, video and the old photo_video category, and refuses an unknown one', function () use ($base) {
        $checkpoint = $this->actingAsOrgOwner()->postJson("$base/applications/{$this->application->id}/checkpoints", [
            'checkpoint_name' => 'Media', 'type' => 'monitoring', 'respondent' => 'applicant', 'send' => true,
        ])->json('data.id');
        $fake = fn () => \Illuminate\Http\UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4');
        $response = $this->actingAsApplicant()->post("$base/checkpoints/$checkpoint/submit", ['files' => [
            ['file' => $fake(), 'file_type' => 'photo'],
            ['file' => $fake(), 'file_type' => 'video'],
            ['file' => $fake(), 'file_type' => 'photo_video'],
        ]], ['Accept' => 'application/json'])->assertStatus(201);
        expect(collect($response->json('data.files'))->pluck('file_type')->sort()->values()->all())->toBe(['photo', 'photo_video', 'video']);

        $this->actingAsApplicant()->post("$base/checkpoints/$checkpoint/submit", ['files' => [['file' => $fake(), 'file_type' => 'audio']]], ['Accept' => 'application/json'])
            ->assertStatus(422);
    });

    it('lets the applicant replace an earlier file while changes are requested', function () use ($base, $answered) {
        [$checkpoint, $submissionId, $oldFileId] = $answered->call($this);
        $oldPath = \App\Models\Programs\Monitoring\MESubmissionFile::find($oldFileId)->file_path;
        $this->actingAsOrgOwner()->postJson("$base/submissions/$submissionId/request-changes", ['reviewer_note' => 'Blurry'])->assertOk();

        $response = $this->actingAsApplicant()->post("$base/checkpoints/$checkpoint/submit", [
            'written_report' => 'Done again',
            'files' => [['file' => \Illuminate\Http\UploadedFile::fake()->image('shop2.jpg'), 'file_type' => 'photo', 'original_filename' => 'Shop photo', 'replace_file_id' => $oldFileId]],
        ], ['Accept' => 'application/json'])->assertStatus(201);

        expect($response->json('data.files'))->toHaveCount(1)
            ->and($response->json('data.files.0.id'))->not->toBe($oldFileId)
            ->and(\App\Models\Programs\Monitoring\MESubmissionFile::find($oldFileId))->toBeNull();
        \Illuminate\Support\Facades\Storage::disk('s3')->assertMissing($oldPath);
    });

    it('adds files without replace_file_id exactly as before', function () use ($base, $answered) {
        [$checkpoint, $submissionId] = $answered->call($this);
        $this->actingAsOrgOwner()->postJson("$base/submissions/$submissionId/request-changes", ['reviewer_note' => 'More'])->assertOk();
        $response = $this->actingAsApplicant()->post("$base/checkpoints/$checkpoint/submit", [
            'files' => [['file' => \Illuminate\Http\UploadedFile::fake()->image('extra.jpg'), 'file_type' => 'photo']],
        ], ['Accept' => 'application/json'])->assertStatus(201);
        expect($response->json('data.files'))->toHaveCount(2);
    });

    it('refuses to replace a file when changes were not requested, or a file of another submission', function () use ($base, $answered) {
        [$checkpoint, , $oldFileId] = $answered->call($this);
        // still waiting for review: nothing can be replaced
        $this->actingAsApplicant()->post("$base/checkpoints/$checkpoint/submit", [
            'files' => [['file' => \Illuminate\Http\UploadedFile::fake()->image('x.jpg'), 'file_type' => 'photo', 'replace_file_id' => $oldFileId]],
        ], ['Accept' => 'application/json'])->assertStatus(422);
        expect(\App\Models\Programs\Monitoring\MESubmissionFile::find($oldFileId))->not->toBeNull();

        // a file from another checkpoint's submission
        [$other, $otherSubmission] = $answered->call($this);
        $this->actingAsOrgOwner()->postJson("$base/submissions/$otherSubmission/request-changes", ['reviewer_note' => 'x'])->assertOk();
        $this->actingAsApplicant()->post("$base/checkpoints/$other/submit", [
            'files' => [['file' => \Illuminate\Http\UploadedFile::fake()->image('y.jpg'), 'file_type' => 'photo', 'replace_file_id' => $oldFileId]],
        ], ['Accept' => 'application/json'])->assertStatus(422);
        expect(\App\Models\Programs\Monitoring\MESubmissionFile::find($oldFileId))->not->toBeNull();
    });

    it('lets only the applicant delete a file, only while changes are requested, never after approval', function () use ($base, $answered) {
        [, $submissionId, $fileId] = $answered->call($this);
        // waiting for review
        $this->actingAsApplicant()->deleteJson("$base/submission-files/$fileId")->assertStatus(422);

        $this->actingAsOrgOwner()->postJson("$base/submissions/$submissionId/request-changes", ['reviewer_note' => 'Redo'])->assertOk();
        $outsider = User::factory()->create(['user_type_id' => 1]);
        $this->actingAs($outsider, 'sanctum')->deleteJson("$base/submission-files/$fileId")->assertStatus(403);
        $this->actingAsApplicant()->deleteJson("$base/submission-files/$fileId")->assertOk()->assertJsonPath('data.file_id', $fileId);
        expect(\App\Models\Programs\Monitoring\MESubmissionFile::find($fileId))->toBeNull();

        [, $approvedId, $approvedFile] = $answered->call($this);
        $this->actingAsOrgOwner()->postJson("$base/submissions/$approvedId/verify")->assertOk();
        $this->actingAsApplicant()->deleteJson("$base/submission-files/$approvedFile")->assertStatus(422);
    });
});
