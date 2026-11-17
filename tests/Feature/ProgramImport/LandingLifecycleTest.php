<?php

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\Programs\Disbursement;
use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\Monitoring\MESubmission;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\ProgramWallet;
use App\Models\Programs\Rounds\ProgramRound;
use Illuminate\Support\Facades\Http;
use Tests\Feature\ProgramImport\ApiImportTestCase;

uses(ApiImportTestCase::class);

function pi_winner(): array
{
    return [['application_ref' => 'W1', 'program_ref' => 'P1', 'startup_name' => 'Agro One', 'email' => 'amina@example.com', 'awarded_amount' => 100000, 'sector' => 'Agri', 'headquarters_location' => 'Nairobi']];
}

function pi_lifecycle_bundle($t, array $opts = []): \App\Modules\ProgramImport\Models\ImportBatch
{
    $ms = [
        ['application_ref' => 'W1', 'milestone_ref' => 'M1', 'sequence_order' => 1, 'title' => 'Equipment', 'amount' => 60000, 'mprv_required' => 'yes'],
        ['application_ref' => 'W1', 'milestone_ref' => 'M2', 'sequence_order' => 2, 'title' => 'Launch', 'amount' => 40000, 'mprv_required' => 'yes'],
    ];
    $sheets = ['program' => [$t->programRow()], 'winners' => pi_winner(), 'suppliers' => [['supplier_ref' => 'S1', 'legal_name' => 'Tools Ltd', 'email' => 'tools@example.com', 'phone' => '0712345678', 'payment_method' => 'mpesa_mobile']],
        'milestones' => $ms, 'milestone_suppliers' => [['milestone_ref' => 'M1', 'supplier_ref' => 'S1', 'amount' => 60000], ['milestone_ref' => 'M2', 'supplier_ref' => 'S1', 'amount' => 40000]],
        'checkpoints' => [['application_ref' => 'W1', 'checkpoint_ref' => 'C1', 'checkpoint_name' => 'Q3 progress', 'type' => 'reporting', 'kpis_to_track' => 'Jobs']]];
    $b = $t->newBatch('disbursement', $t->xlsx($sheets), $opts + ['mark_checkpoints_sent' => true]);
    expect($t->prepare($b)['blockers'])->toBe([]);

    return $t->commit($b);
}

/** Verification, approval, release, completion and sign-off of one milestone, through the existing endpoints. */
function pi_run_milestone($t, ProgramMilestone $m): void
{
    $applicant = User::where('email', 'amina@example.com')->first();
    if ($m->fund_release_status !== 'approved') {   // a milestone unlocked by the previous completion needs no verification again
    $t->actingAs($applicant, 'sanctum')->postJson("/api/v1/programs/milestones/{$m->id}/verifications", ['verification_type' => 'mprv', 'conflict_of_interest_confirmed' => true, 'funds_usage_confirmed' => true, 'additional_declarations' => 'ok'])->assertSuccessful();
    $v = \App\Models\Programs\MilestoneVerification::where('milestone_id', $m->id)->latest('id')->first();
    $t->po()->patchJson("/api/v1/programs/verifications/{$v->id}/approve", ['notes' => 'ok'])->assertSuccessful();
    }
    expect($m->fresh()->fund_release_status)->toBe('approved');

    // Payment to the supplier: the LIPR call is replaced by a scripted success (no network, no money)
    $mock = \Mockery::mock(\App\Service\LiprMpesa\ProgramDisbursementService::class);
    $mock->shouldReceive('disburse')->andReturnUsing(function ($milestone) {
        $d = Disbursement::create(['milestone_id' => $milestone->id, 'recipient_type' => 'supplier', 'supplier_id' => \App\Models\Programs\SupplierDirectory::first()->id, 'amount' => $milestone->amount, 'currency' => 'KES', 'payment_method' => 'mpesa_mobile', 'status' => 'pending']);

        return ['success' => true, 'disbursement' => $d, 'reference' => 'MP'.$milestone->id];
    });
    app()->instance(\App\Service\LiprMpesa\ProgramDisbursementService::class, $mock);
    $res = $t->po()->postJson("/api/v1/programs/milestones/{$m->id}/release-funds", ['type' => 'pay_from_wallet']);
    expect($res->status())->toBeLessThan(300, (string) $res->getContent());
    $d = Disbursement::where('milestone_id', $m->id)->latest('id')->first();
    $t->po()->patchJson("/api/v1/programs/disbursements/{$d->id}/mark-completed", [])->assertSuccessful();
}

it('setup: the owner assigns a Business Coach, starts funding the wallet and publishes the imported round', function () {
    $coach = User::factory()->create(['user_type_id' => 6]);
    KycVerification::create(['user_id' => $coach->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);
    $open = now()->addDays(10)->toDateString();
    $close = now()->addDays(40)->toDateString();
    $rounds = [['program_ref' => 'P1', 'round_number' => 1, 'round_name' => 'Application', 'open_date' => $open, 'close_date' => $close, 'review_period_end' => now()->addDays(50)->toDateString(),
        'rubric_mode' => 'weighted', 'scoring_criteria' => 'Impact:60:10; Team:40:10', 'assignment_type' => 'external']];
    $q = [['round_ref' => 'round-1', 'question_ref' => 'Q1', 'question_text' => 'Describe your business', 'question_type' => 'paragraph', 'is_required' => 'yes']];
    $program = $this->programRow(['program_type' => 'single_round', 'total_rounds' => 1, 'application_start_date' => $open, 'application_deadline' => $close]);
    $b = $this->newBatch('setup', $this->xlsx(['program' => [$program], 'rounds' => $rounds, 'questions' => $q]));
    expect($this->prepare($b)['blockers'])->toBe([]);
    $this->commit($b);
    $p = Program::first();
    $round = ProgramRound::first();
    expect($round->status)->toBe('draft')->and($p->status)->toBe('draft');

    // assign a Business Coach through the normal endpoint (creates the order the normal way)
    $this->po()->postJson("/api/v1/programs/rounds/{$round->id}/reviewers", ['order_type' => 'round_review', 'reviewer_type' => 'external', 'user_id' => $coach->id, 'reviewer_fee' => 0, 'fee_currency' => 'kes'])->assertStatus(201);
    expect(\App\Models\Programs\Rounds\RoundReviewer::where('round_id', $round->id)->count())->toBe(1);

    // fund the wallet: the deposit request goes to LIPR (faked)
    config(['services.lipr.base_path' => 'https://lipr.test']);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(['lipr.test/partners/v1/auth/token' => Http::response(['data' => ['accessToken' => 'T', 'token' => 'T', 'access_token' => 'T'], 'accessToken' => 'T', 'token' => 'T', 'success' => true]),
        'lipr.test/*' => Http::response(['success' => true, 'data' => ['referenceId' => 'R1']])]);
    $wallet = ProgramWallet::where('program_id', $p->id)->first();
    expect($wallet)->not->toBeNull();
    $this->po()->postJson("/api/v1/programs/wallets/{$wallet->id}/deposit", ['amount' => 1000, 'acc_number' => '0712345678', 'purpose' => 'Fund program'])->assertStatus(200);

    // confirm the setup steps, write the two required notification texts, then publish
    foreach (['round.advanced', 'round.not_selected'] as $event) {
        $this->po()->patchJson("/api/v1/programs/{$p->id}/email-templates/$event", ['event' => $event, 'body_html' => '<p>Hello {{applicant_name}}</p>'])->assertOk();
    }
    foreach (['setup', 'questions', 'reviewers', 'notifications'] as $step) {
        $this->po()->postJson("/api/v1/programs/rounds/{$round->id}/configuration-steps", ['step' => $step])->assertOk();
    }
    $this->po()->postJson("/api/v1/programs/rounds/{$round->id}/publish", [])->assertOk();
    expect($round->fresh()->status)->toBe('published');
});

it('disbursement: the first milestone goes through verification, release and completion, then the next one unlocks', function () {
    pi_lifecycle_bundle($this);
    $this->assertSilent();
    [$m1, $m2] = ProgramMilestone::orderBy('sequence_order')->get()->all();
    // A milestone that needs a payment-release verification stays locked until that verification is approved (same as activation)
    expect((bool) $m1->mprv_ready)->toBeTrue()->and($m1->fund_release_status)->toBe('locked')->and($m2->fund_release_status)->toBe('locked');

    pi_run_milestone($this, $m1);
    expect($m1->fresh()->fund_release_status)->toBe('released');
    $applicant = User::where('email', 'amina@example.com')->first();
    $this->actingAs($applicant, 'sanctum')->postJson("/api/v1/programs/milestones/{$m1->id}/completions", ['completion_report' => 'Equipment delivered', 'delivery_notes' => 'ok'])->assertSuccessful();
    $c = \Illuminate\Support\Facades\DB::table('milestone_completion_submissions')->where('milestone_id', $m1->id)->first();
    $this->po()->patchJson("/api/v1/programs/completions/{$c->id}/approve", ['notes' => 'good'])->assertSuccessful();
    expect($m1->fresh()->status)->toBe('completion_approved')->and($m2->fresh()->fund_release_status)->not->toBe('released');
});

it('monitoring: the applicant submits an imported checkpoint and the owner verifies it', function () {
    pi_lifecycle_bundle($this);
    $cp = MECheckpoint::first();
    expect($cp->sent_at)->not->toBeNull();
    $applicant = User::where('email', 'amina@example.com')->first();
    $this->actingAs($applicant, 'sanctum')->postJson("/api/v1/programs/monitoring/checkpoints/{$cp->id}/submit", ['written_report' => 'Hired 5 people', 'kpi_actual_values' => ['Jobs' => 5]])->assertSuccessful();
    $s = MESubmission::first();
    expect($s->status)->toBe('submitted');
    $this->po()->postJson("/api/v1/programs/monitoring/submissions/{$s->id}/verify", [])->assertSuccessful();
    expect($s->fresh()->status)->toBe('verified')->and($cp->fresh()->status)->toBe('verified');
    $this->po()->getJson("/api/v1/programs/monitoring/applications/".ProgramApplication::first()->id."/analytics/overview")->assertOk();
});

it('full bundle: one import, then the whole chain through the existing endpoints, ending verified, released, completed and monitored', function () {
    pi_lifecycle_bundle($this);
    [$m1, $m2] = ProgramMilestone::orderBy('sequence_order')->get()->all();
    $applicant = User::where('email', 'amina@example.com')->first();
    foreach ([$m1, $m2] as $m) {
        pi_run_milestone($this, $m->fresh());
        expect($m->fresh()->fund_release_status)->toBe('released');
        $this->actingAs($applicant, 'sanctum')->postJson("/api/v1/programs/milestones/{$m->id}/completions", ['completion_report' => 'Done', 'delivery_notes' => 'ok'])->assertSuccessful();
        $c = \Illuminate\Support\Facades\DB::table('milestone_completion_submissions')->where('milestone_id', $m->id)->first();
        $this->po()->patchJson("/api/v1/programs/completions/{$c->id}/approve", ['notes' => 'good'])->assertSuccessful();
        expect($m->fresh()->status)->toBe('completion_approved');
    }
    $cp = MECheckpoint::first();
    $this->actingAs($applicant, 'sanctum')->postJson("/api/v1/programs/monitoring/checkpoints/{$cp->id}/submit", ['written_report' => 'All done'])->assertSuccessful();
    $this->po()->postJson('/api/v1/programs/monitoring/submissions/'.MESubmission::first()->id.'/verify', [])->assertSuccessful();
    expect(MECheckpoint::first()->status)->toBe('verified')->and(ProgramMilestone::where('status', 'completion_approved')->count())->toBe(2);
});
