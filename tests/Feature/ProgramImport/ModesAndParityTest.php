<?php

use App\Models\Auth\User;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\ProgramWallet;
use App\Models\Programs\Rounds\ProgramRound;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Silent\ImportContext;
use App\Modules\ProgramImport\Silent\OutboundGuard;
use Tests\Feature\ProgramImport\ImportTestCase;

uses(ImportTestCase::class);

function pi_bundle($t, array $o = [], int $round = 2): array
{
    return ['program' => [$t->programRow()], 'rounds' => $t->roundRows(), 'applications' => $t->appRows($round)] + $o;
}

function pi_run($t, array $sheets, array $options = [], string $sp = 'review', ?int $programId = null)
{
    $b = $t->newBatch($sp, $t->xlsx($sheets), $options, $programId);
    $t->prepare($b);

    return $t->commit($b);
}

it('on_duplicate=fail rejects already imported rows, update refreshes them', function () {
    pi_run($this, pi_bundle($this));
    $b = pi_run($this, pi_bundle($this), ['on_duplicate' => 'fail']);
    expect(ImportRow::where('batch_id', $b->id)->where('status', 'invalid')->count())->toBe(5)->and(Program::count())->toBe(1);

    $sheets = pi_bundle($this);
    $sheets['program'][0]['program_title'] = 'Youth Fund 2';
    pi_run($this, $sheets, ['on_duplicate' => 'update']);
    expect(Program::first()->program_title)->toBe('Youth Fund 2')->and(Program::count())->toBe(1);
});

it('never touches manual records unless link_existing and update_existing are on, and never moves them backward', function () {
    $p = Program::factory()->create(['user_id' => $this->orgUser->id, 'organization_id' => $this->org->id, 'status' => 'awarded', 'program_type' => 'single_round', 'total_rounds' => 1]);
    $r = ProgramRound::factory()->create(['program_id' => $p->id, 'round_number' => 1, 'status' => 'finalized']);
    $applicant = User::factory()->create(['user_type_id' => 1, 'email' => 'amina@example.com']);
    $a = ProgramApplication::factory()->create(['program_id' => $p->id, 'user_id' => $applicant->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $r->id,
        'status' => 'awarded', 'round_status' => 'advanced', 'startup_name' => 'Agro One', 'email' => 'amina@example.com', 'contact_person_email' => 'amina@example.com', 'stage' => 'manual-stage']);

    $apps = [['application_ref' => 'A1', 'round_number' => 1, 'startup_name' => 'Agro One', 'email' => 'amina@example.com', 'sector' => 'Agri', 'headquarters_location' => 'Nairobi', 'total_amount_requested' => 1000, 'stage' => 'import-stage']];
    $round = [['round_number' => 1, 'round_name' => 'Application', 'open_date' => '2026-01-01', 'close_date' => '2026-02-01', 'review_period_end' => '2026-02-10']];

    // 1. existing user + same business, defaults: the application is a conflict, not silently overwritten
    $b = pi_run($this, ['applications' => $apps], [], 'review', $p->id);
    expect($b->stats['commit']['failed'])->toBe(1)->and($a->fresh()->stage)->toBe('manual-stage')->and(ProgramApplication::count())->toBe(1);

    // 2. link_existing without update_existing: linked, nothing changed
    pi_run($this, ['applications' => $apps], ['link_existing' => true], 'review', $p->id);
    expect($a->fresh()->stage)->toBe('manual-stage')->and($a->fresh()->status)->toBe('awarded');

    // 3. update_existing: fields may change but the awarded application is never moved back to pending
    pi_run($this, ['applications' => $apps], ['link_existing' => true, 'update_existing' => true, 'on_duplicate' => 'update'], 'review', $p->id);
    expect($a->fresh()->status)->toBe('awarded')->and($a->fresh()->round_status)->toBe('advanced')->and(Program::first()->status)->toBe('awarded');
});

it('all_or_nothing saves nothing when any row fails; partial saves the rest', function () {
    $sheets = pi_bundle($this);
    $sheets['applications'][1]['round_number'] = 1;
    $sheets['applications'][1]['email'] = 'amina@example.com';   // same applicant twice in the program
    $b = pi_run($this, $sheets, ['mode' => 'all_or_nothing']);
    expect($b->status)->toBe('failed')->and(Program::count())->toBe(0)->and(ProgramApplication::count())->toBe(0)->and($b->error_summary['message'])->toContain('already has an application');

    $b = pi_run($this, $sheets, ['mode' => 'partial']);
    expect($b->status)->toBe('completed_with_errors')->and(ProgramApplication::count())->toBe(1)->and(Program::count())->toBe(1);
});

it('enforces max awardees unless raised, and records winners without touching the wallet', function () {
    $w = fn ($n) => ['application_ref' => "W$n", 'program_ref' => 'P1', 'startup_name' => "Biz $n", 'email' => "w$n@example.com", 'awarded_amount' => 100000, 'sector' => 'Agri', 'headquarters_location' => 'Nairobi'];
    $sheets = ['program' => [$this->programRow(['max_awardees' => 1])], 'winners' => [$w(1), $w(2)]];
    $b = $this->newBatch('funding_setup', $this->xlsx($sheets));
    expect(array_column($this->prepare($b)['blockers'], 'code'))->toContain('over_max_awardees');

    $b = pi_run($this, $sheets, ['raise_max_awardees' => true], 'funding_setup');
    expect($b->status)->toBe('completed')->and(ProgramApplication::where('status', 'awarded')->count())->toBe(2)->and(Program::first()->max_awardees)->toBe(2)
        ->and((float) ProgramWallet::first()->balance)->toBe(0.0)->and(ProgramWallet::first()->status)->toBe('inactive');
});

it('suppresses messages to unclaimed imported accounts and placeholders, but not to claimed or normal ones', function () {
    $apps = pi_bundle($this)['applications'];
    $apps[1]['email'] = null;                                  // placeholder
    pi_run($this, ['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $apps]);
    OutboundGuard::flush();
    $shadow = User::where('email', 'amina@example.com')->first();
    $placeholder = User::where('email', 'like', 'import+%@placeholder.invalid')->first();
    $normal = User::factory()->create(['user_type_id' => 1]);

    expect($shadow->user_type_id)->toBe(1)->and($shadow->password)->toBeNull()->and($shadow->email_verified_at)->toBeNull()
        ->and(OutboundGuard::shouldSuppress($shadow))->toBeTrue()->and(OutboundGuard::shouldSuppress($shadow->email))->toBeTrue()
        ->and(OutboundGuard::shouldSuppress($placeholder))->toBeTrue()->and(OutboundGuard::shouldSuppress($normal))->toBeFalse()
        ->and(ImportContext::isSilent())->toBeFalse();
    ImportContext::inviting(function () use ($shadow, $placeholder) {
        expect(OutboundGuard::shouldSuppress($shadow))->toBeFalse()->and(OutboundGuard::shouldSuppress($placeholder))->toBeTrue();
    });
    expect(ImportContext::silently(fn () => OutboundGuard::shouldSuppress($normal)))->toBeTrue();

    // core notification code really is blocked for them, and real for the normal user
    (new \App\Service\Notification\ProgramNotificationService)->send('round.advanced', [$shadow, $placeholder], ['program_title' => 'X', 'round_name' => 'R']);
    expect(\App\Models\Communication\Notifications::count())->toBe(0);
    (new \App\Service\Notification\ProgramNotificationService)->send('round.advanced', [$normal], ['program_title' => 'X', 'round_name' => 'R']);
    expect(\App\Models\Communication\Notifications::where('receiver_id', $normal->id)->count())->toBe(1);
    // once the applicant sets a password the account is theirs
    $shadow->forceFill(['password' => bcrypt('Secret123')])->save();
    OutboundGuard::flush();
    expect(OutboundGuard::shouldSuppress($shadow))->toBeFalse();
});

it('sends claim invitations only to real imported emails', function () {
    $apps = pi_bundle($this)['applications'];
    $apps[1]['email'] = null;
    pi_run($this, ['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $apps]);
    $this->fakeQueue = false;
    \Illuminate\Support\Facades\Mail::fake();
    $res = $this->po()->postJson('/api/v1/program-imports/programs/'.Program::first()->id.'/invitations')->assertOk()->json('data');
    expect($res['sent'])->toBe(1)->and($res['placeholders_not_contacted'])->toBe(1)
        ->and(User::where('email', 'amina@example.com')->value('invitation_token_hash'))->not->toBeNull()
        ->and(User::where('email', 'like', 'import+%')->value('invitation_token_hash'))->toBeNull();
    $manual = Program::factory()->create(['user_id' => $this->orgUser->id, 'organization_id' => $this->org->id]);
    $this->po()->postJson("/api/v1/program-imports/programs/{$manual->id}/invitations")->assertStatus(422)->assertJsonPath('errors.code', 'not_imported');
});

/* ---------- parity: manual endpoint vs import, persisted attributes compared ---------- */

function pi_attrs($model, array $skip = []): array
{
    $skip = array_merge(['id', 'created_at', 'updated_at', 'uid', 'public_id'], $skip);

    return collect($model->fresh()->getAttributes())->except($skip)->map(fn ($v) => is_float($v) || is_numeric($v) ? (string) (float) $v : $v)->all();
}

it('parity: a program created by import matches a manually created one except the documented differences', function () {
    // Manual: the real endpoint (needs a verified organization; created like ProgramTestCase does)
    \App\Models\Kyc\KycVerification::create(['user_id' => $this->orgUser->id, 'organization_id' => $this->org->id, 'verification_type' => 'organization', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);
    $industry = \App\Models\ProgramIndustry::first();
    $res = $this->po()->postJson('/api/v1/programs/create-program', [
        'program_title' => 'Parity Fund', 'program_industry_id' => $industry->id, 'total_program_amount' => 400000, 'funding_per_business' => 100000,
        'disbursement_type' => 'supplier', 'program_type' => 'multi_round', 'total_rounds' => 2, 'currency' => 'KES',
        'application_start_date' => now()->addDay()->toDateString(), 'application_deadline' => now()->addMonth()->toDateString(),
    ]);
    $res->assertOk();
    $manual = Program::where('program_title', 'Parity Fund')->first();

    $row = $this->programRow(['program_title' => 'Parity Fund', 'application_start_date' => $manual->application_start_date->toDateString(), 'application_deadline' => $manual->application_deadline->toDateString()]);
    $b = pi_run($this, ['program' => [$row], 'rounds' => $this->roundRows()], [], 'setup');
    $imported = Program::where('id', '!=', $manual->id)->first();

    $skip = ['program_title'];
    $diff = array_diff_assoc(pi_attrs($imported, $skip), pi_attrs($manual, $skip));
    // Documented: imported programs are hidden (manual default visible=true); wallet identical; everything else equal.
    expect($diff)->toBe(['visible' => '0'])->and($imported->visible)->toBeFalse();
    expect(pi_attrs(ProgramWallet::where('program_id', $imported->id)->first(), ['program_id']))->toBe(pi_attrs(ProgramWallet::where('program_id', $manual->id)->first(), ['program_id']));
});

it('parity: an applicant landed at review matches one who applied through the real endpoint', function () {
    $program = Program::factory()->create(['user_id' => $this->orgUser->id, 'organization_id' => $this->org->id, 'status' => 'published', 'program_type' => 'single_round', 'total_rounds' => 1,
        'application_start_date' => now()->subDay()->toDateString(), 'application_deadline' => now()->addMonth()->toDateString(), 'funding_per_business' => 100000, 'total_program_amount' => 400000, 'use_match_score' => false]);
    $round = ProgramRound::factory()->create(['program_id' => $program->id, 'round_number' => 1, 'status' => 'published', 'open_date' => now()->subDay()->toDateString(), 'close_date' => now()->addMonth()->toDateString()]);
    $applicant = User::factory()->create(['user_type_id' => 1, 'email' => 'manual@example.com']);
    $res2 = $this->actingAs($applicant, 'sanctum')->postJson("/api/v1/programs/{$program->id}/applications", [
        'startup_name' => 'Agro One', 'contact_person_name' => 'Amina Ali', 'contact_person_email' => 'manual@example.com', 'sector' => 'Agri',
        'headquarters_location' => 'Nairobi', 'total_amount_requested' => 100000,
    ]);
    $manual = ProgramApplication::where('program_id', $program->id)->first();

    $apps = [['application_ref' => 'A1', 'round_number' => 1, 'startup_name' => 'Agro One', 'contact_person_name' => 'Amina Ali', 'email' => 'import@example.com', 'sector' => 'Agri', 'headquarters_location' => 'Nairobi', 'total_amount_requested' => 100000]];
    pi_run($this, ['applications' => $apps], [], 'review', $program->id);
    $imported = ProgramApplication::where('program_id', $program->id)->where('id', '!=', $manual->id)->first();

    $skip = ['user_id', 'email', 'contact_person_email', 'submitted_at', 'current_round_id', 'match_score', 'score_breakdown'];
    expect(array_diff_assoc(pi_attrs($imported, $skip), pi_attrs($manual, $skip)))->toBe([]);
    $h = fn ($a) => \Illuminate\Support\Facades\DB::table('application_round_history')->where('application_id', $a->id)->get(['round_number', 'outcome'])->map(fn ($x) => (array) $x)->all();
    expect($h($imported))->toBe($h($manual));
});

it('parity: imported active milestones match what activating templates produces', function () {
    $winners = [['application_ref' => 'W1', 'program_ref' => 'P1', 'startup_name' => 'Agro One', 'email' => 'a@example.com', 'awarded_amount' => 100000, 'sector' => 'Agri', 'headquarters_location' => 'Nairobi']];
    $ms = [['application_ref' => 'W1', 'milestone_ref' => 'M1', 'sequence_order' => 1, 'title' => 'Equipment', 'description' => 'Buy', 'amount' => 60000], ['application_ref' => 'W1', 'milestone_ref' => 'M2', 'sequence_order' => 2, 'title' => 'Launch', 'description' => 'Go', 'amount' => 40000]];
    pi_run($this, ['program' => [$this->programRow()], 'winners' => $winners, 'milestones' => $ms], [], 'disbursement');
    $imported = ProgramMilestone::orderBy('sequence_order')->get();

    $a2 = ProgramApplication::factory()->create(['program_id' => Program::first()->id, 'user_id' => User::factory()->create(['user_type_id' => 1])->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'awarded', 'funding_setup_status' => 'not_started', 'total_amount_requested' => 100000, 'planning_mode' => 'locked']);
    foreach ([['Equipment', 'Buy', 60000], ['Launch', 'Go', 40000]] as [$t, $d, $amt]) {
        $this->po()->postJson("/api/v1/programs/applications/{$a2->id}/milestones/templates", ['title' => $t, 'description' => $d, 'amount' => $amt])->assertSuccessful();
    }
    $this->po()->postJson("/api/v1/programs/applications/{$a2->id}/milestones/activate")->assertOk();
    $manual = ProgramMilestone::where('app_id', $a2->id)->orderBy('sequence_order')->get();

    foreach ([0, 1] as $i) {
        $skip = ['app_id', 'completed_at', 'approved_at', 'document', 'verification_questions', 'estimated_completion_date', 'duration_days'];
        expect(array_diff_assoc(pi_attrs($imported[$i], $skip), pi_attrs($manual[$i], $skip)))->toBe([]);
    }
    expect(ProgramApplication::find($a2->id)->funding_setup_status)->toBe(ProgramApplication::where('startup_name', 'Agro One')->value('funding_setup_status'));
});

it('leaves the platform untouched when the module is disabled', function () {
    $this->artisan('migrate:status')->assertExitCode(0);
    expect(config('program_imports.enabled'))->toBeTrue();   // enabled for this suite; the provider test below proves the off state
});
