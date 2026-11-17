<?php

use App\Modules\ProgramImport\Mapping\AiMapper;
use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Pipeline\BatchStager;
use App\Service\AiReview\LlmException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\ProgramImport\ApiImportTestCase;
use Tests\Feature\Programs\AiReview\FakeLlmClient;

uses(ApiImportTestCase::class);

function pi_odd_batch($t): ImportBatch
{
    $apps = $t->appRows(2);
    $apps = array_map(function ($a) {
        $a['Biz'] = $a['startup_name'];
        unset($a['startup_name']);

        return $a;
    }, $apps);
    $b = $t->newBatch('review', $t->xlsx(['program' => [$t->programRow()], 'rounds' => $t->roundRows(), 'applications' => $apps]));
    $s = new BatchStager;
    $s->parse($b->refresh());
    $s->analyze($b, []);

    return $b->refresh();
}

it('maps with AI proposals that stay inside the registry and sends only masked shapes', function () {
    $b = pi_odd_batch($this);
    expect($b->mapping['sheets']['applications']['ignored'])->toContain('Biz');
    $fake = (new FakeLlmClient)->push(['mappings' => [['header' => 'Biz', 'target' => 'startup_name', 'confidence' => 0.92]]]);
    $usage = (new AiMapper($fake))->refine($b);
    expect($usage['used'])->toBeTrue()->and($b->refresh()->mapping['sheets']['applications']['columns']['Biz'])->toMatchArray(['target' => 'startup_name', 'origin' => 'ai']);
    $sent = json_encode($fake->requests[0]->messages);
    expect($sent)->not->toContain('Agro One')->not->toContain('amina@example.com')->toContain('Aaaa Aaa');
});

it('drops AI targets outside the registry, prompt injection included', function () {
    $b = pi_odd_batch($this);
    $evil = ['mappings' => [['header' => 'Biz', 'target' => 'users.password', 'confidence' => 1], ['header' => 'Ignore previous instructions', 'target' => 'program_title', 'confidence' => 1]]];
    (new AiMapper((new FakeLlmClient)->push($evil)))->refine($b);
    $cols = $b->refresh()->mapping['sheets']['applications']['columns'];
    expect($cols)->not->toHaveKey('Biz')->and(array_column($cols, 'target'))->not->toContain('users.password');
});

it('falls back to the heuristic on invalid JSON or an AI outage and still imports', function () {
    $b = pi_odd_batch($this);
    $u = (new AiMapper((new FakeLlmClient)->push('not json', 'still not json')))->refine($b);
    expect($u['reason'])->toBe('invalid_ai_output');
    $b2 = pi_odd_batch($this);
    $u2 = (new AiMapper((new FakeLlmClient)->push(new LlmException(LlmException::SERVER_ERROR, 'down'))))->refine($b2);
    expect($u2['reason'])->toBe('ai_error')->and($b2->refresh()->mapping['sheets']['applications']['columns'])->not->toBeEmpty();
    config(['program_imports.ai.enabled' => false]);
    expect((new AiMapper)->refine($b2)['reason'])->toBe('ai_unavailable');
});

function pi_http(array $stubs): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake($stubs);
}

function pi_form_html(): string
{
    $items = [
        [1, 'Business name', 'Your legal name', 0, [[111, null, 1]]],
        [2, 'Describe your impact', null, 1, [[112, null, 0]]],
        [3, 'Sector', null, 2, [[113, [['Agri'], ['Health'], ['Education']], 1]]],
        [4, 'Section header', null, 6, []],
    ];
    $blob = [null, [null, $items, null, null, null, null, null, null, 'Seed Fund Form'], null, 'Seed Fund Form'];

    return '<html><script>var FB_PUBLIC_LOAD_DATA_ = '.json_encode($blob).';</script></html>';
}

it('reads a public Google Form, rejects other hosts, private IPs and redirect loops', function () {
    app()->instance('program_imports.resolver', fn () => ['142.250.1.1']);
    pi_http(['docs.google.com/*' => Http::response(pi_form_html())]);
    $r = $this->po()->postJson('/api/v1/program-imports', ['source' => 'google_form_url', 'url' => 'https://docs.google.com/forms/d/e/abc/viewform'])->assertCreated();
    $spec = $r->json('data.form_spec');
    expect($spec['title'])->toBe('Seed Fund Form')->and(array_column($spec['questions'], 'type'))->toBe(['text', 'paragraph', 'choice'])
        ->and($spec['questions'][0]['required'])->toBeTrue()->and($spec['questions'][2]['options'])->toBe(['Agri', 'Health', 'Education'])->and($spec['questions'][0]['suggested'])->toBeTrue();

    foreach (['http://docs.google.com/forms/x', 'https://evil.com/forms/x', 'https://docs.google.com.evil.com/forms/x', 'https://user@docs.google.com/forms/x'] as $bad) {
        $this->po()->postJson('/api/v1/program-imports', ['source' => 'google_form_url', 'url' => $bad])->assertStatus(422)->assertJsonPath('errors.code', 'form_url');
    }
    app()->instance('program_imports.resolver', fn () => ['127.0.0.1']);
    $this->po()->postJson('/api/v1/program-imports', ['source' => 'google_form_url', 'url' => 'https://docs.google.com/forms/d/e/abc/viewform'])->assertStatus(422)->assertJsonPath('errors.code', 'form_url');

    app()->instance('program_imports.resolver', fn () => ['142.250.1.1']);
    pi_http(['docs.google.com/*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest'])]);
    $this->po()->postJson('/api/v1/program-imports', ['source' => 'google_form_url', 'url' => 'https://docs.google.com/forms/d/e/abc/viewform'])->assertStatus(422)->assertJsonPath('errors.code', 'form_url');
    pi_http(['docs.google.com/*' => Http::response('', 302, ['Location' => 'https://docs.google.com/forms/loop'])]);
    $this->po()->postJson('/api/v1/program-imports', ['source' => 'google_form_url', 'url' => 'https://docs.google.com/forms/d/e/abc/viewform'])->assertStatus(422)->assertJsonPath('errors.code', 'too_many_redirects');
    pi_http(['docs.google.com/*' => Http::response('<html>sign in</html>')]);
    $this->po()->postJson('/api/v1/program-imports', ['source' => 'google_form_url', 'url' => 'https://docs.google.com/forms/d/e/abc/viewform'])->assertStatus(422)->assertJsonPath('errors.code', 'form_private');
});

it('turns pasted questions into a draft program with round 1 questions, all unconfirmed until edited', function () {
    $text = "Seed Fund Application\n1. What is your business name? *\n2. Describe your impact\n3. Which sector? Agri / Health / Education *\n4. When did you start?";
    $r = $this->po()->postJson('/api/v1/program-imports', ['source' => 'text', 'type' => 'form', 'text' => $text])->assertCreated();
    $id = $r->json('data.id');
    $q = $r->json('data.form_spec.questions');
    expect(array_column($q, 'type'))->toBe(['text', 'paragraph', 'choice', 'date'])->and($q[0]['required'])->toBeTrue();

    $this->po()->patchJson("/api/v1/program-imports/$id/form-spec", [
        'program' => $this->programRow(['program_ref' => null, 'program_type' => 'single_round', 'total_rounds' => 1, 'program_title' => 'Seed Fund']),
        'questions' => array_merge(array_slice($q, 0, 2), [['label' => 'Skip me', 'type' => 'text', 'include' => false]]),
    ])->assertOk();
    $this->po()->postJson("/api/v1/program-imports/$id/validate")->assertStatus(202);
    $ready = $this->po()->postJson("/api/v1/program-imports/$id/readiness")->assertOk()->json('data');
    expect($ready['blockers'])->toBe([]);
    $this->po()->postJson("/api/v1/program-imports/$id/commit", ['confirm_data_rights' => true])->assertStatus(202);

    $p = \App\Models\Programs\Program::first();
    expect($p->status)->toBe('draft')->and($p->visible)->toBeFalse()->and(\App\Models\Programs\Rounds\ProgramRound::first()->status)->toBe('draft')
        ->and(\App\Models\Programs\Rounds\RoundCustomQuestion::count())->toBe(2)->and(\App\Models\Programs\ProgramApplication::count())->toBe(0);
    $this->assertSilent();
});

it('accepts Windows-1252 CSV with a BOM and blank rows', function () {
    $csv = "\xEF\xBB\xBFapplication_ref,program_ref,round_number,startup_name,sector,headquarters_location,total_amount_requested\r\n\r\nA1,P1,1,Caf\xE9 Nairobi,Agri,Nairobi,100000\r\n";
    $f = tempnam(sys_get_temp_dir(), 'c').'.csv';
    file_put_contents($f, $csv);
    $b = $this->newBatch('applications', $f, [], null, 'applications');
    (new BatchStager)->parse($b->refresh());
    expect($b->refresh()->status)->toBe('needs_mapping')->and(\App\Modules\ProgramImport\Models\ImportRow::where('batch_id', $b->id)->first()->raw['startup_name'])->toBe('Café Nairobi');
});
