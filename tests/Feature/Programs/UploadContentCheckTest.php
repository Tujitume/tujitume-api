<?php

use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Service\Program\RoundMatchScore;
use Illuminate\Http\UploadedFile;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// Uploads are checked by their real content (sniffed from the bytes), not only their name: a photo renamed to .pdf is
// refused with its name, on file-upload questions and on round documents.

/** A real JPEG whose name says it is something else. */
function renamedPhoto(string $name): UploadedFile
{
    // The fake's own temporary file goes away with it, so the bytes are copied to a file of our own
    $path = tempnam(sys_get_temp_dir(), 'renamed');
    $image = imagecreatetruecolor(20, 20);
    imagejpeg($image, $path);

    return new UploadedFile($path, $name, null, null, true);
}

beforeEach(function () {
    $this->program->update([
        'application_start_date' => now()->subDay()->toDateString(), 'application_deadline' => now()->addDays(20)->toDateString(),
        'status' => 'published', 'visible' => 1,
    ]);
    $this->round->update(['status' => 'published']);
    $this->application->delete();
});

function applyWithFiles($test, array $extra)
{
    return $test->actingAs($test->applicantUser, "sanctum")->post("/api/v1/programs/{$test->program->id}/applications", $extra + [
        'contact_person_name' => 'Amina Otieno', 'contact_person_email' => 'amina@example.test', 'total_amount_requested' => 1,
    ], ['Accept' => 'application/json']);
}

it('tells a renamed photo apart from a real file', function () {
    $scorer = app(RoundMatchScore::class);
    expect($scorer->contentMismatch(renamedPhoto('plan.pdf')))->toContain('plan.pdf')
        ->and($scorer->contentMismatch(renamedPhoto('plan.docx')))->toContain('plan.docx')
        ->and($scorer->contentMismatch(renamedPhoto('photo.png')))->toBeNull()
        ->and($scorer->contentMismatch(UploadedFile::fake()->image('photo.jpg')))->toBeNull()
        ->and($scorer->contentMismatch(UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf')))->toBeNull();
});

it('refuses a photo renamed to .pdf on a file-upload question', function () {
    $question = RoundCustomQuestion::create([
        'round_id' => $this->round->id, 'question_text' => 'Upload your business plan', 'question_type' => 'file_upload',
        'options' => ['document', 'type:pdf'], 'is_required' => true, 'display_order' => 1,
    ]);

    $response = applyWithFiles($this, ['round_answers' => [['question_id' => $question->id, 'files' => [renamedPhoto('plan.pdf')]]]]);

    $response->assertStatus(422);
    expect(json_encode($response->json('errors')))->toContain('plan.pdf')
        ->and(ProgramApplication::where('program_id', $this->program->id)->count())->toBe(0);
});

it('refuses a photo renamed to .pdf as a round document', function () {
    $response = applyWithFiles($this, ['round_documents' => [['document_type' => 'Business permit', 'file' => renamedPhoto('permit.pdf')]]]);

    $response->assertStatus(422)->assertJsonValidationErrors('round_documents.0.file');
    expect(ProgramApplication::where('program_id', $this->program->id)->count())->toBe(0);
});
