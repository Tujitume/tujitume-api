<?php

use App\Models\Auth\User;
use App\Models\Programs\ProgramApplication;
use App\Modules\ProgramImport\Models\ImportBatch;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\ProgramImport\ApiImportTestCase;

uses(ApiImportTestCase::class);

function pi_pending($t): array
{
    Cache::flush();
    $res = $t->po()->getJson('/api/v1/pending-tasks?fresh=1')->assertOk();

    return collect($res->json('data.groups'))->flatMap(fn ($g) => $g['items'])->keyBy('type')->all();
}

function pi_import_with_failures($t): ImportBatch
{
    $apps = $t->appRows(2);
    $apps[1]['email'] = 'amina@example.com';           // the same applicant twice: the second row cannot be saved
    $b = $t->newBatch('review', $t->xlsx(['program' => [$t->programRow()], 'rounds' => $t->roundRows(), 'applications' => $apps]));
    $t->prepare($b);

    return $t->commit($b);
}

it('puts rows that could not be saved in the pending list, explains why, and lets the owner fix and mark them handled', function () {
    $b = pi_import_with_failures($this);
    expect($b->status)->toBe('completed_with_errors')->and($b->stats['commit']['failure_reasons'][0])->toMatchArray(['sheet' => 'applications', 'count' => 1]);
    expect($b->stats['commit']['failure_reasons'][0]['message'])->toContain('already has an application');

    $item = pi_pending($this)['import.failed_rows'];
    expect($item['title'])->toBe('Some rows from your import were not saved')->and($item['subtitle'])->toContain('1 row(s)')->and($item['link'])->toBe("dashboard.programOrg.programsBulkUpload?batch={$b->id}");

    $csv = $this->po()->get("/api/v1/program-imports/{$b->id}/failed-rows?sheet=applications")->assertOk()->streamedContent();
    expect($csv)->toContain('startup_name')->toContain('problem')->toContain('already has an application')->toContain('Agro Two');

    $this->po()->postJson("/api/v1/program-imports/{$b->id}/handled")->assertOk();
    expect(pi_pending($this))->not->toHaveKey('import.failed_rows');
    $this->po()->get("/api/v1/program-imports/{$b->id}/failed-rows?sheet=nope")->assertStatus(200);   // an unknown sheet just has no rows
});

it('shows a failed import and a stalled one, and trying again finishes the job without writing anything twice', function () {
    $b = $this->newBatch('review', $this->xlsx(['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $this->appRows(2)]));
    $this->prepare($b);
    $b->update(['status' => 'failed', 'consent_confirmed_at' => now(), 'error_summary' => ['message' => 'The import stopped unexpectedly.']]);
    $item = pi_pending($this)['import.failed'];
    expect($item['title'])->toBe('Your import did not finish')->and($item['subtitle'])->toContain('stopped unexpectedly');

    $this->po()->postJson("/api/v1/program-imports/{$b->id}/retry")->assertStatus(202);
    $b->refresh();
    expect($b->status)->toBe('completed')->and(ProgramApplication::count())->toBe(2);
    expect(pi_pending($this))->not->toHaveKey('import.failed');
    $this->po()->postJson("/api/v1/program-imports/{$b->id}/retry")->assertStatus(422)->assertJsonPath('errors.code', 'invalid_state');
    expect(ProgramApplication::count())->toBe(2);

    $stalled = ImportBatch::create(['organization_id' => $this->org->id, 'user_id' => $this->orgUser->id, 'type' => 'review', 'start_point' => 'review', 'source' => 'file', 'status' => 'importing', 'original_filename' => 'big.xlsx']);
    ImportBatch::where('id', $stalled->id)->update(['updated_at' => now()->subMinutes(30)]);
    expect(pi_pending($this)['import.stuck']['subtitle'])->toContain('big.xlsx')->toContain('Try again');
});

it('reminds the owner to invite imported applicants until they have been invited', function () {
    $b = $this->newBatch('review', $this->xlsx(['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $this->appRows(2)]));
    $this->prepare($b);
    $this->commit($b);
    expect(pi_pending($this)['import.invite']['subtitle'])->toContain('2 applicant(s)');
    $pid = \App\Models\Programs\Program::first()->id;
    $this->po()->postJson("/api/v1/program-imports/programs/$pid/invitations")->assertOk();
    expect(pi_pending($this))->not->toHaveKey('import.invite');
});

it('flags awardees without a wallet and milestones without a supplier, and they clear once fixed', function () {
    $winners = [['application_ref' => 'W1', 'program_ref' => 'P1', 'startup_name' => 'Agro One', 'email' => 'amina@example.com', 'awarded_amount' => 100000, 'sector' => 'Agri', 'headquarters_location' => 'Nairobi']];
    $ms = [['application_ref' => 'W1', 'milestone_ref' => 'M1', 'sequence_order' => 1, 'title' => 'Equipment', 'amount' => 100000]];
    $b = $this->newBatch('disbursement', $this->xlsx(['program' => [$this->programRow(['disbursement_type' => 'hybrid'])], 'winners' => $winners, 'milestones' => $ms]));
    $this->prepare($b);
    $this->commit($b);
    $p = pi_pending($this);
    expect($p['import.wallet']['subtitle'])->toContain('1 awardee(s)')->and($p['import.supplier']['subtitle'])->toContain('1 milestone(s)');

    User::where('email', 'amina@example.com')->update(['lipr_wallet' => '0700000001']);
    $m = \App\Models\Programs\ProgramMilestone::first();
    $supplier = \App\Models\Programs\SupplierDirectory::create(['added_by' => $this->orgUser->id, 'legal_name' => 'Tools', 'payment_method' => 'other', 'is_active' => true]);
    \App\Models\Programs\MilestoneSupplier::create(['milestone_id' => $m->id, 'supplier_id' => $supplier->id, 'assignment_type' => 'approved', 'payment_route' => 'direct_to_supplier', 'added_by' => 'program_owner']);
    $p = pi_pending($this);
    expect($p)->not->toHaveKey('import.wallet')->and($p)->not->toHaveKey('import.supplier');
});

it('stays out of the pending list for other organizations', function () {
    pi_import_with_failures($this);
    $other = User::factory()->create(['user_type_id' => 4]);
    $org = \App\Models\Organizations\Organization::factory()->create(['owner_user_id' => $other->id]);
    $other->update(['organization_id' => $org->id]);
    Cache::flush();
    $res = $this->actingAs($other, 'sanctum')->getJson('/api/v1/pending-tasks?fresh=1')->assertOk();
    expect(collect($res->json('data.groups'))->flatMap(fn ($g) => collect($g['items'])->pluck('type'))->all())->not->toContain('import.failed_rows');
});
