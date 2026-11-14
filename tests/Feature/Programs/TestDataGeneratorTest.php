<?php

use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Service\TestData\RealisticAnswers;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// The generator writes its sample files under public/; remove them so a test run leaves nothing behind
afterEach(function () {
    if (isset($this->program, $this->round)) \Illuminate\Support\Facades\File::deleteDirectory(public_path("files/programApps/{$this->program->id}/rounds/{$this->round->id}"));
});

it('makes generated applications answer the round\'s own questions: names, emails, Kenyan phones, choices, numbers and readable files', function () {
    $this->round->update(['round_number' => 1, 'review_method' => 'manual']);
    RoundCustomQuestion::where('round_id', $this->round->id)->delete();
    $mk = fn (string $text, string $type, array $options = [], int $order = 1) => RoundCustomQuestion::create(['round_id' => $this->round->id, 'question_text' => $text, 'question_type' => $type, 'is_required' => true, 'display_order' => $order, 'options' => $options]);
    $name = $mk('Your first and last name', 'short_answer', [], 1);
    $company = $mk('Your company email', 'short_answer', [], 2);
    $phone = $mk('Your mobile phone number', 'short_answer', [], 3);
    $stage = $mk('Business stage', 'dropdown', ['Idea', 'Pilot', 'Growing'], 4);
    $team = $mk('How many people are on your team?', 'number', [], 5);
    $cv = $mk('Upload your CV', 'file_upload', ['category:document'], 6);
    $market = $mk('Describe your market opportunity', 'long_text', [], 7);

    mt_srand(7);
    $this->actingAsOrgOwner()->postJson('/api/v1/programs/test-data/generate-applications', ['round_id' => $this->round->id, 'num_applications' => 12])->assertStatus(201);

    $answers = fn ($q) => ApplicationRoundResponse::where('question_id', $q->id)->get();
    // Never filler: no answer is Latin placeholder text
    expect(ApplicationRoundResponse::whereIn('question_id', [$name->id, $company->id, $phone->id, $stage->id, $team->id, $market->id])->pluck('response')->filter(fn ($r) => str_contains(strtolower((string) $r), 'lorem') || str_contains(strtolower((string) $r), 'adipiscing')))->toBeEmpty();

    // Mostly proper names, company emails, and Kenyan numbers
    expect($answers($name)->filter(fn ($r) => count(explode(' ', trim($r->response))) >= 2)->count())->toBeGreaterThanOrEqual(8)
        ->and($answers($company)->filter(fn ($r) => str_contains($r->response, '@') && str_contains($r->response, '.'))->count())->toBeGreaterThanOrEqual(8)
        ->and($answers($phone)->filter(fn ($r) => preg_match('/^(\+?254|0)[71]\d{8}$/', $r->response))->count())->toBeGreaterThanOrEqual(8);
    // A choice question gets one of its choices, a number question a number
    expect($answers($stage)->every(fn ($r) => in_array($r->response, ['Idea', 'Pilot', 'Growing'], true)))->toBeTrue()
        ->and($answers($team)->every(fn ($r) => is_numeric($r->response)))->toBeTrue();
    // The CV question has a real file with text in it
    $file = $answers($cv)->first();
    expect($file->file_path)->not->toBeNull()->and(file_get_contents(public_path($file->file_path)))->toContain('%PDF-1.4')->toContain('Tj');
});

it('builds a PDF whose offsets are valid, with the text in it', function () {
    $pdf = RealisticAnswers::textPdf(['Hello world', 'Second (line)']);
    expect($pdf)->toStartWith('%PDF-1.4')->toContain('(Hello world) Tj')->toContain('Second \\(line\\)')->toEndWith('%%EOF');
    preg_match('/startxref\n(\d+)\n/', $pdf, $m);
    expect(substr($pdf, (int) $m[1], 4))->toBe('xref');
});
