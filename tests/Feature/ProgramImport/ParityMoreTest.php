<?php

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\SupplierDirectory;
use Tests\Feature\ProgramImport\ImportTestCase;

uses(ImportTestCase::class);

function pi_p($model, array $skip = []): array
{
    $skip = array_merge(['id', 'created_at', 'updated_at', 'uid', 'public_id'], $skip);

    return collect($model->fresh()->getAttributes())->except($skip)->map(fn ($v) => is_numeric($v) ? (string) (float) $v : $v)->all();
}

function pi_import($t, array $sheets, string $sp, array $opts = [], ?int $programId = null)
{
    $b = $t->newBatch($sp, $t->xlsx($sheets), $opts, $programId);
    $t->prepare($b);

    return $t->commit($b);
}

function pi_manual_program($t, array $o = []): Program
{
    $p = Program::factory()->create($o + ['user_id' => $t->orgUser->id, 'organization_id' => $t->org->id, 'status' => 'published', 'program_type' => 'multi_round', 'total_rounds' => 2, 'funding_per_business' => 100000, 'total_program_amount' => 400000, 'currency' => 'KES']);
    KycVerification::create(['user_id' => $t->orgUser->id, 'organization_id' => $t->org->id, 'verification_type' => 'organization', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);

    return $p;
}

it('parity: a round created by import matches one created through the rounds endpoint', function () {
    $p = pi_manual_program($this, ['application_start_date' => '2026-01-01', 'application_deadline' => '2026-12-01']);
    ProgramRound::factory()->create(['program_id' => $p->id, 'round_number' => 1, 'status' => 'draft']);
    $res = $this->po()->postJson("/api/v1/programs/{$p->id}/rounds", [
        'round_number' => 2, 'round_name' => 'Pitch', 'open_date' => '2026-03-01', 'close_date' => '2026-04-01', 'review_period_end' => '2026-04-10', 'announcement_date' => '2026-04-20',
        'rubric_mode' => 'weighted', 'scoring_criteria' => [['name' => 'Impact', 'description' => '', 'weight' => 60, 'score_range' => 10], ['name' => 'Team', 'description' => '', 'weight' => 40, 'score_range' => 10]],
        'advancement_mode' => 'manual', 'assignment_type' => 'owner_only', 'assignment_method' => 'manual', 'min_reviewers_required' => 1,
    ]);
    $res->assertSuccessful();
    $manual = ProgramRound::where('program_id', $p->id)->where('round_number', 2)->first();

    $rows = [['program_ref' => 'P9', 'round_number' => 1, 'round_name' => 'Application', 'open_date' => '2026-01-01', 'close_date' => '2026-02-01'],
        ['program_ref' => 'P9', 'round_number' => 2, 'round_name' => 'Pitch', 'open_date' => '2026-03-01', 'close_date' => '2026-04-01', 'review_period_end' => '2026-04-10', 'announcement_date' => '2026-04-20',
            'rubric_mode' => 'weighted', 'scoring_criteria' => 'Impact:60:10; Team:40:10']];
    pi_import($this, ['program' => [$this->programRow(['program_ref' => 'P9', 'program_title' => 'Other'])], 'rounds' => $rows], 'setup');
    $imported = ProgramRound::where('program_id', '!=', $p->id)->where('round_number', 2)->first();
    $skip = ['program_id', 'confirmed_steps'];
    // Documented: setup lands rounds as draft (the manual endpoint also starts a round as draft), so every column must match.
    expect(array_diff_assoc(pi_p($imported, $skip), pi_p($manual, $skip)))->toBe([]);
});

it('parity: a score written by import equals one entered through the scoring endpoint, with the same average and eligibility', function () {
    $p = pi_manual_program($this, ['program_type' => 'single_round', 'total_rounds' => 1]);
    $r = ProgramRound::factory()->create(['program_id' => $p->id, 'round_number' => 1, 'status' => 'published', 'rubric_mode' => 'weighted', 'advancement_mode' => 'score_threshold', 'score_threshold' => 50,
        'scoring_criteria' => [['name' => 'Impact', 'description' => '', 'weight' => 60, 'score_range' => 10], ['name' => 'Team', 'description' => '', 'weight' => 40, 'score_range' => 10]], 'close_date' => now()->addMonth()->toDateString()]);
    $applicant = User::factory()->create(['user_type_id' => 1]);
    $app = ProgramApplication::factory()->create(['program_id' => $p->id, 'user_id' => $applicant->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $r->id, 'status' => 'pending', 'round_status' => 'submitted']);
    \Illuminate\Support\Facades\DB::table('application_round_history')->insert(['application_id' => $app->id, 'round_id' => $r->id, 'round_number' => 1, 'entered_at' => now(), 'submitted_at' => now(), 'outcome' => 'in_progress', 'created_at' => now(), 'updated_at' => now()]);
    $this->po()->postJson("/api/v1/programs/applications/{$app->id}/scores", ['criterion_scores' => [['criterion' => 'Impact', 'score' => 8], ['criterion' => 'Team', 'score' => 7]]])->assertStatus(201);
    $manualScore = ApplicationScore::where('application_id', $app->id)->first();

    $rounds = [['program_ref' => 'P9', 'round_number' => 1, 'round_name' => 'Application', 'open_date' => '2026-01-01', 'close_date' => '2026-02-01', 'review_period_end' => '2026-02-10', 'rubric_mode' => 'weighted', 'scoring_criteria' => 'Impact:60:10; Team:40:10', 'advancement_mode' => 'score_threshold', 'score_threshold' => 50]];
    $apps = [['application_ref' => 'A9', 'program_ref' => 'P9', 'round_number' => 1, 'startup_name' => 'Imp', 'email' => 'imp@example.com', 'sector' => 'Agri', 'headquarters_location' => 'Nairobi', 'total_amount_requested' => 1000]];
    $scores = [['application_ref' => 'A9', 'criterion' => 'Impact', 'criterion_score' => 8], ['application_ref' => 'A9', 'criterion' => 'Team', 'criterion_score' => 7]];
    pi_import($this, ['program' => [$this->programRow(['program_ref' => 'P9', 'program_type' => 'single_round', 'total_rounds' => 1])], 'rounds' => $rounds, 'applications' => $apps, 'scores' => $scores], 'finalization');
    $impScore = ApplicationScore::where('application_id', '!=', $app->id)->first();
    $skip = ['application_id', 'round_id', 'scored_at', 'review_started_at', 'review_completed_at'];
    expect(array_diff_assoc(pi_p($impScore, $skip), pi_p($manualScore, $skip)))->toBe([]);
    $impApp = ProgramApplication::where('startup_name', 'Imp')->first();
    foreach (['average_score', 'is_eligible_to_advance', 'round_status'] as $k) {
        expect((string) $impApp->$k)->toBe((string) $app->fresh()->$k);
    }
});

it('parity: an awardee landed by import matches one awarded through the real accept endpoint', function () {
    $p = pi_manual_program($this, ['program_type' => 'single_round', 'total_rounds' => 1]);
    $r = ProgramRound::factory()->create(['program_id' => $p->id, 'round_number' => 1, 'status' => 'published', 'advancement_mode' => 'manual']);
    $app = ProgramApplication::factory()->create(['program_id' => $p->id, 'user_id' => User::factory()->create(['user_type_id' => 1])->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $r->id, 'status' => 'pending', 'round_status' => 'submitted', 'total_amount_requested' => 100000]);
    $this->po()->postJson("/api/v1/programs/applications/{$app->id}/accept")->assertOk();
    $manual = $app->fresh();

    pi_import($this, ['program' => [$this->programRow(['program_ref' => 'P9', 'program_type' => 'single_round', 'total_rounds' => 1])],
        'winners' => [['application_ref' => 'W9', 'program_ref' => 'P9', 'startup_name' => 'Imp', 'email' => 'imp@example.com', 'awarded_amount' => 0, 'sector' => 'Agri', 'headquarters_location' => 'Nairobi']]], 'funding_setup');
    $imported = ProgramApplication::where('startup_name', 'Imp')->first();
    $keys = ['status', 'round_status', 'knockout_status', 'funding_setup_status', 'planning_mode', 'escrow_funded', 'is_eligible_to_advance', 'program_owner_id'];
    $pick = fn ($m) => collect(pi_p($m))->only($keys)->all();
    expect($pick($imported))->toBe($pick($manual));
    expect(ProgramRound::where('program_id', $imported->program_id)->value('status'))->toBe('finalized');
});

it('parity: a supplier imported matches one added through the supplier directory endpoint', function () {
    $this->po()->postJson('/api/v1/programs/supplier-directory', ['legal_name' => 'Tools Ltd', 'email' => 'tools@example.com', 'phone' => '+254712345678', 'payment_method' => 'bank_transfer', 'bank_name' => 'KCB', 'bank_account_number' => '123456'])->assertSuccessful();
    $manual = SupplierDirectory::first();
    pi_import($this, ['suppliers' => [['supplier_ref' => 'S1', 'legal_name' => 'Tools Two', 'email' => 'two@example.com', 'phone' => '+254712345679', 'payment_method' => 'bank_transfer']]], 'disbursement');
    $imported = SupplierDirectory::where('legal_name', 'Tools Two')->first();
    $skip = ['legal_name', 'email', 'phone', 'bank_name', 'bank_account_number', 'notes', 'added_by'];
    $diff = array_diff_assoc(pi_p($imported, $skip), pi_p($manual, $skip));
    // Documented: payment details are not parsed into bank columns in v1 (kept in notes); everything else equals.
    expect($diff)->toBe([]);
    expect($imported->user_id)->toBe($manual->user_id)->and($imported->is_active)->toBe($manual->is_active);
});

it('parity: an M&E checkpoint imported matches one created through the monitoring endpoint (unsent)', function () {
    $p = pi_manual_program($this, ['program_type' => 'single_round', 'total_rounds' => 1]);
    $app = ProgramApplication::factory()->create(['program_id' => $p->id, 'user_id' => User::factory()->create(['user_type_id' => 1])->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'awarded', 'funding_setup_status' => 'completed']);
    $this->po()->postJson("/api/v1/programs/monitoring/applications/{$app->id}/checkpoints", ['checkpoint_name' => 'Q3', 'type' => 'reporting', 'due_date' => '2026-12-01', 'requirement' => 'r', 'kpis_to_track' => [['name' => 'Jobs', 'unit' => '', 'target' => '']], 'evidence_required' => ['x'], 'require_site_visit' => false, 'should_notify_applicant' => false])->assertSuccessful();
    $manual = MECheckpoint::first();

    pi_import($this, ['program' => [$this->programRow(['program_ref' => 'P9'])], 'winners' => [['application_ref' => 'W9', 'program_ref' => 'P9', 'startup_name' => 'Imp', 'email' => 'imp@example.com', 'awarded_amount' => 1000, 'sector' => 'Agri', 'headquarters_location' => 'Nairobi']],
        'checkpoints' => [['application_ref' => 'W9', 'checkpoint_ref' => 'C9', 'checkpoint_name' => 'Q3', 'type' => 'reporting', 'due_date' => '2026-12-01', 'requirement' => 'r', 'kpis_to_track' => 'Jobs', 'evidence_required' => 'x']]], 'monitoring');
    $imported = MECheckpoint::where('id', '!=', $manual->id)->first();
    // Documented difference: a manual checkpoint is sent to the applicant at creation (sent_at set); an imported one stays unsent so nothing
    // reaches the applicant until the owner chooses to send it.
    $skip = ['app_id', 'program_id', 'submission_fields', 'custom_submission_fields', 'meeting_id', 'sent_at'];
    expect(array_diff_assoc(pi_p($imported, $skip), pi_p($manual, $skip)))->toBe([]);
    // With the option on, an imported checkpoint is marked sent exactly like a manual one (still no message: should_notify_applicant is false).
    $again = pi_import($this, ['program' => [$this->programRow(['program_ref' => 'P9'])], 'checkpoints' => [['application_ref' => 'W9', 'checkpoint_ref' => 'C10', 'checkpoint_name' => 'Q4', 'type' => 'reporting']]], 'monitoring', ['mark_checkpoints_sent' => true]);
    expect(MECheckpoint::where('checkpoint_name', 'Q4')->value('sent_at'))->not->toBeNull()->and($imported->fresh()->sent_at)->toBeNull();
});

it('parity: show endpoints return the same VALUES for an imported and a manual program, round and application', function () {
    $dates = ['application_start_date' => '2026-01-01', 'application_deadline' => '2026-12-01'];
    $p = pi_manual_program($this, ['program_title' => 'Parity Fund', 'program_description' => null, 'program_type' => 'single_round', 'total_rounds' => 1, 'status' => 'published', 'disbursement_type' => 'supplier',
        'available_amount' => 400000, 'max_awardees' => 4, 'program_industry_id' => \App\Models\ProgramIndustry::first()->id, 'use_match_score' => true, 'match_score_limit' => null, 'require_kyc_verification_to_apply' => false] + $dates);
    \App\Models\Programs\ProgramWallet::create(['program_id' => $p->id, 'total_deposited' => 0, 'total_disbursed' => 0, 'total_reserved' => 0, 'balance' => 0, 'status' => 'inactive', 'currency' => 'KES']);
    $r = ProgramRound::factory()->create(['program_id' => $p->id, 'round_number' => 1, 'round_name' => 'Application', 'status' => 'published', 'open_date' => '2026-01-01', 'close_date' => '2026-02-01', 'review_period_end' => '2026-02-10',
        'announcement_date' => null, 'rubric_mode' => 'simple_total', 'scoring_criteria' => null, 'knockout_questions' => null, 'required_documents' => null, 'assignment_type' => 'owner_only', 'assignment_method' => 'manual',
        'advancement_mode' => 'manual', 'score_threshold' => null, 'max_advancing' => null, 'tie_breaker_rule' => null, 'confirmed_steps' => null]);
    $manualUser = User::factory()->create(['user_type_id' => 1, 'email' => 'manual@example.com', 'first_name' => 'Amina', 'last_name' => 'Ali']);
    $app = ProgramApplication::factory()->create(['program_id' => $p->id, 'user_id' => $manualUser->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $r->id, 'status' => 'pending', 'round_status' => 'submitted',
        'startup_name' => 'Agro One', 'contact_person_name' => 'Amina Ali', 'email' => 'manual@example.com', 'contact_person_email' => 'manual@example.com', 'sector' => 'Agri', 'headquarters_location' => 'Nairobi', 'total_amount_requested' => 100000,
        'stage' => null, 'revenue_last_12_months' => 0, 'team_experience_avg_years' => null, 'traction_kpis' => null, 'social_impact_areas' => null, 'pitch_deck_file' => null, 'pitch_video' => null, 'business_plan_file' => null,
        'bonus_points' => null, 'awarded_amount' => null, 'match_score' => null, 'score_breakdown' => null, 'reviewer_notes' => null, 'rejection_reason' => null, 'average_score' => null, 'assigned_reviewer_id' => null, 'is_eligible_to_advance' => false,
        'knockout_status' => 'pending', 'funding_setup_status' => 'not_started', 'planning_mode' => 'locked', 'escrow_funded' => false, 'awarded_at' => null]);

    $rounds = [['program_ref' => 'P9', 'round_number' => 1, 'round_name' => 'Application', 'open_date' => '2026-01-01', 'close_date' => '2026-02-01', 'review_period_end' => '2026-02-10']];
    $apps = [['application_ref' => 'A9', 'program_ref' => 'P9', 'round_number' => 1, 'startup_name' => 'Agro One', 'contact_person_name' => 'Amina Ali', 'email' => 'import@example.com', 'sector' => 'Agri', 'headquarters_location' => 'Nairobi', 'total_amount_requested' => 100000]];
    pi_import($this, ['program' => [$this->programRow(['program_ref' => 'P9', 'program_title' => 'Parity Fund', 'program_type' => 'single_round', 'total_rounds' => 1, 'max_awardees' => 4] + $dates)], 'rounds' => $rounds, 'applications' => $apps], 'review');
    $ip = Program::where('id', '!=', $p->id)->first();
    $ir = ProgramRound::where('program_id', $ip->id)->first();
    $ia = ProgramApplication::where('program_id', $ip->id)->first();

    // Differences that are the point of an import, or belong to the person/record rather than the behavior. Everything else must be equal.
    $explained = '/(^|\.)(id|[a-z_]+_id|uid|public_id|[a-z_]+_at|created_at|updated_at|email|contact_person_email|first_name|last_name|display_name|name|image|token|visible|status)$/';
    $flat = fn (array $j) => collect(\Illuminate\Support\Arr::dot($j))->reject(fn ($v, $k) => preg_match($explained, $k) || str_contains($k, 'wallet.') || str_contains($k, '.user.') || str_contains($k, 'organization_logo') || str_contains($k, 'owner.'))->all();
    foreach ([['program', "/api/v1/programs/get_program/{$p->id}", "/api/v1/programs/get_program/{$ip->id}"], ['round', "/api/v1/programs/rounds/{$r->id}", "/api/v1/programs/rounds/{$ir->id}"], ['application', "/api/v1/programs/applications/{$app->id}", "/api/v1/programs/applications/{$ia->id}"]] as [$name, $m, $i]) {
        $a = $flat($this->po()->getJson($m)->assertOk()->json());
        $b = $flat($this->po()->getJson($i)->assertOk()->json());
        expect(count($a))->toBeGreaterThan(15, "$name: too few fields were compared");
        $diff = array_filter(array_intersect_key($b, $a), fn ($v, $k) => (string) json_encode($v) !== (string) json_encode($a[$k]), ARRAY_FILTER_USE_BOTH);
        $missing = array_keys(array_diff_key($b, $a)) + array_keys(array_diff_key($a, $b));
        expect($diff)->toBe([], "$name values differ: ".json_encode($diff));
        expect(array_values(array_unique($missing)))->toBe([], "$name has fields on one side only: ".json_encode($missing));
    }
    // And the explained differences are exactly the documented ones
    expect($ip->visible)->toBeFalse()->and($ip->status)->toBe('under_review')->and($ia->status)->toBe($app->status)->and($ia->round_status)->toBe($app->round_status);
});
