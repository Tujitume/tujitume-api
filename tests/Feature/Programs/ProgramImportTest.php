<?php

namespace Tests\Feature\Programs;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(ProgramTestCase::class);

const IMPORTS = '/api/v1/programs/imports';

it('saves an import, its step and its file, and lists it for the organization', function () {
    Storage::fake('local');
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'new', 'stage' => 'setup'])
        ->assertCreated()->assertJsonPath('data.step', 'upload')->assertJsonPath('data.status', 'draft')->json('data.id');

    $file = UploadedFile::fake()->create('Amari - New program - import.xlsx', 20);
    $this->actingAsOrgOwner()->post(IMPORTS."/$id/file", ['file' => $file], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.file.name', 'Amari - New program - import.xlsx');
    Storage::disk('local')->assertExists(ProgramImport::find($id)->file_path);

    $this->actingAsOrgOwner()->patchJson(IMPORTS."/$id", ['step' => 'check'])->assertOk()->assertJsonPath('data.step', 'check');
    $this->actingAsOrgOwner()->get(IMPORTS."/$id/file")->assertOk();
    $this->actingAsOrgOwner()->getJson(IMPORTS)->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.file.name', 'Amari - New program - import.xlsx');
});

it('drops the file when the choices change, and refuses files that are not Excel or CSV', function () {
    Storage::fake('local');
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'new', 'stage' => 'setup'])->json('data.id');
    $this->actingAsOrgOwner()->post(IMPORTS."/$id/file", ['file' => UploadedFile::fake()->create('notes.pdf', 5)], ['Accept' => 'application/json'])->assertStatus(422);
    $this->actingAsOrgOwner()->post(IMPORTS."/$id/file", ['file' => UploadedFile::fake()->create('data.csv', 5)], ['Accept' => 'application/json'])->assertOk();
    $path = ProgramImport::find($id)->file_path;

    $this->actingAsOrgOwner()->patchJson(IMPORTS."/$id", ['stage' => 'review'])->assertOk()->assertJsonPath('data.file', null);
    Storage::disk('local')->assertMissing($path);
});

it('needs a program the organization runs when adding to an existing program', function () {
    $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'existing', 'stage' => 'review'])->assertStatus(422);
    $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'existing', 'stage' => 'review', 'program_id' => $this->program->id, 'current_stage' => 'review'])
        ->assertCreated()->assertJsonPath('data.program_title', $this->program->program_title);

    $stranger = User::factory()->create(['user_type_id' => 4]);
    $theirs = Program::factory()->create(['user_id' => $stranger->id]);
    $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'existing', 'stage' => 'review', 'program_id' => $theirs->id])->assertForbidden();
});

it('keeps other organizations and applicants out', function () {
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'new', 'stage' => 'setup'])->json('data.id');

    $other = User::factory()->create(['user_type_id' => 4]);
    $org = Organization::factory()->create(['owner_user_id' => $other->id]);
    $other->update(['organization_id' => $org->id]);
    $this->actingAs($other, 'sanctum')->getJson(IMPORTS."/$id")->assertForbidden();
    $this->actingAs($other, 'sanctum')->patchJson(IMPORTS."/$id", ['step' => 'check'])->assertForbidden();
    $this->actingAs($other, 'sanctum')->deleteJson(IMPORTS."/$id")->assertForbidden();
    expect($this->actingAs($other, 'sanctum')->getJson(IMPORTS)->json('data'))->toBe([]);

    $this->actingAsApplicant()->getJson(IMPORTS)->assertForbidden();
    $this->actingAsOrgOwner()->deleteJson(IMPORTS."/$id")->assertOk();
    expect(ProgramImport::find($id))->toBeNull();
});

it('keeps what was fixed on the page, and forgets it when the choices change', function () {
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'new', 'stage' => 'setup'])->json('data.id');
    $data = ['program' => ['title' => 'Nyumba Bora', 'totalAmount' => 5000000], 'rounds' => [['number' => 1, 'name' => 'Eligibility check']], 'questionRows' => []];

    $this->actingAsOrgOwner()->patchJson(IMPORTS."/$id", ['data' => $data])->assertOk()->assertJsonPath('data.data.program.title', 'Nyumba Bora');
    $this->actingAsOrgOwner()->getJson(IMPORTS."/$id")->assertJsonPath('data.data.rounds.0.name', 'Eligibility check');

    $this->actingAsOrgOwner()->patchJson(IMPORTS."/$id", ['stage' => 'review'])->assertOk()->assertJsonPath('data.data', null);
});

it('starts a many-programs import without a single stage', function () {
    $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many'])->assertCreated()->assertJsonPath('data.stage', 'mixed')->assertJsonPath('data.program_id', null);
    $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'review'])->assertCreated()->assertJsonPath('data.stage', 'review');
    $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'new'])->assertStatus(422);
});

it('checks an import against what is already there without creating anything', function () {
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $programsBefore = Program::count();
    $cells = fn (array $o) => $o + ['industry' => 'Grants', 'total_amount' => '5000000', 'per_applicant' => '250000', 'stage' => 'setup'];
    $data = ['version' => 2,
        'programs' => [
            ['id' => 1, 'cells' => $cells(['code' => 'P1', 'title' => $this->program->program_title])],
            ['id' => 2, 'cells' => $cells(['code' => 'P2', 'title' => 'Brand new program'])],
            ['id' => 3, 'cells' => $cells(['code' => 'P3', 'title' => 'Brand new program'])],
            ['id' => 4, 'cells' => $cells(['code' => 'P4', 'title' => 'Odd industry', 'industry' => 'Space mining'])],
            ['id' => 5, 'cells' => $cells(['code' => 'P5', 'title' => 'Long round'])],
        ],
        'rounds' => [['id' => 1, 'cells' => ['program' => 'P5', 'round' => '1', 'name' => str_repeat('x', 101)]]],
        'fields' => [],
    ];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many'])->json('data.id');
    $this->actingAsOrgOwner()->patchJson(IMPORTS."/$id", ['data' => $data])->assertOk();

    $res = $this->actingAsOrgOwner()->getJson(IMPORTS."/$id/check")->assertOk();
    $by = collect($res->json('data.programs'))->keyBy('code');
    expect($by['P1']['status'])->toBe('decide')->and($by['P1']['existing_program_id'])->toBe($this->program->id)
        ->and($by['P2']['status'])->toBe('ready')
        ->and($by['P3']['issues'][0]['key'])->toBe('titleTwice')
        ->and($by['P4']['issues'][0]['key'])->toBe('unknownIndustry')
        ->and($by['P5']['issues'][0]['key'])->toBe('roundNameTooLong')
        ->and($res->json('data.totals'))->toBe(['ready' => 1, 'decide' => 2, 'problems' => 2])
        ->and(Program::count())->toBe($programsBefore);
});

it('asks the AI for fixes and keeps only changes to known columns', function () {
    config(['services.import_ai.api_key' => 'test-key', 'services.import_ai.base_url' => 'https://ai.test/v1']);
    \Illuminate\Support\Facades\Http::fake(['ai.test/*' => \Illuminate\Support\Facades\Http::response(['choices' => [['message' => ['content' => json_encode(['fixes' => [
        ['key' => 'fields:1', 'changes' => ['fileKinds' => 'Document; Photo', 'password' => 'x'], 'reason' => 'Image needs Photo.'],
        ['key' => 'nope', 'changes' => ['text' => 'x']],
    ]])]]]])]);
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many'])->json('data.id');

    $res = $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/ai-fix", ['problems' => [
        ['key' => 'fields:1', 'table' => 'fields', 'message' => 'The file type "Image" does not fit the file category you chose.', 'cells' => ['fileKinds' => 'Document', 'fileTypes' => 'PDF; Image']],
    ]])->assertOk();
    expect($res->json('data.fixes'))->toBe([['key' => 'fields:1', 'changes' => ['fileKinds' => 'Document; Photo'], 'reason' => 'Image needs Photo.']])->and($res->json('data.reply'))->toBe('');

    config(['services.import_ai.api_key' => null]);
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/ai-fix", ['problems' => [['key' => 'k', 'table' => 'fields', 'message' => 'm', 'cells' => ['text' => 'x']]]])->assertStatus(503);
});

it('creates the programs as hidden drafts, one at a time, and carries on after a failure', function () {
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $program = fn (string $title, array $o = []) => $o + ['program_title' => $title, 'industry' => 'Grants', 'total_program_amount' => 5000000, 'funding_per_business' => 250000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'multi_round', 'total_rounds' => 2, 'use_match_score' => true, 'match_score_limit' => 50];
    $field = fn (array $o = []) => $o + ['question_text' => 'What is your business name?', 'question_type' => 'short_answer', 'is_required' => true, 'options' => null, 'use_in_match_score' => false];
    $plan = [
        ['code' => 'P1', 'program' => $program('Imported One'), 'rounds' => [
            ['round_number' => 1, 'round_name' => 'Application', 'questions' => [
                $field(),
                $field(['question_text' => 'Upload your certificate', 'question_type' => 'file_upload', 'options' => ['document', 'photo', 'type:pdf', 'type:image', 'max_files:2']]),
                $field(['question_text' => 'Employees?', 'question_type' => 'number', 'use_in_match_score' => true, 'match_points' => 30, 'match_answers' => ['min' => 3]]),
            ]],
            ['round_number' => 2, 'round_name' => 'Pitch', 'open_date' => '2026-03-10', 'close_date' => '2026-03-31', 'review_method' => 'ai_assisted', 'advancement_mode' => 'fixed_quota', 'max_advancing' => 20, 'questions' => [$field()]],
        ]],
        // 120 points is more than the app allows: this program fails, the other is still created
        ['code' => 'P2', 'program' => $program('Imported Two'), 'rounds' => [
            ['round_number' => 1, 'round_name' => 'Application', 'questions' => [$field(['question_type' => 'number', 'use_in_match_score' => true, 'match_points' => 120, 'match_answers' => ['min' => 1]])]],
        ]],
    ];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many'])->json('data.id');

    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan])->assertOk()->assertJsonPath('data.status', 'importing');
    $import = ProgramImport::find($id);
    expect($import->status)->toBe('partial')
        ->and($import->progress['created'])->toBe(1)->and($import->progress['failed'])->toBe(1)
        ->and($import->progress['results']['P2']['message'])->toContain('field 1');

    $created = Program::find($import->progress['results']['P1']['program_id']);
    expect($created->status)->toBe('draft')->and($created->visible)->toBeFalse()->and($created->organization_id)->toBe($this->organization->id)
        ->and($created->rounds()->count())->toBe(2)->and($created->wallet()->exists())->toBeTrue()
        ->and(Program::where('program_title', 'Imported Two')->exists())->toBeFalse();
    $round1 = $created->rounds()->where('round_number', 1)->first();
    expect($round1->open_date->toDateString())->toBe('2026-01-10')
        ->and(\App\Models\Programs\Rounds\RoundCustomQuestion::where("round_id", $round1->id)->count())->toBe(3)
        ->and(\App\Models\Programs\Rounds\RoundCustomQuestion::where("round_id", $round1->id)->where('question_type', 'file_upload')->first()->options)->toBe(['document', 'photo', 'type:pdf', 'type:image', 'max_files:2'])
        ->and($created->rounds()->where('round_number', 2)->first()->max_advancing)->toBe(20);

    // Fixed and started again: P1 is not created twice, P2 is created now
    $plan[1]['rounds'][0]['questions'][0]['match_points'] = 40;
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan])->assertOk();
    expect(ProgramImport::find($id)->status)->toBe('completed')
        ->and(Program::where('program_title', 'Imported One')->count())->toBe(1)
        ->and(Program::where('program_title', 'Imported Two')->count())->toBe(1);
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan])->assertStatus(422);
});

// Every email really sent to the people in the import (the array mailer keeps them; Mail::fake does not see emails
// sent with a view). The "import finished" email to the person who started it is left out: it is theirs, not the
// applicants', coaches' or suppliers' (its own test checks it)
function sentEmails(): array
{
    return collect(\Illuminate\Support\Facades\Mail::mailer('array')->getSymfonyTransport()->messages())
        ->map(fn ($m) => ['to' => $m->getEnvelope()->getRecipients()[0]->getAddress(), 'subject' => $m->getOriginalMessage()->getSubject(), 'body' => $m->getOriginalMessage()->getHtmlBody()])
        ->reject(fn ($m) => $m['subject'] === 'Your program import is finished')->values()->all();
}

it('brings in applicants as submitted, with quiet accounts, their answers and their rounds, then tells them only when asked', function () {
    config(['mail.default' => 'array']);
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $existing = User::factory()->create(['email' => 'known@example.org', 'user_type_id' => 1]);
    $field = fn (array $o = []) => $o + ['question_text' => 'Business name', 'question_type' => 'short_answer', 'is_required' => true, 'options' => null, 'use_in_match_score' => false];
    $person = fn (string $email, array $answers, array $o = []) => $o + ['email' => $email, 'first_name' => 'Amina', 'last_name' => 'Otieno', 'phone' => '+254712345678', 'business_name' => null, 'amount_requested' => null, 'submitted_on' => '2026-02-01', 'answers' => $answers];
    $plan = [['code' => 'P1', 'program' => ['program_title' => 'Review Import', 'industry' => 'Grants', 'total_program_amount' => 5000000, 'funding_per_business' => 250000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'multi_round', 'total_rounds' => 2, 'use_match_score' => true, 'match_score_limit' => 50],
        'rounds' => [
            ['round_number' => 1, 'round_name' => 'Application', 'status' => 'finished', 'rubric_mode' => 'weighted', 'assignment_type' => 'owner_only', 'assignment_method' => 'manual', 'min_reviewers_required' => 1,
                'scoring_criteria' => [['name' => 'Viability', 'description' => 'Can it last?', 'weight' => 60, 'scoreRange' => '0-10'], ['name' => 'Impact', 'description' => '', 'weight' => 40, 'scoreRange' => '0-5']],
                'questions' => [
                $field(),
                $field(['question_text' => 'Sectors', 'question_type' => 'checkbox', 'options' => ['Agri', 'Tech']]),
                $field(['question_text' => 'Employees', 'question_type' => 'number', 'use_in_match_score' => true, 'match_points' => 30, 'match_answers' => ['min' => 3]]),
            ], 'applications' => [
                $person('New.Person@Example.org', [['question' => 0, 'response' => 'Amina Farms'], ['question' => 1, 'response' => '["Agri"]'], ['question' => 2, 'response' => '5']], ['amount_requested' => 120000]),
                $person('known@example.org', [['question' => 0, 'response' => 'Known Ltd'], ['question' => 2, 'response' => '1']], ['business_name' => 'Known Ltd']),
            ]],
            ['round_number' => 2, 'round_name' => 'Pitch', 'status' => 'in_review', 'questions' => [$field(['question_text' => 'Pitch summary', 'question_type' => 'long_text'])], 'applications' => [
                $person('new.person@example.org', [['question' => 0, 'response' => 'We grow food.']], ['submitted_on' => '2026-03-05']),
            ]],
        ]]];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'review'])->json('data.id');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan])->assertOk();

    $import = ProgramImport::find($id);
    expect($import->status)->toBe('completed')->and($import->progress['results']['P1']['applicants'])->toBe(2);
    $program = Program::find($import->progress['results']['P1']['program_id']);
    [$round1, $round2] = $program->rounds()->orderBy('round_number')->get()->all();

    // The round is set up for review as Round Config would save it; reviewed by the owner, so the Business Coaches step is done
    expect($round1->rubric_mode)->toBe('weighted')->and(collect($round1->scoring_criteria)->pluck('weight', 'name')->all())->toBe(['Viability' => 60, 'Impact' => 40])
        ->and($round1->scoring_criteria[1]['scoreRange'])->toBe('0-5')->and($round1->assignment_type)->toBe('owner_only')
        ->and($round1->confirmed_steps)->toBe(['setup', 'questions', 'reviewers'])->and($round2->scoring_criteria)->toBeNull();

    // A quiet account for the new person: an applicant, no password; the known applicant keeps their own account
    $quiet = User::where('email', 'new.person@example.org')->first();
    expect($quiet)->not->toBeNull()->and((int) $quiet->user_type_id)->toBe(1)->and($quiet->getAttributes()['password'])->toBeNull()
        ->and($quiet->first_name)->toBe('Amina')->and(\App\Models\Auth\UserSetting::where('user_id', $quiet->id)->exists())->toBeTrue()
        ->and(User::where('email', 'known@example.org')->count())->toBe(1);

    $apps = \App\Models\Programs\ProgramApplication::where('program_id', $program->id)->get()->keyBy('email');
    expect($apps)->toHaveCount(2);
    $a = $apps['new.person@example.org'];
    // Round 1 is finished and round 2 is being reviewed (published, as the app runs it)
    expect($round1->status)->toBe('finalized')->and($round2->status)->toBe('published');
    // Amina moved on from round 1 (approved) and waits in round 2; the known applicant was not selected in round 1
    expect($a->user_id)->toBe($quiet->id)->and($a->status)->toBe('approved')->and($a->round_status)->toBe('submitted')
        ->and($a->current_round_id)->toBe($round2->id)->and((float) $a->total_amount_requested)->toBe(120000.0)
        ->and($a->startup_name)->toBe('Amina Otieno')
        ->and($apps['known@example.org']->user_id)->toBe($existing->id)->and($apps['known@example.org']->current_round_id)->toBe($round1->id)
        ->and($apps['known@example.org']->round_status)->toBe('not_selected')->and($apps['known@example.org']->status)->toBe('pending')
        ->and(\App\Models\ApplicationRoundHistory::where('application_id', $apps['known@example.org']->id)->value('outcome'))->toBe('not_selected')
        ->and((float) $apps['known@example.org']->total_amount_requested)->toBe(250000.0);

    $history = \App\Models\ApplicationRoundHistory::where('application_id', $a->id)->orderBy('round_number')->get();
    expect($history)->toHaveCount(2)->and($history[0]->outcome)->toBe('advanced')->and($history[1]->outcome)->toBe('in_progress')
        ->and($history[1]->submitted_at->toDateString())->toBe('2026-03-05');
    $answers = \App\Models\Programs\Rounds\ApplicationRoundResponse::where('application_id', $a->id)->pluck('response', 'round_id');
    expect(\App\Models\Programs\Rounds\ApplicationRoundResponse::where('application_id', $a->id)->count())->toBe(4)
        ->and($answers[$round2->id])->toBe('We grow food.');

    // Nothing was sent by the import itself; the page offers to tell the 2 people
    expect(sentEmails())->toBe([]);
    $view = $this->actingAsOrgOwner()->getJson(IMPORTS."/$id")->assertOk();
    expect($view->json('data.progress.notify.to_tell'))->toBe(2)->and($view->json('data.progress.results.P1.application_ids'))->toBeNull();

    // Let applicants know: one email each; the quiet account gets a password that works, the known one keeps theirs
    $existingHash = $existing->fresh()->getAttributes()['password'];
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/notify-applicants")->assertOk();
    $import = ProgramImport::find($id);
    expect($import->progress['notify']['status'])->toBe('sent')->and($import->progress['notify']['sent'])->toBe(2);
    $mails = collect(sentEmails())->keyBy('to');
    expect($mails)->toHaveCount(2)->and($mails['new.person@example.org']['body'])->toContain('Review Import')->toContain('Password')
        ->and($mails['known@example.org']['body'])->not->toContain('Password');
    preg_match('/font-family:monospace;font-size:16px">([^<]+)</', $mails['new.person@example.org']['body'], $m);
    expect(\Illuminate\Support\Facades\Hash::check(html_entity_decode($m[1]), $quiet->fresh()->password))->toBeTrue()
        ->and($existing->fresh()->getAttributes()['password'])->toBe($existingHash);

    // Nobody is told twice
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/notify-applicants")->assertStatus(422);
    expect(sentEmails())->toHaveCount(2)
        ->and($this->actingAsOrgOwner()->getJson(IMPORTS."/$id")->json('data.progress.notify.to_tell'))->toBe(0);
});

it('refuses a Start at review import without applicants, and weights that do not add up to 100', function () {
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $program = ['program_title' => 'No Applicants', 'industry' => 'Grants', 'total_program_amount' => 5000000, 'funding_per_business' => 250000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'single_round', 'use_match_score' => false];
    $round = ['round_number' => 1, 'round_name' => 'Application', 'questions' => [['question_text' => 'Business name', 'question_type' => 'short_answer', 'is_required' => true]]];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'review'])->json('data.id');

    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => [['code' => 'P1', 'program' => $program, 'rounds' => [$round]]]])
        ->assertStatus(422)->assertJsonFragment(['message' => 'Every program in a "Start at review" import needs its applicants. Bring programs without applicants in with "Set up a program".']);

    // Chosen to come in "set up only": a new program in this same import, without applicants; its rounds stay drafts
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => [['code' => 'P1', 'setup_only' => true, 'program' => array_merge($program, ['program_title' => 'Set Up Only']), 'rounds' => [$round + ['status' => 'in_review']]]]])->assertOk();
    $setUp = Program::where('program_title', 'Set Up Only')->first();
    expect($setUp)->not->toBeNull()->and($setUp->status)->toBe('draft')->and($setUp->rounds()->first()->status)->toBe('draft')
        ->and($setUp->rounds()->first()->confirmed_steps)->toBe(['setup', 'questions'])
        ->and(\App\Models\Programs\ProgramApplication::where('program_id', $setUp->id)->count())->toBe(0);
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'review'])->json('data.id');

    $round['applications'] = [['email' => 'a@example.org', 'answers' => [['question' => 0, 'response' => 'A Ltd']]]];
    $round['scoring_criteria'] = [['name' => 'Viability', 'weight' => 50, 'scoreRange' => '0-10']];
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => [['code' => 'P1', 'program' => $program, 'rounds' => [$round]]]])->assertOk();
    expect(ProgramImport::find($id)->progress['results']['P1']['message'])->toContain('add up to 100')
        ->and(Program::where('program_title', 'No Applicants')->exists())->toBeFalse();
});

it('adds Business Coaches like Round Config, invites them only when asked, and refuses emails that do not fit', function () {
    config(['mail.default' => 'array']);
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $role = \App\Models\Auth\Role::forceCreate(['name' => 'editor_import_test', 'access_types' => '[]']);
    // The team role new team members join with (seeded on every server)
    \App\Models\Auth\Role::where('name', 'internal_reviewer')->exists() || \App\Models\Auth\Role::forceCreate(['name' => 'internal_reviewer', 'access_types' => '[]']);
    $teammate = User::factory()->create(['email' => 'team@example.org', 'user_type_id' => 4, 'organization_id' => $this->organization->id]);
    $provider = User::factory()->create(['email' => 'provider@example.org', 'user_type_id' => 3]);
    \App\Models\Auth\OrganizationUserRole::create(['organization_id' => $this->organization->id, 'user_id' => $teammate->id, 'role_id' => $role->id, 'status' => 'active']);
    $program = ['program_title' => 'Coached Review', 'industry' => 'Grants', 'total_program_amount' => 5000000, 'funding_per_business' => 250000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'single_round', 'use_match_score' => false];
    $coach = fn (string $email, array $o = []) => $o + ['email' => $email, 'first_name' => 'Wanjiru', 'last_name' => 'Kamau', 'reviewer_type' => 'external', 'reviewer_fee' => 0, 'reviewer_payment_basis' => 'flat_round_fee', 'minimum_apps_to_get_paid' => null, 'max_apps_assigned' => null, 'assigned_percentage' => null];
    $round = ['round_number' => 1, 'round_name' => 'Application', 'review_period_end' => now()->addMonth()->toDateString(), 'assignment_type' => 'both', 'assignment_method' => 'round_robin',
        'questions' => [['question_text' => 'Business name', 'question_type' => 'short_answer', 'is_required' => true]],
        'applications' => [['email' => 'applicant@example.org', 'answers' => [['question' => 0, 'response' => 'A Ltd']]]],
        'coaches' => [
            $coach('new.coach@example.org', ['reviewer_fee' => 5000, 'minimum_apps_to_get_paid' => 3, 'max_apps_assigned' => 10, 'expertise_tags' => ['Agriculture', 'Finance']]),
            $coach('team@example.org', ['reviewer_type' => 'internal', 'reviewer_fee' => 9999]),
            $coach('provider@example.org', ['first_name' => null, 'last_name' => null, 'reviewer_fee' => 8000, 'reviewer_payment_basis' => 'per_application']),
            // New to Tujitume, listed as a team member: joins the team as a reviewer, waiting to accept
            $coach('new.team@example.org', ['reviewer_type' => 'internal', 'first_name' => 'Neema', 'last_name' => 'Wafula']),
        ]];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'review'])->json('data.id');

    // Step 4 already says which emails do not fit: an applicant's email cannot be a coach, a stranger is not on the team
    ProgramImport::whereKey($id)->update(['data' => json_encode(['version' => 2, 'programs' => [['id' => 1, 'cells' => ['code' => 'P1', 'title' => 'Coached Review', 'industry' => 'Grants']]],
        'coaches' => [['id' => 1, 'cells' => ['program' => 'P1', 'round' => '1', 'email' => $this->applicantUser->email, 'kind' => 'coach']], ['id' => 2, 'cells' => ['program' => 'P1', 'round' => '1', 'email' => $this->applicantUser->email, 'kind' => 'team']],
            ['id' => 3, 'cells' => ['program' => 'P1', 'round' => '1', 'email' => 'provider@example.org', 'kind' => 'coach']], ['id' => 4, 'cells' => ['program' => 'P1', 'round' => '1', 'email' => 'nameless@example.org', 'kind' => 'coach']],
            ['id' => 5, 'cells' => ['program' => 'P1', 'round' => '1', 'email' => 'named@example.org', 'kind' => 'coach', 'first_name' => 'A', 'last_name' => 'B']]]])]);
    $issues = collect($this->actingAsOrgOwner()->getJson(IMPORTS."/$id/check")->assertOk()->json('data.programs.0.issues'))->pluck('key');
    // The review page asks the same while fixing: who each email is
    $who = $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/people", ['people' => [['email' => 'Team@Example.org', 'kind' => 'team'], ['email' => 'new.team@example.org', 'kind' => 'team'], ['email' => $this->applicantUser->email, 'kind' => 'coach'], ['email' => 'provider@example.org', 'kind' => 'coach']]])->assertOk()->json('data');
    expect($who['team:team@example.org']['as'])->toBe('team')->and($who['team:new.team@example.org']['as'])->toBe('new_team')
        ->and($who['coach:'.strtolower($this->applicantUser->email)]['problem'])->toBe('coachEmailTaken')
        ->and($who['coach:provider@example.org'])->toBe(['as' => 'provider', 'problem' => null, 'listed' => false]);
    // A service provider needs a fee; someone new needs a name (and is told they get an account)
    expect($issues)->toContain('coachEmailTaken')->toContain('coachNotInTeam')->toContain('coachProviderFee')->toContain('coachNameNeeded')->toContain('coachNew');

    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => [['code' => 'P1', 'program' => $program, 'rounds' => [$round]]]])->assertOk();
    $import = ProgramImport::find($id);
    expect($import->status)->toBe('completed');
    $created = Program::find($import->progress['results']['P1']['program_id'])->rounds()->first();

    // The new coach: a quiet account in the organization; both wait to accept, with a work order; the team member has no fee
    $newCoach = User::where('email', 'new.coach@example.org')->first();
    expect((int) $newCoach->user_type_id)->toBe(6)->and((int) $newCoach->organization_id)->toBe($this->organization->id)->and($newCoach->getAttributes()['password'])->toBeNull()->and($newCoach->invited_at)->toBeNull();
    $places = \App\Models\Programs\Rounds\RoundReviewer::where('round_id', $created->id)->get()->keyBy('user_id');
    // Expertise tags are kept as Round Config keeps them
    expect(json_decode($places[$newCoach->id]->expertise_tags, true))->toBe(['Agriculture', 'Finance']);

    // The service provider reviews as a paid third party, and is now on the organization's provider list
    expect(\App\Models\Organizations\OrganizationProvider::has($this->organization->id, $provider->id))->toBeTrue()
        ->and($places[$provider->id]->fee_type)->toBe('per_application')->and((float) $places[$provider->id]->reviewer_fee)->toBe(8000.0);
    $newTeam = User::where('email', 'new.team@example.org')->first();
    expect((int) $newTeam->user_type_id)->toBe(4)->and((int) $newTeam->organization_id)->toBe($this->organization->id)
        ->and(\App\Models\Auth\OrganizationUserRole::where('user_id', $newTeam->id)->value('status'))->toBe('pending')
        ->and($places[$newTeam->id]->reviewer_type)->toBe('internal');
    expect($places)->toHaveCount(4)->and($places[$newCoach->id]->acceptance_status)->toBe('pending')->and((float) $places[$newCoach->id]->reviewer_fee)->toBe(5000.0)
        ->and((float) $places[$teammate->id]->reviewer_fee)->toBe(0.0)->and($places[$teammate->id]->reviewer_type)->toBe('internal')
        ->and(\App\Models\ReviewerOrder::where('round_id', $created->id)->where('reviewer_id', $newCoach->id)->value('minimum_apps_to_get_paid'))->toBe(3)
        ->and($created->confirmed_steps)->toContain('reviewers')
        ->and(sentEmails())->toBe([])
        ->and($this->actingAsOrgOwner()->getJson(IMPORTS."/$id")->json('data.progress.invite.to_tell'))->toBe(4);

    // Invite Business Coaches: the new coach gets the invitation; the team member is told about the round; never twice
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/invite-coaches")->assertOk();
    $mails = collect(sentEmails())->groupBy('to');
    expect(ProgramImport::find($id)->progress['invite']['status'])->toBe('sent')
        ->and($mails['new.coach@example.org'][0]['subject'])->toContain('invited to join Tujitume')
        ->and($newCoach->fresh()->invitation_token_hash)->not->toBeNull()
        // The new team member gets the team invitation, and their place on the team now has its token
        ->and($mails['new.team@example.org'][0]['subject'])->toContain("You've been invited to join")
        ->and(\App\Models\Auth\OrganizationUserRole::where('user_id', $newTeam->id)->value('invitation_token_hash'))->not->toBeNull()
        ->and(\App\Models\Communication\Notifications::where("receiver_id", $teammate->id)->exists() || $mails->has('team@example.org'))->toBeTrue();
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/invite-coaches")->assertStatus(422);
});

it('brings in a program at finalization: finished rounds with their decisions and paid coaches, the round reviewed with its scores', function () {
    config(['mail.default' => 'array']);
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $program = ['program_title' => 'Reviewed Program', 'industry' => 'Grants', 'total_program_amount' => 5000000, 'funding_per_business' => 250000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'multi_round', 'total_rounds' => 2, 'use_match_score' => false];
    $criteria = [['name' => 'Viability', 'description' => '', 'weight' => 50, 'scoreRange' => '0-10'], ['name' => 'Impact', 'description' => '', 'weight' => 50, 'scoreRange' => '0-10']];
    $coach = fn (string $email, array $o = []) => $o + ['email' => $email, 'first_name' => 'Coach', 'last_name' => 'One', 'reviewer_type' => 'external', 'reviewer_fee' => 1000, 'reviewer_payment_basis' => 'per_application', 'minimum_apps_to_get_paid' => null, 'max_apps_assigned' => null, 'assigned_percentage' => null, 'paid' => false];
    $person = fn (string $email) => ['email' => $email, 'first_name' => 'A', 'last_name' => 'B', 'answers' => [['question' => 0, 'response' => 'X Ltd']]];
    $score = fn (string $applicant, ?string $reviewer, float $v, float $i) => ['applicant' => $applicant, 'reviewer' => $reviewer, 'criterion_scores' => [['criterion' => 'Viability', 'score' => $v], ['criterion' => 'Impact', 'score' => $i]], 'scored_on' => '2026-03-01'];
    $field = [['question_text' => 'Business name', 'question_type' => 'short_answer', 'is_required' => true]];
    $plan = [['code' => 'P1', 'program' => $program, 'rounds' => [
        ['round_number' => 1, 'round_name' => 'Application', 'status' => 'finished', 'assignment_type' => 'external', 'scoring_criteria' => $criteria, 'questions' => $field,
            'applications' => [$person('go@example.org'), $person('stop@example.org')],
            'coaches' => [$coach('paid.coach@example.org', ['paid' => true])],
            'scores' => [$score('go@example.org', 'paid.coach@example.org', 9, 8), $score('stop@example.org', 'paid.coach@example.org', 3, 2)]],
        ['round_number' => 2, 'round_name' => 'Pitch', 'status' => 'in_review', 'review_period_end' => now()->addMonth()->toDateString(), 'assignment_type' => 'external', 'advancement_mode' => 'score_threshold', 'score_threshold' => 60,
            'scoring_criteria' => $criteria, 'questions' => $field,
            'applications' => [$person('go@example.org')],
            'coaches' => [$coach('busy.coach@example.org')],
            'scores' => [$score('go@example.org', 'busy.coach@example.org', 8, 6), $score('go@example.org', null, 6, 6)]],
    ]]];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'finalization'])->json('data.id');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan])->assertOk();
    $import = ProgramImport::find($id);
    expect($import->status)->toBe('completed', json_encode($import->progress['results']));
    [$r1, $r2] = Program::find($import->progress['results']['P1']['program_id'])->rounds()->orderBy('round_number')->get()->all();
    $apps = \App\Models\Programs\ProgramApplication::where('program_id', $r1->program_id)->get()->keyBy('email');
    $history = fn ($app, $round) => \App\Models\ApplicationRoundHistory::where('application_id', $app->id)->where('round_id', $round->id)->first();

    // Round 1 is finished: decisions made, averages kept; the scores and the coach's paid work are there
    expect($r1->status)->toBe('finalized')->and($r2->status)->toBe('published')
        ->and($history($apps['go@example.org'], $r1)->outcome)->toBe('advanced')->and((float) $history($apps['go@example.org'], $r1)->average_score)->toBe(85.0)
        ->and($apps['stop@example.org']->round_status)->toBe('not_selected')->and((float) $history($apps['stop@example.org'], $r1)->average_score)->toBe(25.0);
    $paidCoach = User::where('email', 'paid.coach@example.org')->first();
    $order1 = \App\Models\ReviewerOrder::where('round_id', $r1->id)->where('reviewer_id', $paidCoach->id)->first();
    expect($order1->work_status)->toBe('approved')->and($order1->payment_status)->toBe('completed')->and((float) $order1->fee_amount)->toBe(2000.0)
        ->and(\App\Models\Programs\Rounds\RoundReviewer::where('round_id', $r1->id)->value('acceptance_status'))->toBe('accepted')
        ->and(\App\Models\Programs\Rounds\ReviewerApplicationAssignment::where('round_id', $r1->id)->where('status', 'completed')->count())->toBe(2);

    // Round 2 is being reviewed: two scores (the coach's and the owner's), the application scored with its average;
    // the coach's work is delivered for the owner to approve and pay in Tujitume
    $go = $apps['go@example.org'];
    expect($go->current_round_id)->toBe($r2->id)->and($go->round_status)->toBe('scored')->and((float) $go->average_score)->toBe(65.0)->and((bool) $go->is_eligible_to_advance)->toBeTrue()
        ->and(\App\Models\Programs\Rounds\ApplicationScore::where('round_id', $r2->id)->count())->toBe(2);
    $busy = \App\Models\ReviewerOrder::where('round_id', $r2->id)->where('reviewer_id', User::where('email', 'busy.coach@example.org')->value('id'))->first();
    expect($busy->work_status)->toBe('delivered')->and($busy->payment_status)->toBe('unpaid')->and((float) $busy->fee_amount)->toBe(1000.0)
        ->and(sentEmails())->toBe([]);
});

it('picks a stopped import up again on the next progress check, and leaves a running one alone', function () {
    \Illuminate\Support\Facades\Bus::fake();
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'new', 'stage' => 'setup'])->assertCreated()->json('data.id');
    $import = ProgramImport::find($id);
    $import->forceFill(['status' => 'importing', 'plan' => [['code' => 'P1', 'rounds' => []]], 'progress' => ['total' => 1, 'done' => 0, 'created' => 0, 'failed' => 0, 'results' => []]])->save();

    // Just saved: something is working on it
    $this->actingAsOrgOwner()->getJson(IMPORTS."/$id?light=1")->assertOk();
    \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\RunProgramImportJob::class);
    \Illuminate\Support\Facades\Bus::assertNotDispatchedAfterResponse(\App\Jobs\RunProgramImportJob::class);

    // Nothing saved for a while (the run was stopped): the check starts it again
    ProgramImport::whereKey($id)->update(['updated_at' => now()->subMinutes(5)]);
    $this->actingAsOrgOwner()->getJson(IMPORTS."/$id?light=1")->assertOk();
    config('queue.default') === 'sync'
        ? \Illuminate\Support\Facades\Bus::assertDispatchedAfterResponse(\App\Jobs\RunProgramImportJob::class)
        : \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\RunProgramImportJob::class);
});

it('brings in a program at funding setup: one finished round without fields or criteria, the awardees awarded for the deal room', function () {
    config(['mail.default' => 'array']);
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $program = ['program_title' => 'Awarded Program', 'industry' => 'Grants', 'total_program_amount' => 5000000, 'funding_per_business' => 250000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'single_round', 'total_rounds' => 1, 'use_match_score' => false];
    $person = fn (string $email, bool $awarded, int $score) => ['email' => $email, 'first_name' => 'A', 'last_name' => 'B', 'awarded' => $awarded, 'score' => $score, 'answers' => []];
    $plan = [['code' => 'P1', 'program' => $program, 'rounds' => [
        ['round_number' => 1, 'round_name' => 'Application', 'status' => 'finished', 'assignment_type' => 'owner_only', 'scoring_criteria' => [], 'questions' => [],
            'applications' => [$person('winner@example.org', true, 88), $person('runner@example.org', false, 41)]],
    ]]];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'funding'])->json('data.id');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan])->assertOk();
    $import = ProgramImport::find($id);
    expect($import->status)->toBe('completed', json_encode($import->progress['results']));

    $created = Program::find($import->progress['results']['P1']['program_id']);
    $round = $created->rounds()->first();
    $apps = \App\Models\Programs\ProgramApplication::where('program_id', $created->id)->get()->keyBy('email');
    $history = fn ($app) => \App\Models\ApplicationRoundHistory::where('application_id', $app->id)->where('round_id', $round->id)->first();

    // The awardee is awarded (in the deal room for funding setup), as finalizing the last round leaves them
    expect($created->status)->toBe('awarded')->and((bool) $created->visible)->toBeFalse()->and($round->status)->toBe('finalized')
        ->and($apps['winner@example.org']->status)->toBe('awarded')->and($apps['winner@example.org']->round_status)->toBe('advanced')
        ->and($history($apps['winner@example.org'])->outcome)->toBe('awarded')->and((float) $history($apps['winner@example.org'])->average_score)->toBe(88.0)
        ->and($apps['runner@example.org']->status)->not->toBe('awarded')->and($apps['runner@example.org']->round_status)->toBe('not_selected')
        ->and((float) $apps['runner@example.org']->average_score)->toBe(41.0);
});

it('brings in a program at disbursement: the awardees with their funding set up, milestone 1 ready to release', function () {
    config(['mail.default' => 'array']);
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    // A supplier the owner already has is reused (same email), not added twice
    $known = \App\Models\Programs\SupplierDirectory::create(['added_by' => $this->orgUser->id, 'legal_name' => 'Agro Supplies Ltd', 'phone' => '0700000001', 'email' => 'sales@agro.example', 'payment_method' => 'mpesa_paybill', 'mpesa_paybill_number' => '222111', 'mpesa_paybill_account' => 'AGRO', 'is_active' => true]);
    // Hybrid: one awardee is paid through a supplier, the other straight to them
    $program = ['program_title' => 'Disbursing Program', 'industry' => 'Grants', 'disbursement_type' => 'hybrid', 'total_program_amount' => 5000000, 'funding_per_business' => 250000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'single_round', 'total_rounds' => 1, 'use_match_score' => false];
    $person = fn (string $email) => ['email' => $email, 'first_name' => 'A', 'last_name' => 'B', 'awarded' => true, 'answers' => []];
    $supplier = ['name' => 'Agro Supplies Ltd', 'phone' => '0700000001', 'email' => 'SALES@agro.example', 'payment_method' => 'mpesa_paybill', 'account_number' => '222111', 'account_reference' => 'AGRO'];
    $plan = [['code' => 'P1', 'program' => $program, 'rounds' => [
        ['round_number' => 1, 'round_name' => 'Application', 'status' => 'finished', 'assignment_type' => 'owner_only', 'scoring_criteria' => [], 'questions' => [],
            'applications' => [$person('farmer@example.org'), $person('maker@example.org')]],
    ], 'milestones' => [
        ['awardee' => 'farmer@example.org', 'order' => 1, 'title' => 'Seeds', 'description' => 'Buy seed', 'amount' => 60000, 'paid_to' => 'supplier', 'supplier' => $supplier, 'mprv' => false,
            'budget' => [['item' => 'Maize seed', 'unit_cost' => 2000, 'quantity' => 20], ['item' => 'Fertiliser', 'unit_cost' => 5000, 'quantity' => 4]]],
        ['awardee' => 'farmer@example.org', 'order' => 2, 'title' => 'Irrigation', 'description' => 'Drip kit', 'amount' => 90000, 'paid_to' => 'supplier', 'supplier' => $supplier + ['email' => 'sales@agro.example'], 'budget' => []],
        ['awardee' => 'maker@example.org', 'order' => 1, 'title' => 'Workshop', 'description' => 'Tools', 'amount' => 100000, 'paid_to' => 'applicant', 'mprv' => true, 'budget' => []],
    ]]];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'disbursement'])->json('data.id');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan])->assertOk();
    $import = ProgramImport::find($id);
    expect($import->status)->toBe('completed', json_encode($import->progress['results']));

    $apps = \App\Models\Programs\ProgramApplication::where('program_id', $import->progress['results']['P1']['program_id'])->get()->keyBy('email');
    $farmer = $apps['farmer@example.org'];
    $ms = \App\Models\Programs\ProgramMilestone::where('app_id', $farmer->id)->orderBy('sequence_order')->get();
    // Funding set up: active milestones in order, the first ready to release, the next locked; totals from the milestones
    expect($farmer->status)->toBe('awarded')->and($farmer->funding_setup_status)->toBe('completed')
        ->and((float) $farmer->total_amount_requested)->toBe(150000.0)->and((float) $farmer->awarded_amount)->toBe(150000.0)
        ->and($ms->pluck('sequence_order')->all())->toBe([1, 2])->and($ms[0]->is_template)->toBeFalse()
        ->and($ms[0]->fund_release_status)->toBe('approved')->and($ms[1]->fund_release_status)->toBe('locked')->and($ms[0]->status)->toBe('pending');
    // The known supplier is reused on both milestones, paid direct; the budget lines are on the milestone's supplier
    $link = \App\Models\Programs\MilestoneSupplier::where('milestone_id', $ms[0]->id)->first();
    expect($link->supplier_id)->toBe($known->id)->and($link->payment_route)->toBe('direct_to_supplier')->and((float) $link->quoted_amount)->toBe(60000.0)
        ->and(\App\Models\Programs\SupplierDirectory::where('added_by', $this->orgUser->id)->count())->toBe(2)
        ->and(\App\Models\Programs\MilestoneBudgetItem::where('milestone_id', $ms[0]->id)->sum('total_cost'))->toEqual(60000);
    // Paid straight to the applicant, with a pre-release check: locked until the check is passed
    $maker = \App\Models\Programs\ProgramMilestone::where('app_id', $apps['maker@example.org']->id)->first();
    expect($maker->fund_release_status)->toBe('locked')->and((bool) $maker->mprv_required)->toBeTrue()
        ->and(\App\Models\Programs\MilestoneSupplier::where('milestone_id', $maker->id)->value('payment_route'))->toBe('direct_to_applicant');
});

it('records who added a supplier, sends suppliers nothing while importing, and tells them when asked', function () {
    config(['mail.default' => 'array']);
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $program = ['program_title' => 'Supplier Program', 'industry' => 'Grants', 'total_program_amount' => 5000000, 'funding_per_business' => 250000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'single_round', 'total_rounds' => 1, 'use_match_score' => false];
    $plan = [['code' => 'P1', 'program' => $program, 'rounds' => [
        ['round_number' => 1, 'round_name' => 'Application', 'status' => 'finished', 'assignment_type' => 'owner_only', 'scoring_criteria' => [], 'questions' => [],
            'applications' => [['email' => 'farmer@example.org', 'first_name' => 'A', 'last_name' => 'B', 'awarded' => true, 'answers' => []]]],
    ], 'milestones' => [
        ['awardee' => 'farmer@example.org', 'order' => 1, 'title' => 'Seeds', 'description' => 'Buy seed', 'amount' => 60000, 'paid_to' => 'supplier', 'supplier_by' => 'applicant',
            'supplier' => ['name' => 'New Seeds Co', 'phone' => '0711000000', 'email' => 'hello@newseeds.example', 'payment_method' => 'mpesa_till', 'account_number' => '555111', 'account_reference' => 'SEED'],
            'budget' => [['item' => 'Seed', 'unit_cost' => 3000, 'quantity' => 20]]],
    ]]];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'disbursement'])->json('data.id');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan])->assertOk();
    $import = ProgramImport::find($id);
    expect($import->status)->toBe('completed', json_encode($import->progress['results']));
    $sent = fn () => collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress())->all();

    // Brought in by the applicant, as the deal room records it; nobody is emailed while importing
    $link = \App\Models\Programs\MilestoneSupplier::first();
    expect($link->added_by)->toBe('applicant')->and(\App\Models\Programs\MilestoneBudgetItem::first()->added_by)->toBe('applicant')
        ->and(collect($sent())->filter(fn ($to) => $to === 'hello@newseeds.example'))->toHaveCount(0)
        ->and($this->actingAsOrgOwner()->getJson(IMPORTS."/$id?light=1")->json('data.progress.suppliers.to_tell'))->toBe(1);

    // Tell suppliers: the welcome (new to the list) and the milestone they were added to; then nobody is told twice
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/notify-suppliers")->assertOk();
    expect(collect($sent())->filter(fn ($to) => $to === 'hello@newseeds.example'))->toHaveCount(2)
        ->and(ProgramImport::find($id)->progress['suppliers']['status'])->toBe('sent');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/notify-suppliers")->assertStatus(422);
});

it('pays milestones only as the program\'s disbursement type allows', function () {
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $program = fn (string $type) => ['program_title' => "Pays {$type}", 'industry' => 'Grants', 'disbursement_type' => $type, 'total_program_amount' => 5000000, 'funding_per_business' => 250000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'single_round', 'total_rounds' => 1, 'use_match_score' => false];
    $rounds = [['round_number' => 1, 'round_name' => 'Application', 'status' => 'finished', 'assignment_type' => 'owner_only', 'scoring_criteria' => [], 'questions' => [],
        'applications' => [['email' => 'farmer@example.org', 'first_name' => 'A', 'last_name' => 'B', 'awarded' => true, 'answers' => []]]]];
    $supplier = ['name' => 'Seeds Co', 'phone' => '0711000000', 'email' => 'seeds@example.org', 'payment_method' => 'mpesa_mobile', 'account_number' => '0711000000'];
    $plan = [
        // A beneficiary program pays the applicant: a milestone paid to a supplier is refused
        ['code' => 'P1', 'program' => $program('beneficiary'), 'rounds' => $rounds, 'milestones' => [['awardee' => 'farmer@example.org', 'order' => 1, 'title' => 'Seeds', 'description' => 'x', 'amount' => 1000, 'paid_to' => 'supplier', 'supplier' => $supplier]]],
        // ... and with Paid to left empty, it pays the applicant
        ['code' => 'P2', 'program' => $program('beneficiary'), 'rounds' => $rounds, 'milestones' => [['awardee' => 'farmer@example.org', 'order' => 1, 'title' => 'Seeds', 'description' => 'x', 'amount' => 1000]]],
        // A supplier program cannot pay the applicant
        ['code' => 'P3', 'program' => $program('supplier'), 'rounds' => $rounds, 'milestones' => [['awardee' => 'farmer@example.org', 'order' => 1, 'title' => 'Seeds', 'description' => 'x', 'amount' => 1000, 'paid_to' => 'applicant']]],
    ];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'disbursement'])->json('data.id');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan])->assertOk();
    $results = ProgramImport::find($id)->progress['results'];

    expect($results['P1']['status'])->toBe('failed')->and($results['P1']['message'])->toContain('pays the applicant')
        ->and($results['P2']['status'])->toBe('created')
        ->and($results['P3']['status'])->toBe('failed')->and($results['P3']['message'])->toContain('pays suppliers');
    $p2 = \App\Models\Programs\ProgramApplication::where('program_id', $results['P2']['program_id'])->first();
    $milestone = \App\Models\Programs\ProgramMilestone::where('app_id', $p2->id)->first();
    expect(\App\Models\Programs\MilestoneSupplier::where('milestone_id', $milestone->id)->value('payment_route'))->toBe('direct_to_applicant');
});

it('says which milestone suppliers are already in the owner\'s list and how they are paid', function () {
    \App\Models\Programs\SupplierDirectory::create(['added_by' => $this->orgUser->id, 'legal_name' => 'Agro Supplies Ltd', 'phone' => '0700000001', 'email' => 'sales@agro.example', 'payment_method' => 'mpesa_paybill', 'mpesa_paybill_number' => '222111', 'mpesa_paybill_account' => 'AGRO', 'is_active' => true]);
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'disbursement'])->json('data.id');

    $info = $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/suppliers", ['emails' => ['SALES@agro.example', 'new@seeds.example', $this->applicantUser->email]])->assertOk()->json('data');

    expect($info['sales@agro.example'])->toMatchArray(['in_list' => true, 'name' => 'Agro Supplies Ltd', 'payment_method' => 'mpesa_paybill', 'account' => '222111 / AGRO'])
        ->and($info['new@seeds.example'])->toBe(['in_list' => false, 'on_tujitume' => false])
        ->and($info[mb_strtolower($this->applicantUser->email)]['on_tujitume'])->toBeTrue();
    // Only the owner of the import asks
    $this->actingAsApplicant()->postJson(IMPORTS."/$id/suppliers", ['emails' => ['sales@agro.example']])->assertForbidden();
});

it('brings in a program at monitoring & evaluation: milestones as far as they got, payments recorded, checkpoints with reports and site visits', function () {
    config(['mail.default' => 'array']);
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $program = ['program_title' => 'Monitored Program', 'industry' => 'Grants', 'disbursement_type' => 'hybrid', 'total_program_amount' => 5000000, 'funding_per_business' => 250000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'single_round', 'total_rounds' => 1, 'use_match_score' => false];
    $supplier = ['name' => 'Agro Supplies Ltd', 'phone' => '0700000001', 'email' => 'sales@agro.example', 'payment_method' => 'mpesa_paybill', 'account_number' => '222111', 'account_reference' => 'AGRO'];
    $plan = [['code' => 'P1', 'program' => $program, 'rounds' => [
        ['round_number' => 1, 'round_name' => 'Application', 'status' => 'finished', 'assignment_type' => 'owner_only', 'scoring_criteria' => [], 'questions' => [],
            'applications' => [['email' => 'farmer@example.org', 'first_name' => 'A', 'last_name' => 'B', 'awarded' => true, 'answers' => []]]],
    ], 'milestones' => [
        // Completed (with its MPRV and final checks), paid in two parts and under way, then not started
        ['awardee' => 'farmer@example.org', 'order' => 1, 'title' => 'Cows', 'description' => 'Buy cows', 'amount' => 50000, 'paid_to' => 'supplier', 'supplier' => $supplier, 'mprv' => true, 'final_check' => true, 'budget' => [],
            'progress' => 'completed', 'payments' => [['amount' => 50000, 'paid_on' => '2026-03-10', 'channel' => 'mpesa_paybill', 'reference' => 'QF12AB34']]],
        ['awardee' => 'farmer@example.org', 'order' => 2, 'title' => 'Shed', 'description' => 'Build shed', 'amount' => 80000, 'paid_to' => 'applicant', 'budget' => [],
            'progress' => 'paid', 'payments' => [['amount' => 40000, 'paid_on' => '2026-06-02', 'channel' => 'bank_transfer'], ['amount' => 40000, 'paid_on' => '2026-07-15', 'channel' => 'bank_transfer']]],
        ['awardee' => 'farmer@example.org', 'order' => 3, 'title' => 'Cooler', 'description' => 'Buy cooler', 'amount' => 70000, 'paid_to' => 'supplier', 'supplier' => $supplier, 'budget' => [], 'progress' => 'not_started', 'payments' => []],
    ], 'checkpoints' => [
        ['awardee' => 'farmer@example.org', 'order' => 1, 'name' => 'First quarter report', 'type' => 'reporting', 'status' => 'verified', 'report' => '12 cows bought.', 'submitted_on' => '2026-04-30', 'review_note' => 'Good work',
            'description' => 'How the herd is doing', 'kpis_to_track' => [['name' => 'Cows', 'unit' => 'head', 'target' => 12]], 'evidence_required' => [['key' => 'receipts', 'label' => 'Receipts', 'category' => 'document', 'field_type' => 'file_upload', 'required' => true]],
            'submission_fields' => [['key' => 'report', 'label' => 'Written progress report', 'type' => 'report', 'required' => true], ['key' => 'kpi_actuals', 'label' => 'KPI actual values', 'type' => 'kpi_actuals', 'required' => true]],
            'kpi_actual_values' => [['name' => 'Cows', 'unit' => 'head', 'target' => 12, 'actual' => 12]]],
        ['awardee' => 'farmer@example.org', 'order' => 2, 'name' => 'Visit the farm', 'status' => 'pending', 'site_visit' => true,
            'visit' => ['kind' => 'coach', 'email' => 'officer@visits.example', 'first_name' => 'Wanjiku', 'last_name' => 'M', 'visit_date' => now()->addDays(10)->toDateString(), 'status' => 'scheduled']],
    ]]];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'monitoring'])->json('data.id');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan])->assertOk();
    $import = ProgramImport::find($id);
    expect($import->status)->toBe('completed', json_encode($import->progress['results']));

    $app = \App\Models\Programs\ProgramApplication::where('program_id', $import->progress['results']['P1']['program_id'])->firstOrFail();
    $ms = \App\Models\Programs\ProgramMilestone::where('app_id', $app->id)->orderBy('sequence_order')->get();
    // Done in order, as the deal room leaves them
    expect($ms[0]->status)->toBe('completion_approved')->and($ms[0]->fund_release_status)->toBe('released')->and((bool) $ms[0]->mprv_ready)->toBeTrue()->and((bool) $ms[0]->final_approval_ready)->toBeTrue()
        ->and($ms[1]->status)->toBe('completed')->and($ms[1]->fund_release_status)->toBe('released')->and((bool) $ms[1]->fund_released)->toBeTrue()
        ->and($ms[2]->fund_release_status)->toBe('locked')->and($ms[2]->status)->toBe('pending');
    // Checks recorded as approved, payments as completed disbursements (no wallet moves)
    expect(\App\Models\Programs\MilestoneVerification::where('milestone_id', $ms[0]->id)->pluck('verification_type')->sort()->values()->all())->toBe(['final_approval', 'mprv'])
        ->and(\App\Models\Milestones\MilestoneCompletionSubmission::where('milestone_id', $ms[0]->id)->value('decision'))->toBe('approved')
        ->and(\App\Models\Programs\Disbursement::where('milestone_id', $ms[1]->id)->where('status', 'completed')->sum('amount'))->toEqual(80000)
        ->and(\App\Models\Programs\Disbursement::where('milestone_id', $ms[1]->id)->value('recipient_type'))->toBe('business_owner')
        ->and(\App\Models\Programs\Disbursement::where('milestone_id', $ms[0]->id)->value('payment_reference'))->toBe('QF12AB34')
        // In the payments ledger (the program's wallet), not confirmed for the supplier (only their own link does that)
        ->and(\App\Models\Programs\Disbursement::where('milestone_id', $ms[1]->id)->value('wallet_id'))->toBe(\App\Models\Programs\ProgramWallet::where('program_id', $app->program_id)->value('id'))
        ->and(\App\Models\Programs\Disbursement::where('supplier_confirmed', true)->count())->toBe(0);

    // Checkpoints: the report received, verified; the visit scheduled with a new officer, nobody told while importing
    $cps = \App\Models\Programs\Monitoring\MECheckpoint::where('app_id', $app->id)->orderBy('display_order')->get();
    expect($cps)->toHaveCount(2)->and($cps[0]->status)->toBe('verified')->and($cps[0]->sent_at)->not->toBeNull()
        ->and(\App\Models\Programs\Monitoring\MESubmission::where('checkpoint_id', $cps[0]->id)->value('written_report'))->toBe('12 cows bought.')
        ->and((bool) $cps[1]->require_site_visit)->toBeTrue()->and($cps[1]->type)->toBe('site_visit')
        // Numbered 0, 1… as the M&E tab does; what it tracks and asks for, and the results the report gave
        ->and($cps->pluck('display_order')->all())->toBe([0, 1])->and($cps[0]->checkpoint_description)->toBe('How the herd is doing')
        ->and($cps[0]->kpis_to_track)->toBe([['name' => 'Cows', 'unit' => 'head', 'target' => 12]])->and($cps[0]->evidence_required[0]['label'])->toBe('Receipts')
        ->and(collect($cps[0]->submission_fields)->pluck('key')->all())->toBe(['report', 'kpi_actuals'])
        ->and(\App\Models\Programs\Monitoring\MESubmission::where('checkpoint_id', $cps[0]->id)->first()->kpi_actual_values[0]['actual'])->toBe(12);
    // A site visit is the officer's to report; what was paid is no longer available to the program
    expect($cps[1]->respondent)->toBe('site_visit_officer')
        ->and((float) \App\Models\Programs\Program::find($app->program_id)->available_amount)->toBe(5000000.0 - 130000.0);
    $visit = \App\Models\Programs\Monitoring\MESiteVisit::where('checkpoint_id', $cps[1]->id)->firstOrFail();
    $officer = \App\Models\Auth\User::find($visit->reviewer_id);
    expect($visit->status)->toBe('scheduled')->and($visit->assign_type)->toBe('external')->and($officer->email)->toBe('officer@visits.example')
        ->and(\App\Models\ReviewerOrder::where('site_visit_id', $visit->id)->value('acceptance_status'))->toBe('pending');
    $sent = fn () => collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress())->all();
    expect(collect($sent())->filter(fn ($to) => $to === 'officer@visits.example'))->toHaveCount(0)
        ->and($this->actingAsOrgOwner()->getJson(IMPORTS."/$id?light=1")->json('data.progress.inspectors.to_tell'))->toBe(1);

    // Tell site visit officers: the new officer gets their invitation; then nobody is told twice
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/notify-inspectors")->assertOk();
    expect(collect($sent())->filter(fn ($to) => $to === 'officer@visits.example'))->toHaveCount(1)
        ->and(ProgramImport::find($id)->progress['inspectors']['status'])->toBe('sent');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/notify-inspectors")->assertStatus(422);
});

it('refuses monitoring milestones out of order and payments above a milestone', function () {
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $program = ['program_title' => 'Out Of Order', 'industry' => 'Grants', 'disbursement_type' => 'beneficiary', 'total_program_amount' => 5000000, 'funding_per_business' => 250000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'single_round', 'total_rounds' => 1, 'use_match_score' => false];
    $plan = fn (array $milestones) => [['code' => 'P1', 'program' => $program, 'rounds' => [
        ['round_number' => 1, 'round_name' => 'Application', 'status' => 'finished', 'assignment_type' => 'owner_only', 'scoring_criteria' => [], 'questions' => [],
            'applications' => [['email' => 'farmer@example.org', 'first_name' => 'A', 'last_name' => 'B', 'awarded' => true, 'answers' => []]]],
    ], 'milestones' => $milestones]];
    $m = fn (int $order, string $progress, array $payments = []) => ['awardee' => 'farmer@example.org', 'order' => $order, 'title' => "M{$order}", 'description' => 'x', 'amount' => 10000, 'paid_to' => 'applicant', 'budget' => [], 'progress' => $progress, 'payments' => $payments];

    foreach ([[$m(1, 'paid'), $m(2, 'paid')], [$m(1, 'paid', [['amount' => 15000, 'paid_on' => '2026-03-01']])]] as $milestones) {
        $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'monitoring'])->json('data.id');
        $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan($milestones)])->assertOk();
        expect(ProgramImport::find($id)->status)->not->toBe('completed');
    }
});

it('carries on a "Let applicants know" that stopped part way, on the next progress check', function () {
    config(['mail.default' => 'array']);
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $program = ['program_title' => 'Resumed Sends', 'industry' => 'Grants', 'total_program_amount' => 5000000, 'funding_per_business' => 250000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'single_round', 'total_rounds' => 1, 'use_match_score' => false];
    $plan = [['code' => 'P1', 'program' => $program, 'rounds' => [
        ['round_number' => 1, 'round_name' => 'Application', 'status' => 'in_review', 'assignment_type' => 'owner_only', 'scoring_criteria' => [['name' => 'Impact', 'weight' => 100, 'scoreRange' => '0-10']], 'questions' => [],
            'applications' => [['email' => 'one@example.org', 'first_name' => 'A', 'last_name' => 'B', 'answers' => []]]],
    ]]];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'review'])->json('data.id');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan])->assertOk();
    // A send left on "sending" (its run was stopped) a while ago
    $import = ProgramImport::find($id);
    $progress = $import->progress;
    $progress['notify'] = ['status' => 'sending', 'total' => 1, 'sent' => 0, 'sent_ids' => []];
    $import->forceFill(['progress' => $progress])->save();
    ProgramImport::whereKey($id)->update(['updated_at' => now()->subMinutes(5)]);

    $this->actingAsOrgOwner()->getJson(IMPORTS."/$id?light=1")->assertOk();
    expect(ProgramImport::find($id)->progress['notify']['status'])->toBe('sent');
    // Starting the import again while emails go out is refused
    $p = ProgramImport::find($id);
    $pr = $p->progress; $pr['notify']['status'] = 'sending'; $p->forceFill(['progress' => $pr, 'status' => 'partial'])->save();
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan])->assertStatus(422);
});

it('does not email an address that cannot receive mail, says why, and does not try it again', function () {
    config(['mail.default' => 'array', 'mail.import.check_domains' => true]);
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $program = ['program_title' => 'Bounce Program', 'industry' => 'Grants', 'total_program_amount' => 5000000, 'funding_per_business' => 250000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'single_round', 'total_rounds' => 1, 'use_match_score' => false];
    $plan = [['code' => 'P1', 'program' => $program, 'rounds' => [
        ['round_number' => 1, 'round_name' => 'Application', 'status' => 'finished', 'assignment_type' => 'owner_only', 'scoring_criteria' => [], 'questions' => [],
            'applications' => [['email' => 'farmer@tujitume.com', 'first_name' => 'A', 'last_name' => 'B', 'awarded' => true, 'answers' => []]]],
    ], 'milestones' => [
        // A test domain: it cannot receive mail, so the supplier is not emailed (it would bounce)
        ['awardee' => 'farmer@tujitume.com', 'order' => 1, 'title' => 'Seeds', 'description' => 'Buy seed', 'amount' => 60000, 'paid_to' => 'supplier',
            'supplier' => ['name' => 'Ghost Seeds', 'phone' => '0711000000', 'email' => 'sales@ghost-seeds.test', 'payment_method' => 'mpesa_mobile', 'account_number' => '0711000000'], 'budget' => []],
    ]]];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'disbursement'])->json('data.id');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan])->assertOk();
    expect(ProgramImport::find($id)->status)->toBe('completed');

    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/notify-suppliers")->assertOk();
    $sent = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress());
    $suppliers = $this->actingAsOrgOwner()->getJson(IMPORTS."/$id?light=1")->json('data.progress.suppliers');

    expect($sent->contains('sales@ghost-seeds.test'))->toBeFalse()
        ->and($suppliers['skipped'])->toBe([['email' => 'sales@ghost-seeds.test', 'reason' => 'no_mail_domain']])
        ->and($suppliers['to_tell'])->toBe(0)->and($suppliers['status'])->toBe('sent');
});

it('tells the person who started an import that it finished, with how long it took, once', function () {
    config(['mail.default' => 'array']);
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $program = ['program_title' => 'Quick Program', 'industry' => 'Grants', 'total_program_amount' => 1000000, 'funding_per_business' => 100000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'single_round', 'total_rounds' => 1, 'use_match_score' => false];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'setup'])->json('data.id');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => [['code' => 'P1', 'program' => $program, 'rounds' => [['round_number' => 1, 'round_name' => 'Application', 'questions' => [['question_text' => 'Name', 'question_type' => 'short_answer', 'is_required' => true]]]]]]])->assertOk();
    $import = ProgramImport::find($id);
    expect($import->status)->toBe('completed');

    $notes = \App\Models\Communication\Notifications::where('receiver_id', $this->orgUser->id)->where('text', 'like', 'Import finished%')->get();
    $emails = collect(app('mailer')->getSymfonyTransport()->messages())->filter(fn ($m) => $m->getOriginalMessage()->getSubject() === 'Your program import is finished');
    expect($notes)->toHaveCount(1)->and($notes[0]->text)->toContain('1 of 1 program(s) created in')
        ->and($emails)->toHaveCount(1)->and($import->progress['finished_notified'])->not->toBeNull();

    // A run that finds nothing left to do (a retry, a resume) does not announce it again
    (new \App\Jobs\RunProgramImportJob($id))->handle(app(\App\Service\Program\ProgramImportRunner::class));
    expect(\App\Models\Communication\Notifications::where('receiver_id', $this->orgUser->id)->where('text', 'like', 'Import finished%')->count())->toBe(1);
});

it('hands imported applications of an AI round to the AI review queue only when asked, once', function () {
    config(['mail.default' => 'array', 'ai_review.enabled' => true]);
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    $program = ['program_title' => 'AI Program', 'industry' => 'Grants', 'total_program_amount' => 1000000, 'funding_per_business' => 100000,
        'application_start_date' => '2026-01-10', 'application_deadline' => '2026-02-28', 'program_type' => 'single_round', 'total_rounds' => 1, 'use_match_score' => false];
    $round = ['round_number' => 1, 'round_name' => 'Application', 'status' => 'in_review', 'review_method' => 'ai_assisted', 'assignment_type' => 'owner_only',
        'review_period_end' => now()->addMonth()->toDateString(), 'scoring_criteria' => [['name' => 'Impact', 'description' => '', 'weight' => 100, 'scoreRange' => '0-10']],
        'questions' => [['question_text' => 'What do you do?', 'question_type' => 'short_answer', 'is_required' => true]],
        'applications' => [['email' => 'one@tujitume.com', 'first_name' => 'A', 'last_name' => 'B', 'answers' => [['question' => 0, 'response' => 'Farming']]]]];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'review'])->json('data.id');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => [['code' => 'P1', 'program' => $program, 'rounds' => [$round]]]])->assertOk();
    expect(ProgramImport::find($id)->status)->toBe('completed', json_encode(ProgramImport::find($id)->progress['results']));

    // Nothing reviewed while importing: one application waits for the person to start it
    expect(\App\Models\Programs\Ai\ApplicationAiReview::count())->toBe(0)
        ->and($this->actingAsOrgOwner()->getJson(IMPORTS."/$id?light=1")->json('data.progress.ai.to_tell'))->toBe(1);

    \Illuminate\Support\Facades\Bus::fake([\App\Jobs\RunApplicationAiReviewJob::class]);
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/ai-review")->assertOk();

    // Queued in the app's own AI review queue, as a submission is; then nothing is left to start
    expect(\App\Models\Programs\Ai\ApplicationAiReview::where('status', \App\Models\Programs\Ai\ApplicationAiReview::STATUS_QUEUED)->count())->toBe(1)
        ->and($this->actingAsOrgOwner()->getJson(IMPORTS."/$id?light=1")->json('data.progress.ai.to_tell'))->toBe(0);
    \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\RunApplicationAiReviewJob::class, 1);
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/ai-review")->assertStatus(422);
});

it('keeps opening and closing times in the owner\'s time zone, as Create Program does, for the countdowns', function () {
    \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Grants'], ['url' => 'grants']);
    \App\Models\Auth\UserSetting::updateOrCreate(['user_id' => $this->orgUser->id], ['timezone' => 'Africa/Nairobi']);
    $program = ['program_title' => 'Timed Program', 'industry' => 'Grants', 'total_program_amount' => 1000000, 'funding_per_business' => 100000,
        'application_start_date' => '2026-12-01', 'application_deadline' => '2026-12-20', 'application_opens_at' => '2026-12-01 09:00', 'application_closes_at' => '2026-12-20 17:30',
        'program_type' => 'single_round', 'total_rounds' => 1, 'use_match_score' => false];
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'many', 'stage' => 'setup'])->json('data.id');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => [['code' => 'P1', 'program' => $program, 'rounds' => [['round_number' => 1, 'round_name' => 'Application', 'questions' => [['question_text' => 'Name', 'question_type' => 'short_answer', 'is_required' => true]]]]]]])->assertOk();
    $import = ProgramImport::find($id);
    expect($import->status)->toBe('completed', json_encode($import->progress['results']));

    $created = Program::find($import->progress['results']['P1']['program_id']);
    $round = $created->rounds()->first();
    // 09:00 in Nairobi is 06:00 UTC; Round 1 without its own dates opens and closes with the program
    expect($created->timezone)->toBe('Africa/Nairobi')
        ->and($created->application_opens_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-01 06:00')
        ->and($created->application_closes_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-20 14:30')
        ->and($round->opens_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-01 06:00')
        ->and($round->closes_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-20 14:30');
});

it('continues a program you have from where it is: its stage, then its awardees\' milestones and checkpoints', function () {
    config(['mail.default' => 'array']);
    $this->program->forceFill(['disbursement_type' => 'beneficiary'])->save();
    $this->application->forceFill(['status' => 'awarded', 'awarded_amount' => 100000])->save();
    $email = mb_strtolower($this->applicantUser->email);
    // A draft plan in the deal room, not activated yet: the file's milestones replace it
    $draft = \App\Models\Programs\ProgramMilestone::create(['app_id' => $this->application->id, 'sequence_order' => 1, 'title' => 'Draft', 'description' => '', 'amount' => 1000, 'status' => 'pending', 'is_template' => true]);

    $state = $this->actingAsOrgOwner()->getJson(IMPORTS.'/program-state/'.$this->program->id)->assertOk()->json('data');
    expect($state['stage'])->toBe('funding')->and($state['allowed'])->toBe(['disbursement', 'monitoring'])
        ->and($state['awardees'][0])->toMatchArray(['email' => $email, 'has_milestones' => false, 'has_draft' => true])
        ->and($state['program']['disbursement_type'])->toBe('beneficiary');

    // Stages it has passed cannot be chosen; one from where it is can
    $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'existing', 'stage' => 'review', 'program_id' => $this->program->id])->assertStatus(422);
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'existing', 'stage' => 'monitoring', 'program_id' => $this->program->id])
        ->assertCreated()->json('data.id');
    expect(ProgramImport::find($id)->current_stage)->toBe('funding');

    $plan = [['code' => '', 'rounds' => [], 'milestones' => [
        ['awardee' => strtoupper($email), 'order' => 1, 'title' => 'Tools', 'description' => 'Buy tools', 'amount' => 40000, 'paid_to' => 'applicant', 'budget' => [],
            'progress' => 'paid', 'payments' => [['amount' => 40000, 'paid_on' => '2026-03-10', 'channel' => 'bank_transfer']]],
        ['awardee' => $email, 'order' => 2, 'title' => 'Stock', 'description' => 'Buy stock', 'amount' => 60000, 'paid_to' => 'applicant', 'budget' => [], 'progress' => 'not_started', 'payments' => []],
    ], 'checkpoints' => [['awardee' => $email, 'name' => 'First report', 'type' => 'reporting', 'due_date' => '2026-04-01', 'status' => 'pending']]]];
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$id/start", ['plan' => $plan])->assertOk();
    $import = ProgramImport::find($id);
    expect($import->status)->toBe('completed', json_encode($import->progress['results']));

    $ms = \App\Models\Programs\ProgramMilestone::where('app_id', $this->application->id)->orderBy('sequence_order')->get();
    expect($ms->pluck('title')->all())->toBe(['Tools', 'Stock'])->and($ms->every(fn ($m) => ! $m->is_template))->toBeTrue()
        ->and(\App\Models\Programs\ProgramMilestone::find($draft->id))->toBeNull()
        ->and($this->application->fresh()->funding_setup_status)->toBe('completed')
        ->and(\App\Models\Programs\Disbursement::where('milestone_id', $ms[0]->id)->where('status', 'completed')->count())->toBe(1)
        ->and(\App\Models\Programs\Monitoring\MECheckpoint::where('app_id', $this->application->id)->value('checkpoint_name'))->toBe('First report')
        // No new program and no new round: it was added to the one you have
        ->and(\App\Models\Programs\Rounds\ProgramRound::where('program_id', $this->program->id)->count())->toBe(1);

    // Now it is at monitoring; giving the same awardee milestones again is refused, nothing half-done is left
    expect($this->actingAsOrgOwner()->getJson(IMPORTS.'/program-state/'.$this->program->id)->json('data.stage'))->toBe('monitoring');
    $again = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'existing', 'stage' => 'monitoring', 'program_id' => $this->program->id])->json('data.id');
    $this->actingAsOrgOwner()->postJson(IMPORTS."/$again/start", ['plan' => [['code' => '', 'rounds' => [], 'milestones' => [$plan[0]['milestones'][1]]]]])->assertOk();
    expect(ProgramImport::find($again)->status)->toBe('partial')
        ->and(json_encode(ProgramImport::find($again)->progress['results']))->toContain('already has milestones')
        ->and(\App\Models\Programs\ProgramMilestone::where('app_id', $this->application->id)->count())->toBe(2);

    // Someone else's program says nothing
    $stranger = User::factory()->create(['user_type_id' => 4]);
    $theirs = Program::factory()->create(['user_id' => $stranger->id]);
    $this->actingAsOrgOwner()->getJson(IMPORTS.'/program-state/'.$theirs->id)->assertForbidden();
});

it('starts an import from a Google Form, always at set up', function () {
    $id = $this->actingAsOrgOwner()->postJson(IMPORTS, ['mode' => 'form', 'stage' => 'review'])->assertCreated()->json('data.id');
    expect(ProgramImport::find($id)->stage)->toBe('setup');
    $this->actingAsOrgOwner()->patchJson(IMPORTS."/$id", ['stage' => 'funding'])->assertOk();
    expect(ProgramImport::find($id)->stage)->toBe('setup');
});
