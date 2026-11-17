<?php

use App\Models\Auth\User;
use App\Models\Communication\Notifications;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ProgramImport\ApiImportTestCase;

uses(ApiImportTestCase::class);

it('never sends closing reminders for an imported round, but still does for a normal one', function () {
    $in3 = now()->addDays(3)->toDateString();
    $rounds = [['program_ref' => 'P1', 'round_number' => 1, 'round_name' => 'Application', 'open_date' => '2026-01-01', 'close_date' => $in3, 'review_period_end' => $in3]];
    $b = $this->newBatch('review', $this->xlsx(['program' => [$this->programRow(['program_type' => 'single_round', 'total_rounds' => 1])], 'rounds' => $rounds, 'applications' => $this->appRows(1)]));
    $this->prepare($b);
    $this->commit($b);
    User::whereIn('email', ['amina@example.com', 'bo@example.com'])->each(fn ($u) => $u->forceFill(['password' => bcrypt('Secret123')])->save());   // claimed: they could receive messages
    \App\Modules\ProgramImport\Silent\OutboundGuard::flush();
    expect(ProgramRound::first()->status)->toBe('published');

    $this->artisan('update:program-status')->assertExitCode(0);
    expect(Notifications::count())->toBe(0);

    // control: a normal (not imported) published round closing in 3 days does notify its applicant
    $p = Program::factory()->create(['user_id' => $this->orgUser->id, 'organization_id' => $this->org->id, 'status' => 'open']);
    $r = ProgramRound::factory()->create(['program_id' => $p->id, 'round_number' => 1, 'status' => 'published', 'close_date' => $in3]);
    $applicant = User::factory()->create(['user_type_id' => 1]);
    ProgramApplication::factory()->create(['program_id' => $p->id, 'user_id' => $applicant->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $r->id]);
    $this->artisan('update:program-status')->assertExitCode(0);
    expect(Notifications::where('receiver_id', $applicant->id)->count())->toBe(1);
});

it('removes the attachments ZIP on cancel and on purge, not only the data file', function () {
    Storage::fake('s3');
    $make = function () {
        $zip = tempnam(sys_get_temp_dir(), 'z').'.zip';
        $z = new ZipArchive;
        $z->open($zip, ZipArchive::CREATE);
        $z->addFromString('deck.pdf', '%PDF-1.4');
        $z->close();
        $id = $this->po()->post('/api/v1/program-imports', ['start_point' => 'review', 'source' => 'file', 'file' => new UploadedFile($this->xlsx(['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $this->appRows(2)]), 'd.xlsx', null, null, true), 'attachments' => new UploadedFile($zip, 'a.zip', null, null, true)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return ImportBatch::find($id);
    };
    $b = $make();
    $zipPath = $b->options['attachments_path'];
    Storage::disk('local')->assertExists($zipPath);
    $this->po()->deleteJson("/api/v1/program-imports/{$b->id}")->assertOk();
    Storage::disk('local')->assertMissing($zipPath);
    Storage::disk('local')->assertMissing($b->file_path);
    expect(ImportBatch::find($b->id)->options)->not->toHaveKey('attachments_path');

    $b2 = $make();
    $zip2 = $b2->options['attachments_path'];
    ImportBatch::query()->update(['updated_at' => now()->subDays(60)]);
    $this->artisan('program-imports:purge')->assertExitCode(0);
    Storage::disk('local')->assertMissing($zip2);
    expect(ImportBatch::find($b2->id)->file_path)->toBeNull()->and(ImportBatch::find($b2->id)->options)->not->toHaveKey('attachments_path');
});

it('blocks a rollback when an imported account has gained other data, and never deletes it', function () {
    $b = $this->newBatch('review', $this->xlsx(['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $this->appRows(2)]));
    $this->prepare($b);
    $this->commit($b);
    $shadow = User::where('email', 'amina@example.com')->first();
    \App\Models\Kyc\KycVerification::create(['user_id' => $shadow->id, 'verification_type' => 'entrepreneur', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);
    $this->po()->postJson("/api/v1/program-imports/{$b->id}/rollback")->assertStatus(422)->assertJsonPath('errors.code', 'rollback_blocked')
        ->assertJsonFragment(['code' => 'user_referenced']);
    expect(User::where('email', 'amina@example.com')->exists())->toBeTrue()->and(ProgramApplication::count())->toBe(2);

    \App\Models\Kyc\KycVerification::where('user_id', $shadow->id)->delete();
    $this->po()->postJson("/api/v1/program-imports/{$b->id}/rollback")->assertOk();
    expect(User::where('email', 'amina@example.com')->exists())->toBeFalse();
});

it('imports into a chosen round of an existing program by round_id or round_number', function () {
    $p = Program::factory()->create(['user_id' => $this->orgUser->id, 'organization_id' => $this->org->id, 'status' => 'published', 'program_type' => 'multi_round', 'total_rounds' => 2, 'funding_per_business' => 100000, 'total_program_amount' => 400000]);
    $r1 = ProgramRound::factory()->create(['program_id' => $p->id, 'round_number' => 1, 'status' => 'finalized']);
    $r2 = ProgramRound::factory()->create(['program_id' => $p->id, 'round_number' => 2, 'status' => 'published']);
    $apps = array_map(function ($a) { unset($a['round_number'], $a['program_ref']); return $a; }, $this->appRows(2));
    $make = fn (array $extra) => $this->po()->post('/api/v1/program-imports', ['type' => 'applications', 'source' => 'file', 'program_id' => $p->id, 'file' => new UploadedFile($this->xlsx(['applications' => $apps]), 'a.xlsx', null, null, true)] + $extra, ['Accept' => 'application/json']);

    $id = $make(['round_id' => $r2->id])->assertCreated()->json('data.id');
    expect(ImportBatch::find($id)->options['round_number'])->toBe(2);
    $this->po()->postJson("/api/v1/program-imports/$id/analyze")->assertStatus(202);
    $this->po()->postJson("/api/v1/program-imports/$id/validate")->assertStatus(202);
    $this->po()->postJson("/api/v1/program-imports/$id/commit", ['confirm_data_rights' => true])->assertStatus(202);
    expect(ProgramApplication::where('program_id', $p->id)->pluck('current_round_id')->unique()->all())->toBe([$r2->id]);

    $make(['round_number' => 2])->assertCreated();
    $other = Program::factory()->create(['user_id' => $this->orgUser->id, 'organization_id' => $this->org->id]);
    $make(['round_id' => ProgramRound::factory()->create(['program_id' => $other->id, 'round_number' => 1])->id])->assertStatus(404)->assertJsonPath('errors.code', 'round_not_found');
    $this->po()->post('/api/v1/program-imports', ['type' => 'applications', 'source' => 'file', 'round_number' => 2, 'file' => new UploadedFile($this->xlsx(['applications' => $apps]), 'a.xlsx', null, null, true)], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.code', 'program_required');
});

it('reads a form straight through the Google API source, and says so when Google is not configured or connected', function () {
    $body = ['source' => 'google_api', 'url' => 'https://docs.google.com/forms/d/1FAIpQLSabcdefghijk/edit'];
    $this->po()->postJson('/api/v1/program-imports', $body)->assertStatus(422)->assertJsonPath('errors.code', 'google_not_configured');
    config(['program_imports.google.oauth_enabled' => true, 'services.google.client_id' => 'cid', 'services.google.client_secret' => 's']);
    $this->po()->postJson('/api/v1/program-imports', $body)->assertStatus(422)->assertJsonPath('errors.code', 'google_not_connected');
    ImportToken::create(['organization_id' => $this->org->id, 'user_id' => $this->orgUser->id, 'provider' => 'google', 'access_token' => 'T', 'expires_at' => now()->addHour()]);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(['forms.googleapis.com/*' => Http::response(['info' => ['title' => 'Private Fund'], 'items' => [['title' => 'Business name', 'questionItem' => ['question' => ['required' => true, 'textQuestion' => []]]]]])]);
    $r = $this->po()->postJson('/api/v1/program-imports', $body)->assertCreated();
    expect($r->json('data.form_spec.title'))->toBe('Private Fund')->and($r->json('data.type'))->toBe('form')->and($r->json('data.source'))->toBe('google_api');
});
