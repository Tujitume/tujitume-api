<?php

use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\Rounds\ProgramRound;
use Tests\Feature\ProgramImport\ImportTestCase;

uses(ImportTestCase::class);

/** The PO's next steps after each start point, run through the EXISTING endpoints. */

it('setup: a draft program the owner can keep configuring', function () {
    $file = $this->xlsx(['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'questions' => [
        ['round_ref' => 'round-1', 'question_ref' => 'Q1', 'question_text' => 'Describe your business', 'question_type' => 'paragraph', 'is_required' => 'yes'],
    ]]);
    $b = $this->newBatch('setup', $file);
    expect($this->prepare($b)['blockers'])->toBe([]);
    $this->commit($b);
    $this->assertSilent();

    $p = Program::first();
    expect($p->status)->toBe('draft')->and($p->visible)->toBeFalse();
    $r1 = ProgramRound::where('round_number', 1)->first();
    expect($r1->status)->toBe('draft')->and($r1->questions ?? \App\Models\Programs\Rounds\RoundCustomQuestion::where('round_id', $r1->id)->count())->not->toBeEmpty();
    $this->po()->getJson("/api/v1/programs/rounds/{$r1->id}")->assertOk();
    $this->po()->getJson("/api/v1/programs/rounds/{$r1->id}/questions")->assertOk();
    $this->po()->patchJson("/api/v1/programs/rounds/{$r1->id}", ['round_name' => 'Application round'])->assertOk();
    $this->po()->getJson("/api/v1/programs/get_program/{$p->id}")->assertOk();
});

it('review: the owner accepts and rejects in round 1 and finalizes a single-round program', function () {
    $rounds = [['program_ref' => 'P1', 'round_number' => 1, 'round_name' => 'Application', 'open_date' => '2026-01-01', 'close_date' => '2026-02-01', 'review_period_end' => '2026-02-10']];
    $apps = $this->appRows(1);
    $apps[0]['outcome'] = null;
    $file = $this->xlsx(['program' => [$this->programRow(['program_type' => 'single_round', 'total_rounds' => 1])], 'rounds' => $rounds, 'applications' => $apps]);
    $b = $this->newBatch('review', $file);
    expect($this->prepare($b)['blockers'])->toBe([]);
    $this->commit($b);
    $this->assertSilent();

    $a1 = ProgramApplication::where('startup_name', 'Agro One')->first();
    $a2 = ProgramApplication::where('startup_name', 'Agro Two')->first();
    expect($a1->status)->toBe('pending')->and($a1->round_status)->toBe('submitted');

    $this->po()->getJson("/api/v1/programs/rounds/{$a1->current_round_id}/applications")->assertOk();
    $this->po()->postJson("/api/v1/programs/applications/{$a1->id}/accept")->assertOk();
    $this->po()->postJson("/api/v1/programs/applications/{$a2->id}/reject")->assertOk();
    expect($a1->fresh()->status)->toBe('awarded')->and($a2->fresh()->round_status)->toBe('not_selected');
    $this->po()->postJson("/api/v1/programs/rounds/{$a1->current_round_id}/finalize")->assertOk();
    expect(Program::first()->status)->toBe('awarded');
});

it('review: round 2 applicants are advanced and the final round is finalized', function () {
    $file = $this->xlsx(['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $this->appRows(2)]);
    $b = $this->newBatch('review', $file);
    $this->prepare($b);
    $this->commit($b);

    $a1 = ProgramApplication::where('startup_name', 'Agro One')->first();
    $a2 = ProgramApplication::where('startup_name', 'Agro Two')->first();
    $this->po()->postJson("/api/v1/programs/applications/{$a1->id}/advance")->assertOk();
    $this->po()->postJson("/api/v1/programs/applications/{$a2->id}/reject")->assertOk();
    $this->po()->postJson("/api/v1/programs/rounds/{$a1->current_round_id}/finalize")->assertOk();
    expect($a1->fresh()->status)->toBe('awarded')->and(Program::first()->status)->toBe('awarded');
});

it('finalization: scores land exactly as manual scoring would and finalize awards the winner', function () {
    $rounds = $this->roundRows();
    $rounds[1]['advancement_mode'] = 'score_threshold';
    $rounds[1]['score_threshold'] = 50;
    $scores = [
        ['application_ref' => 'A1', 'round_number' => 2, 'criterion' => 'Impact', 'criterion_score' => 8],
        ['application_ref' => 'A1', 'round_number' => 2, 'criterion' => 'Team', 'criterion_score' => 7],
        ['application_ref' => 'A2', 'round_number' => 2, 'criterion' => 'Impact', 'criterion_score' => 3],
        ['application_ref' => 'A2', 'round_number' => 2, 'criterion' => 'Team', 'criterion_score' => 2],
    ];
    $file = $this->xlsx(['program' => [$this->programRow()], 'rounds' => $rounds, 'applications' => $this->appRows(2), 'scores' => $scores]);
    $b = $this->newBatch('finalization', $file);
    $r = $this->prepare($b);
    expect($r['blockers'])->toBe([]);
    $this->commit($b);
    $this->assertSilent();

    $a1 = ProgramApplication::where('startup_name', 'Agro One')->first();
    $a2 = ProgramApplication::where('startup_name', 'Agro Two')->first();
    // weighted rubric: Impact 8/10*100*0.6 = 48, Team 7/10*100*0.4 = 28 => 76
    expect($a1->round_status)->toBe('scored')->and((float) $a1->average_score)->toBe(76.0)->and((bool) $a1->is_eligible_to_advance)->toBeTrue()
        ->and((float) $a2->average_score)->toBe(26.0)->and((bool) $a2->is_eligible_to_advance)->toBeFalse();
    $this->po()->getJson("/api/v1/programs/rounds/{$a1->current_round_id}/finalize-preview")->assertOk();
    $this->po()->postJson("/api/v1/programs/rounds/{$a1->current_round_id}/finalize")->assertOk();
    expect($a1->fresh()->status)->toBe('awarded')->and($a2->fresh()->round_status)->toBe('not_selected')->and(Program::first()->status)->toBe('awarded');
});

it('finalization: a total score only creates the overall criterion with a warning-free landing', function () {
    $rounds = [['program_ref' => 'P1', 'round_number' => 1, 'round_name' => 'Application', 'open_date' => '2026-01-01', 'close_date' => '2026-02-01', 'review_period_end' => '2026-02-10', 'advancement_mode' => 'score_threshold', 'score_threshold' => 50]];
    $scores = [['application_ref' => 'A1', 'total_score' => 82], ['application_ref' => 'A2', 'total_score' => 40]];
    $file = $this->xlsx(['program' => [$this->programRow(['program_type' => 'single_round', 'total_rounds' => 1])], 'rounds' => $rounds, 'applications' => $this->appRows(1), 'scores' => $scores]);
    $b = $this->newBatch('finalization', $file);
    $this->prepare($b);
    $this->commit($b);
    $round = ProgramRound::first();
    expect($round->scoring_criteria[0]['name'])->toBe('Imported overall score')->and($round->rubric_mode)->toBe('weighted');
    expect((float) ProgramApplication::where('startup_name', 'Agro One')->value('average_score'))->toBe(82.0);
});

it('funding_setup: awardees go through templates and activation', function () {
    $winners = [['application_ref' => 'W1', 'program_ref' => 'P1', 'startup_name' => 'Agro One', 'email' => 'amina@example.com', 'awarded_amount' => 100000, 'awarded_at' => '2026-05-01', 'sector' => 'Agri', 'headquarters_location' => 'Nairobi']];
    $file = $this->xlsx(['program' => [$this->programRow()], 'winners' => $winners]);
    $b = $this->newBatch('funding_setup', $file);
    expect($this->prepare($b)['blockers'])->toBe([]);
    $this->commit($b);
    $this->assertSilent();

    $a = ProgramApplication::first();
    expect(Program::first()->status)->toBe('awarded')->and($a->status)->toBe('awarded')->and($a->funding_setup_status)->toBe('not_started')
        ->and((float) $a->awarded_amount)->toBe(100000.0)->and($a->planning_mode)->toBe('locked')
        ->and(ProgramRound::first()->round_name)->toBe('Imported awardees')->and(ProgramRound::first()->status)->toBe('finalized');

    $this->po()->postJson("/api/v1/programs/applications/{$a->id}/planning-mode", ['planning_mode' => 'locked'])->assertOk();
    $this->po()->postJson("/api/v1/programs/applications/{$a->id}/milestones/templates", ['title' => 'Equipment', 'description' => 'Buy equipment', 'amount' => 60000])->assertSuccessful();
    $this->po()->postJson("/api/v1/programs/applications/{$a->id}/milestones/templates", ['title' => 'Launch', 'description' => 'Go live', 'amount' => 40000])->assertSuccessful();
    $this->po()->postJson("/api/v1/programs/applications/{$a->id}/milestones/activate")->assertOk();
    expect($a->fresh()->funding_setup_status)->toBe('completed')->and(ProgramMilestone::where('app_id', $a->id)->where('is_template', false)->count())->toBe(2);
});

it('disbursement: active milestones are ready for verification and release in order', function () {
    $winners = [['application_ref' => 'W1', 'program_ref' => 'P1', 'startup_name' => 'Agro One', 'email' => 'amina@example.com', 'awarded_amount' => 100000, 'awarded_at' => '2026-05-01', 'sector' => 'Agri', 'headquarters_location' => 'Nairobi']];
    $milestones = [
        ['application_ref' => 'W1', 'milestone_ref' => 'M1', 'sequence_order' => 1, 'title' => 'Equipment', 'amount' => 60000],
        ['application_ref' => 'W1', 'milestone_ref' => 'M2', 'sequence_order' => 2, 'title' => 'Launch', 'amount' => 40000],
    ];
    $suppliers = [['supplier_ref' => 'S1', 'legal_name' => 'Tools Ltd', 'email' => 'tools@example.com', 'phone' => '0712345678']];
    $ms = [['milestone_ref' => 'M1', 'supplier_ref' => 'S1', 'amount' => 60000], ['milestone_ref' => 'M2', 'supplier_ref' => 'S1', 'amount' => 40000]];
    $file = $this->xlsx(['program' => [$this->programRow()], 'winners' => $winners, 'milestones' => $milestones, 'suppliers' => $suppliers, 'milestone_suppliers' => $ms]);
    $b = $this->newBatch('disbursement', $file);
    $r = $this->prepare($b);
    expect($r['blockers'])->toBe([]);
    $this->commit($b);
    $this->assertSilent();

    $a = ProgramApplication::first();
    [$m1, $m2] = ProgramMilestone::where('app_id', $a->id)->orderBy('sequence_order')->get()->all();
    expect($a->funding_setup_status)->toBe('completed')->and($m1->is_template)->toBeFalse()->and($m1->fund_release_status)->toBe('approved')->and($m2->fund_release_status)->toBe('locked');
    $this->po()->getJson("/api/v1/programs/milestones/{$m1->id}/disbursement-data")->assertOk();
    $this->po()->getJson("/api/v1/programs/milestones/{$m1->id}/verifications")->assertOk();
    // The release guards pass for milestone 1 (it fails later only at the LIPR transfer, which is not configured in tests)
    $res = $this->po()->postJson("/api/v1/programs/milestones/{$m1->id}/release-funds", ['type' => 'pay_from_wallet']);
    expect((string) $res->json('message'))->not->toContain('not ready for fund release')->not->toContain('Supplier not found')->not->toContain('Earlier milestones');
    // Milestone 2 is held back until milestone 1 is released
    $this->po()->postJson("/api/v1/programs/milestones/{$m2->id}/release-funds", ['type' => 'pay_from_wallet'])->assertStatus(422);
});

it('disbursement: fully paid milestones are recorded without moving money, and a partial release is allowed', function () {
    $winners = [['application_ref' => 'W1', 'program_ref' => 'P1', 'startup_name' => 'Agro One', 'email' => 'amina@example.com', 'awarded_amount' => 100000, 'awarded_at' => '2026-05-01', 'sector' => 'Agri', 'headquarters_location' => 'Nairobi']];
    $partial = [
        ['application_ref' => 'W1', 'milestone_ref' => 'M1', 'sequence_order' => 1, 'title' => 'Equipment', 'amount' => 60000, 'status' => 'completion_approved'],
        ['application_ref' => 'W1', 'milestone_ref' => 'M2', 'sequence_order' => 2, 'title' => 'Launch', 'amount' => 40000],
    ];
    $b = $this->newBatch('disbursement', $this->xlsx(['program' => [$this->programRow()], 'winners' => $winners, 'milestones' => $partial]));
    $r = $this->prepare($b);
    expect($r['blockers'])->toBe([])->and(array_column($r['warnings'], 'code'))->toContain('partial_release_remainder');

    $all = array_map(fn ($m) => $m + ['status' => 'completion_approved'], $partial);
    $disb = [['milestone_ref' => 'M1', 'amount' => 60000, 'disbursed_at' => '2026-06-01', 'payment_reference' => 'MPESA123'], ['milestone_ref' => 'M2', 'amount' => 40000, 'disbursed_at' => '2026-07-01', 'payment_reference' => 'MPESA124']];
    $b = $this->newBatch('disbursement', $this->xlsx(['program' => [$this->programRow()], 'winners' => $winners, 'milestones' => $all, 'disbursements' => $disb]));
    expect($this->prepare($b)['blockers'])->toBe([]);
    $before = (float) \App\Models\Programs\ProgramWallet::first()?->balance;
    $this->commit($b);
    $this->assertSilent();
    expect(\App\Models\Programs\Disbursement::where('status', 'completed')->count())->toBe(2)->and((float) \App\Models\Programs\Program::first()->available_amount)->toBe(400000.0)
        ->and((float) \App\Models\Programs\ProgramWallet::first()->balance)->toBe($before);
});

it('monitoring: checkpoints can be sent, listed and verified', function () {
    $winners = [['application_ref' => 'W1', 'program_ref' => 'P1', 'startup_name' => 'Agro One', 'email' => 'amina@example.com', 'awarded_amount' => 100000, 'awarded_at' => '2026-05-01', 'sector' => 'Agri', 'headquarters_location' => 'Nairobi']];
    $cps = [['application_ref' => 'W1', 'checkpoint_ref' => 'C1', 'checkpoint_name' => 'Q3 progress', 'type' => 'reporting', 'due_date' => '2026-12-01', 'kpis_to_track' => 'Jobs; Revenue']];
    $ms = [['application_ref' => 'W1', 'milestone_ref' => 'M1', 'sequence_order' => 1, 'title' => 'Equipment', 'amount' => 100000, 'status' => 'completion_approved', 'fund_release_status' => 'released']];
    $pay = [['milestone_ref' => 'M1', 'amount' => 100000, 'disbursed_at' => '2026-06-01', 'recipient_type' => 'business_owner']];
    $b = $this->newBatch('monitoring', $this->xlsx(['program' => [$this->programRow()], 'winners' => $winners, 'milestones' => $ms, 'disbursements' => $pay, 'checkpoints' => $cps]));
    expect($this->prepare($b)['blockers'])->toBe([]);
    $this->commit($b);
    $this->assertSilent();

    $a = ProgramApplication::first();
    $cp = \App\Models\Programs\Monitoring\MECheckpoint::first();
    expect($cp->status)->toBe('pending')->and($cp->sent_at)->toBeNull()->and($cp->kpis_to_track[0]['name'])->toBe('Jobs');
    $this->po()->getJson("/api/v1/programs/monitoring/applications/{$a->id}/checkpoints")->assertOk();
    $this->po()->getJson("/api/v1/programs/monitoring/applications/{$a->id}/analytics/overview")->assertOk();
    $this->po()->postJson("/api/v1/programs/monitoring/checkpoints/{$cp->id}/send")->assertSuccessful();
});
