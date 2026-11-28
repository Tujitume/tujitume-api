<?php

use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Programs\AiReview\AiReviewTestCase;

uses(AiReviewTestCase::class);

beforeEach(function () {
    Storage::fake('s3');
    $this->aiRound('manual');
    $this->path = 'files/programApps/1/rounds/1/cv.pdf';
    Storage::disk('s3')->put($this->path, '%PDF-1.4 fake cv');
    $this->answer = ApplicationRoundResponse::updateOrCreate(
        ['application_id' => $this->application->id, 'question_id' => $this->question->id],
        ['round_id' => $this->round->id, 'file_path' => $this->path, 'file_paths' => [$this->path]],
    );
    $this->url = "/api/v1/programs/applications/{$this->application->id}/answers/{$this->answer->id}/file";
});

it('streams the file to the program owner', function () {
    $res = $this->actingAsOrgOwner()->get($this->url)->assertOk();
    expect($res->streamedContent())->toBe('%PDF-1.4 fake cv')
        ->and($res->headers->get('Cache-Control'))->toContain('no-store');
});

it('streams it to an assigned reviewer only', function () {
    $this->assignReviewer();
    $this->actingAsReviewer()->get($this->url)->assertOk();
    ReviewerApplicationAssignment::query()->delete();
    $this->actingAsReviewer()->get($this->url)->assertStatus(403);
});

it('refuses another organization and the applicant', function () {
    $this->actingAs($this->otherOrgUser(), 'sanctum')->get($this->url)->assertStatus(403);
    $this->actingAsApplicant()->get($this->url)->assertStatus(403);
});

it('does not serve an answer that belongs to another application, or a missing index', function () {
    $this->actingAsOrgOwner()->get("{$this->url}?index=5")->assertStatus(404);
    $other = \App\Models\Programs\ProgramApplication::factory()->create(['program_id' => $this->program->id, 'current_round_id' => $this->round->id]);
    $this->actingAsOrgOwner()->get("/api/v1/programs/applications/{$other->id}/answers/{$this->answer->id}/file")->assertStatus(404);
});

it('streams a round document to the owner and an assigned coach only, never through another application', function () {
    $docPath = 'files/programApps/1/rounds/1/registration.pdf';
    Storage::disk('s3')->put($docPath, '%PDF-1.4 certificate');
    $doc = \App\Models\Programs\Rounds\RoundRequiredDocument::create([
        'application_id' => $this->application->id, 'round_id' => $this->round->id, 'document_type' => 'Registration certificate', 'file_path' => $docPath,
    ]);
    $url = "/api/v1/programs/applications/{$this->application->id}/documents/{$doc->id}/file";

    expect($this->actingAsOrgOwner()->get($url)->assertOk()->streamedContent())->toBe('%PDF-1.4 certificate');
    $this->actingAsReviewer()->get($url)->assertStatus(403);
    $this->assignReviewer();
    $this->actingAsReviewer()->get($url)->assertOk();
    $this->actingAs($this->otherOrgUser(), 'sanctum')->get($url)->assertStatus(403);
    $this->actingAsApplicant()->get($url)->assertStatus(403);

    $other = \App\Models\Programs\ProgramApplication::factory()->create(['program_id' => $this->program->id, 'current_round_id' => $this->round->id]);
    $this->actingAsOrgOwner()->get("/api/v1/programs/applications/{$other->id}/documents/{$doc->id}/file")->assertStatus(404);
});
