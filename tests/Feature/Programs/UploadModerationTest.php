<?php

use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Service\Validation\SpamImageChecker;
use Illuminate\Http\UploadedFile;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// Images uploaded with an application go through the same moderation as business and service images (Sightengine,
// off in tests: these swap in a checker that answers like it).

beforeEach(function () {
    $this->program->update([
        'application_start_date' => now()->subDay()->toDateString(), 'application_deadline' => now()->addDays(20)->toDateString(),
        'status' => 'published', 'visible' => 1,
    ]);
    $this->round->update(['status' => 'published']);
    $this->application->delete();
    $this->question = RoundCustomQuestion::create([
        'round_id' => $this->round->id, 'question_text' => 'Upload a photo of your shop', 'question_type' => 'file_upload',
        'options' => ['photo'], 'is_required' => true, 'display_order' => 1,
    ]);
});

function moderationSays(array $flags): void
{
    app()->instance(SpamImageChecker::class, new class($flags) extends SpamImageChecker {
        public function __construct(private array $flags) {}
        public function check($image_path) { return $this->flags; }
    });
}

function applyWithPhoto($test)
{
    return $test->actingAs($test->applicantUser, 'sanctum')->post("/api/v1/programs/{$test->program->id}/applications", [
        'contact_person_name' => 'Amina Otieno', 'contact_person_email' => 'amina@example.test', 'total_amount_requested' => 1,
        'round_answers' => [['question_id' => $test->question->id, 'files' => [UploadedFile::fake()->image('shop.jpg')]]],
    ], ['Accept' => 'application/json']);
}

it('refuses an inappropriate image with its name', function () {
    moderationSays(['nudity']);

    $response = applyWithPhoto($this)->assertStatus(422);
    expect(json_encode($response->json('errors')))->toContain('shop.jpg')->toContain('inappropriate')
        ->and(ProgramApplication::where('program_id', $this->program->id)->count())->toBe(0);
});

it('accepts a clean image', function () {
    moderationSays([]);

    applyWithPhoto($this)->assertSuccessful();
    expect(ProgramApplication::where('program_id', $this->program->id)->count())->toBe(1);
});

it('never checks a document, and is off when switched off', function () {
    $checker = new SpamImageChecker();
    expect($checker->refusal(UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf')))->toBeNull();

    config(['services.sightengine.enabled' => false]);
    expect($checker->check(UploadedFile::fake()->image('a.jpg')->getRealPath()))->toBe([]);
});
