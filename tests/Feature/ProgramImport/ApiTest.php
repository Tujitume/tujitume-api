<?php

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Modules\ProgramImport\Models\ImportBatch;
use Illuminate\Http\UploadedFile;
use Tests\Feature\ProgramImport\ApiImportTestCase;

uses(ApiImportTestCase::class);

function pi_upload($t, string $startPoint, string $file, array $extra = [])
{
    return $t->po()->post('/api/v1/program-imports', ['start_point' => $startPoint, 'source' => 'file', 'file' => new UploadedFile($file, basename($file), null, null, true)] + $extra, ['Accept' => 'application/json']);
}

function pi_flow($t, array $sheets, string $sp = 'review'): array
{
    $r = pi_upload($t, $sp, $t->xlsx($sheets))->assertCreated();
    $id = $r->json('data.id');
    $t->po()->postJson("/api/v1/program-imports/$id/analyze")->assertStatus(202);
    $t->po()->postJson("/api/v1/program-imports/$id/validate")->assertStatus(202);

    return [$id, $t->po()->postJson("/api/v1/program-imports/$id/readiness")->assertOk()->json('data')];
}

it('runs the whole wizard over HTTP: upload, analyze, validate, readiness, commit', function () {
    [$id, $ready] = pi_flow($this, ['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $this->appRows(2)]);
    expect($ready['ready'])->toBeTrue()->and($ready['landing_preview']['program']['status'])->toBe('under_review');

    $this->po()->postJson("/api/v1/program-imports/$id/commit", [])->assertStatus(422)->assertJsonPath('errors.code', 'consent_required');
    $this->po()->postJson("/api/v1/program-imports/$id/commit", ['confirm_data_rights' => true])->assertStatus(202);

    $b = ImportBatch::find($id);
    expect($b->status)->toBe('completed')->and($b->consent_confirmed_at)->not->toBeNull()->and(Program::count())->toBe(1)->and(ProgramApplication::count())->toBe(2);
    $this->assertSilent();
    $this->po()->getJson('/api/v1/program-imports')->assertOk()->assertJsonPath('data.items.0.id', $id);
    $this->po()->getJson("/api/v1/program-imports/$id/rows?status=imported")->assertOk()->assertJsonPath('data.meta.total', 5);
});

it('refuses a commit while readiness has blockers', function () {
    [$id, $ready] = pi_flow($this, ['program' => [$this->programRow()], 'rounds' => $this->roundRows()], 'review');
    expect($ready['ready'])->toBeFalse();
    $this->po()->postJson("/api/v1/program-imports/$id/commit", ['confirm_data_rights' => true])->assertStatus(422)->assertJsonPath('errors.code', 'readiness_blocked');
    expect(Program::count())->toBe(0);
});

it('keeps imports inside the organization and respects roles', function () {
    [$id] = pi_flow($this, ['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $this->appRows(2)]);

    $other = User::factory()->create(['user_type_id' => 4]);
    $org2 = Organization::factory()->create(['owner_user_id' => $other->id]);
    $other->update(['organization_id' => $org2->id]);
    $this->actingAs($other, 'sanctum')->getJson("/api/v1/program-imports/$id")->assertNotFound();
    $this->actingAs($other, 'sanctum')->postJson("/api/v1/program-imports/$id/commit", ['confirm_data_rights' => true])->assertNotFound();
    $this->actingAs($other, 'sanctum')->getJson('/api/v1/program-imports')->assertOk()->assertJsonPath('data.meta.total', 0);

    $applicant = User::factory()->create(['user_type_id' => 1]);
    $this->actingAs($applicant, 'sanctum')->getJson('/api/v1/program-imports/start-points')->assertForbidden();
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $this->getJson('/api/v1/program-imports/start-points')->assertUnauthorized();
});

it('serves the catalog, types and templates', function () {
    $sp = $this->po()->getJson('/api/v1/program-imports/start-points')->assertOk()->json('data');
    expect(array_column($sp, 'key'))->toBe(['setup', 'review', 'finalization', 'funding_setup', 'disbursement', 'monitoring'])->and($sp[1]['next_steps'])->not->toBeEmpty();
    $types = $this->po()->getJson('/api/v1/program-imports/types')->assertOk()->json('data.types');
    expect(array_column($types, 'type'))->toContain('program', 'milestones', 'checkpoints');
    $this->po()->get('/api/v1/program-imports/templates/review?format=xlsx')->assertOk();
    $this->po()->get('/api/v1/program-imports/templates/milestones?format=csv')->assertOk();
    $this->po()->getJson('/api/v1/program-imports/templates/review?format=csv')->assertStatus(422)->assertJsonPath('errors.code', 'bundle_requires_xlsx');
    $this->po()->getJson('/api/v1/program-imports/templates/nope')->assertNotFound();
});

it('rejects bad files', function () {
    $f = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
    file_put_contents($f, 'not a zip');
    pi_upload($this, 'review', $f)->assertStatus(422)->assertJsonPath('errors.code', 'invalid_file');
    $g = tempnam(sys_get_temp_dir(), 'x').'.exe';
    file_put_contents($g, 'MZ');
    $this->po()->post('/api/v1/program-imports', ['start_point' => 'review', 'source' => 'file', 'file' => new UploadedFile($g, 'a.exe', null, null, true)], ['Accept' => 'application/json'])->assertStatus(422);
});

it('neutralizes formulas and html in stored values and in the error report', function () {
    $apps = $this->appRows(2);
    $apps[0]['startup_name'] = '=HYPERLINK("http://evil","x")';
    $apps[0]['traction_kpis'] = '<script>alert(1)</script>Growing';
    $apps[1]['total_amount_requested'] = '50k';
    [$id] = pi_flow($this, ['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $apps]);
    $this->po()->postJson("/api/v1/program-imports/$id/commit", ['confirm_data_rights' => true])->assertStatus(202);

    $a = ProgramApplication::first();
    expect($a->startup_name)->toStartWith("'=")->and($a->traction_kpis)->toBe('alert(1)Growing')->and(ProgramApplication::count())->toBe(1);
    $csv = $this->po()->get("/api/v1/program-imports/$id/errors")->assertOk()->streamedContent();
    expect($csv)->toContain('not a valid amount');
});

it('fixes a staged row and revalidates', function () {
    $apps = $this->appRows(2);
    $apps[1]['total_amount_requested'] = '80k';
    [$id, $ready] = pi_flow($this, ['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $apps]);
    $bad = $this->po()->getJson("/api/v1/program-imports/$id/rows?status=invalid")->json('data.items.0');
    expect($bad['errors'])->toHaveKey('total_amount_requested');
    $this->po()->patchJson("/api/v1/program-imports/$id/rows/{$bad['id']}", ['raw' => ['total_amount_requested' => '80000']])->assertOk();
    expect(ImportBatch::find($id)->status)->toBe('ready');
});

it('undoes a clean import and blocks the undo once something happened downstream', function () {
    [$id] = pi_flow($this, ['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $this->appRows(2)]);
    $this->po()->postJson("/api/v1/program-imports/$id/commit", ['confirm_data_rights' => true]);
    $this->po()->postJson("/api/v1/program-imports/$id/rollback")->assertOk();
    expect(Program::count())->toBe(0)->and(ProgramApplication::count())->toBe(0)->and(User::where('email', 'amina@example.com')->exists())->toBeFalse()
        ->and(ImportBatch::find($id)->status)->toBe('rolled_back');

    // same file again after rollback imports cleanly (links were removed)
    [$id2] = pi_flow($this, ['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $this->appRows(2)]);
    $this->po()->postJson("/api/v1/program-imports/$id2/commit", ['confirm_data_rights' => true]);
    expect(Program::count())->toBe(1);
    $a = ProgramApplication::first();
    $this->po()->postJson("/api/v1/programs/applications/{$a->id}/advance")->assertOk();
    $this->po()->postJson("/api/v1/program-imports/$id2/rollback")->assertStatus(422)->assertJsonPath('errors.code', 'rollback_blocked');
    expect(Program::count())->toBe(1);
});

it('cancels an uncommitted import and purges old data but keeps the ledger', function () {
    [$id] = pi_flow($this, ['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $this->appRows(2)]);
    $this->po()->deleteJson("/api/v1/program-imports/$id")->assertOk();
    expect(ImportBatch::find($id)->status)->toBe('cancelled');

    [$id2] = pi_flow($this, ['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $this->appRows(2)]);
    $this->po()->postJson("/api/v1/program-imports/$id2/commit", ['confirm_data_rights' => true]);
    $this->po()->deleteJson("/api/v1/program-imports/$id2")->assertStatus(422);
    ImportBatch::query()->update(['updated_at' => now()->subDays(40)]);
    $this->artisan('program-imports:purge')->assertExitCode(0);
    expect(\App\Modules\ProgramImport\Models\ImportRow::count())->toBe(0)->and(\App\Modules\ProgramImport\Models\ImportRecord::count())->toBeGreaterThan(0)->and(ImportBatch::find($id2)->file_path)->toBeNull();
});
