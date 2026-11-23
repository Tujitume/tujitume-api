<?php

use App\Models\ProgramIndustry;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Support\TextFormParser;
use Tests\Feature\ProgramImport\ApiImportTestCase;

uses(ApiImportTestCase::class);

const PI_GOOGLE_COPY = "Seed Fund 2027 Application\nThanks for applying.\nsomeone@example.com Switch account\n* Indicates required question\nEmail\n*\nYour mobile phone number\n*\n"
    ."Team Member 2\n*\na) Name and role at the company? b) LinkedIn profile (1,000 characters max)\nTeam Member 3\na) Name and role at the company? b) LinkedIn profile (1,000 characters max)\n"
    ."Company Website\n*\nPitch video URL\n(Optional) Video of the founders pitching, under 2 minutes please.\nRound 2: Pitch\nWhat is your product?\n*\n(500 characters max)\n"
    ."My company is in: (You can check multiple boxes)\n*\nAgTech\nFinTech\nOther:\nHow did you hear about us?\n*\nWeb search\nA friend\nOther:\n"
    ."(Optional) If someone referred you, enter their name:\nA copy of your responses will be emailed to the address you provided.\nNever submit passwords through Google Forms.\nreCAPTCHA\nPrivacy\nTerms";

it('reads a copied Google Form exactly: only starred questions are required, help, options, types and rounds are kept', function () {
    $spec = (new TextFormParser)->parse(PI_GOOGLE_COPY);
    $q = collect($spec['questions'])->keyBy('label');

    expect($spec['title'])->toBe('Seed Fund 2027 Application')->and($spec['description'])->not->toContain('Switch account')
        ->and($q->keys()->all())->toBe(['Email', 'Your mobile phone number', 'Team Member 2', 'Team Member 3', 'Company Website', 'Pitch video URL', 'What is your product?',
            'My company is in: (You can check multiple boxes)', 'How did you hear about us?', '(Optional) If someone referred you, enter their name:'])
        ->and($q->map(fn ($x) => $x['required'])->filter()->keys()->all())->toBe(['Email', 'Your mobile phone number', 'Team Member 2', 'Company Website', 'What is your product?',
            'My company is in: (You can check multiple boxes)', 'How did you hear about us?'])
        ->and($q['Email']['type'])->toBe('email')->and($q['Your mobile phone number']['type'])->toBe('phone')->and($q['Company Website']['type'])->toBe('url')
        ->and($q['Pitch video URL']['type'])->toBe('video_link')->and($q['Team Member 3']['type'])->toBe('long_text')
        ->and($q['Team Member 3']['help_text'])->toContain('LinkedIn')
        ->and($q['My company is in: (You can check multiple boxes)']['type'])->toBe('checkbox')->and($q['My company is in: (You can check multiple boxes)']['options'])->toBe(['AgTech', 'FinTech', 'Other'])
        ->and($q['How did you hear about us?']['type'])->toBe('multiple_choice')
        ->and($q['What is your product?']['round'])->toBe(2)->and($q['Email']['round'])->toBe(1)
        ->and($spec['rounds'])->toBe([['number' => 1, 'name' => 'Application'], ['number' => 2, 'name' => 'Pitch']]);
});

it('turns a form with a round break into a multi-round draft program with every app question type', function () {
    $r = $this->po()->postJson('/api/v1/program-imports', ['source' => 'text', 'type' => 'form', 'text' => PI_GOOGLE_COPY])->assertCreated();
    $id = $r->json('data.id');
    $this->po()->patchJson("/api/v1/program-imports/$id/form-spec", ['program' => $this->programRow(['program_ref' => null, 'program_title' => 'Seed Fund']), 'confirmed' => true])->assertOk();
    $this->po()->postJson("/api/v1/program-imports/$id/validate")->assertStatus(202);
    $b = ImportBatch::find($id);
    expect($b->status)->toBe('ready', json_encode(ImportRow::where('batch_id', $id)->where('status', 'invalid')->get(['sheet', 'errors'])));
    $this->commit($b);

    expect(ProgramRound::orderBy('round_number')->pluck('round_name')->all())->toBe(['Application', 'Pitch'])
        ->and(RoundCustomQuestion::where('question_type', 'video_link')->count())->toBe(1)
        ->and(RoundCustomQuestion::where('question_type', 'checkbox')->value('options'))->toBe(['AgTech', 'FinTech', 'Other'])
        ->and(RoundCustomQuestion::where('question_text', 'like', 'Team Member 3%')->value('question_text'))->toContain("\na) Name and role");
    $this->assertSilent();
});

it('finds an industry written differently and suggests the closest one when it does not exist', function () {
    ProgramIndustry::firstOrCreate(['name' => 'Retail and Trade']);
    $ok = $this->newBatch('setup', $this->xlsx(['program' => [$this->programRow(['program_industry' => 'Retail & Trade'])], 'rounds' => $this->roundRows()]));
    expect($this->prepare($ok)['ready'])->toBeTrue();

    $bad = $this->newBatch('setup', $this->xlsx(['program' => [$this->programRow(['program_industry' => 'Retial and Trade', 'program_ref' => 'P2'])], 'rounds' => $this->roundRows()]));
    $res = $this->prepare($bad);
    $row = ImportRow::where('batch_id', $bad->id)->where('sheet', 'program')->first();
    expect($row->errors['program_industry'])->toContain('Did you mean "Retail and Trade"')
        ->and(collect($res['blockers'])->pluck('code')->all())->toContain('sheet_all_invalid');
});

it('does not ask for data that was already imported: earlier rows are reused and explained', function () {
    $first = $this->newBatch('setup', $this->xlsx(['program' => [$this->programRow()], 'rounds' => $this->roundRows()]));
    $this->prepare($first);
    $this->commit($first);

    $again = $this->newBatch('review', $this->xlsx(['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $this->appRows(1)]));
    $res = $this->prepare($again);
    expect($res['blockers'])->toBe([])->and(collect($res['warnings'])->pluck('code')->all())->toContain('already_imported');
});

it('changes the import options from the check step and checks again', function () {
    $apps = $this->appRows(1);
    $apps[1]['total_amount_requested'] = 'lots';
    $file = new \Illuminate\Http\UploadedFile($this->xlsx(['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $apps]), 'data.xlsx', null, null, true);
    $id = $this->po()->post('/api/v1/program-imports', ['start_point' => 'review', 'source' => 'file', 'file' => $file, 'options' => ['mode' => 'all_or_nothing']], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $an = $this->po()->postJson("/api/v1/program-imports/$id/analyze");
    expect($an->status())->toBe(202, $an->getContent());
    $this->po()->postJson("/api/v1/program-imports/$id/validate")->assertStatus(202);
    $this->po()->postJson("/api/v1/program-imports/$id/readiness")->assertOk()->assertJsonPath('data.ready', false);

    $r = $this->po()->patchJson("/api/v1/program-imports/$id/options", ['options' => ['mode' => 'partial']])->assertOk();
    expect($r->json('data.readiness.ready'))->toBeTrue()->and(ImportBatch::find($id)->options['mode'])->toBe('partial');
    $this->po()->patchJson("/api/v1/program-imports/$id/options", ['options' => ['mode' => 'everything']])->assertStatus(422);
});

it('after the award, the round history shows who stopped in which round and who won', function () {
    $rounds = [...$this->roundRows(), ['program_ref' => 'P1', 'round_number' => 3, 'round_name' => 'Final', 'open_date' => '2026-05-01', 'close_date' => '2026-05-20']];
    $apps = [...$this->appRows(1), ...array_map(fn ($a) => array_merge($a, ['application_ref' => $a['application_ref'].'b', 'email' => 'b'.$a['email'], 'startup_name' => $a['startup_name'].' B']), $this->appRows(3))];
    $winners = [['application_ref' => 'A1b', 'program_ref' => 'P1', 'awarded_amount' => 100000]];
    $ms = [['application_ref' => 'A1b', 'milestone_ref' => 'M1', 'sequence_order' => 1, 'title' => 'Equipment', 'amount' => 100000]];
    $b = $this->newBatch('disbursement', $this->xlsx(['program' => [$this->programRow(['total_rounds' => 3])], 'rounds' => $rounds, 'questions' => null, 'applications' => $apps, 'winners' => $winners, 'milestones' => $ms]));
    expect($this->prepare($b)['blockers'])->toBe([]);
    $this->commit($b);

    $by = ProgramApplication::all()->keyBy('startup_name');
    expect($by['Agro One B']->status)->toBe('awarded')->and((float) $by['Agro One B']->awarded_amount)->toBe(100000.0)
        ->and($by['Agro Two B']->status)->toBe('rejected')->and($by['Agro Two B']->round_status)->toBe('not_selected')
        ->and($by['Agro One']->status)->toBe('rejected')
        ->and(ProgramRound::find($by['Agro One']->current_round_id)->round_number)->toBe(1)
        ->and(ProgramRound::find($by['Agro Two B']->current_round_id)->round_number)->toBe(3);
    $this->assertSilent();
});

it('builds a stage template with only that stage\'s tabs, linked example rows and dropdowns', function () {
    $path = (new \App\Modules\ProgramImport\Support\TemplateGenerator)->build('monitoring', 'xlsx');
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $titles = array_map(fn ($w) => $w->getTitle(), $book->getAllSheets());
    expect($titles)->toContain('milestones', 'disbursements', 'checkpoints', 'applications')->not->toContain('questions', 'fields', 'reviewers')
        ->and($book->getSheetByName('program')->getCell('J2')->getValue())->not->toBeEmpty()
        ->and($book->getSheetByName('winners')->getCell('A2')->getValue())->toBe($book->getSheetByName('milestones')->getCell('A2')->getValue());
    $setup = \PhpOffice\PhpSpreadsheet\IOFactory::load((new \App\Modules\ProgramImport\Support\TemplateGenerator)->build('setup', 'xlsx'));
    expect(array_map(fn ($w) => $w->getTitle(), $setup->getAllSheets()))->not->toContain('milestones', 'applications', 'winners');
});

it('imports every stage template exactly as downloaded, with answers linked to their questions', function (string $stage) {
    $path = (new \App\Modules\ProgramImport\Support\TemplateGenerator)->build($stage, 'xlsx');
    $file = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    copy($path, $file);
    $b = $this->newBatch($stage, $file);
    $res = $this->prepare($b);
    $invalid = ImportRow::where('batch_id', $b->id)->where('status', 'invalid')->get(['sheet', 'row_number', 'errors'])->toArray();
    expect($invalid)->toBe([], json_encode($invalid))->and($res['blockers'])->toBe([], json_encode($res['blockers']));
    $this->commit($b);
    expect($b->refresh()->status)->toBe('completed', json_encode($b->stats['commit'] ?? []));
    if (in_array($stage, ['review', 'finalization'], true)) {
        $cols = $b->mapping['sheets']['applications']['columns'];
        expect(collect($cols)->pluck('target')->filter(fn ($x) => str_starts_with((string) $x, 'question:'))->count())->toBe(6, json_encode($cols))
            ->and(\App\Models\Programs\Rounds\ApplicationRoundResponse::count())->toBeGreaterThan(0);
    }
    $this->assertSilent();
})->with(['setup', 'review', 'finalization', 'funding_setup', 'disbursement', 'monitoring']);

it('names a downloaded template after the organisation and shows who started each import', function () {
    $this->org->update(['name' => 'Acme Foundation', 'display_name' => 'Acme Foundation']);
    $r = $this->po()->get('/api/v1/program-imports/templates/review?format=xlsx')->assertOk();
    expect($r->headers->get('content-disposition'))->toContain('Acme Foundation - Start at review import template.xlsx');

    $b = $this->newBatch('setup', $this->xlsx(['program' => [$this->programRow()], 'rounds' => $this->roundRows()]));
    $this->prepare($b);
    $this->commit($b);
    $item = $this->po()->getJson('/api/v1/program-imports')->assertOk()->json('data.items.0');
    expect($item['program_title'])->toBe('Youth Fund')->and($item['started_by'])->not->toBeEmpty();
});
