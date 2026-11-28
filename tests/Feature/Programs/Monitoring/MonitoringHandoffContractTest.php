<?php

use App\Models\Auth\User;
use App\Models\Communication\Notifications;
use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\ReviewerOrder;
use App\Models\Services\Services;
use Illuminate\Support\Facades\DB;

uses(Tests\Feature\Programs\ProgramTestCase::class);

/*
 * The contract the frontend was built against (backend-fix-for-dev.txt, tasks 1-9): checkpoint hand-off,
 * structured change requests, site visit fee proposals, officer location/price, provider work rows,
 * paid-visit acceptance checks, the "tujitume" theme and the visit's copy of its checkpoint.
 */

const HANDOFF_ME = '/api/v1/programs/monitoring';

function handoffNotes(User $user): int
{
    return Notifications::where('receiver_id', $user->id)->count();
}

/** A sent site-visit checkpoint with an officer assigned through the API; returns [checkpointId, visitId, orderId]. */
function handoffVisit($test, ?User $officer = null, float $fee = 0, array $checkpoint = []): array
{
    $officer ??= $test->reviewerUser;
    $checkpointId = $test->actingAs($test->orgUser, "sanctum")->postJson(HANDOFF_ME . "/applications/{$test->application->id}/checkpoints", array_merge([
        'checkpoint_name' => 'Farm visit', 'type' => 'monitoring', 'respondent' => 'site_visit_officer', 'send' => true,
        'require_site_visit' => true, 'should_notify_applicant' => false,
    ], $checkpoint))->assertStatus(201)->json('data.id');
    $test->actingAs($test->orgUser, "sanctum")->postJson(HANDOFF_ME . "/checkpoints/$checkpointId/site-visit/assign", [
        'reviewer_id' => $officer->id, 'start_date' => now()->addWeek()->toDateString(), 'assign_type' => 'internal', 'reviewer_fee' => $fee,
    ])->assertStatus(201);
    $visitId = MESiteVisit::where('checkpoint_id', $checkpointId)->value('id');

    return [$checkpointId, $visitId, ReviewerOrder::where('site_visit_id', $visitId)->value('id')];
}

describe('Task 1: checkpoint hand-off', function () {
    it('sends a checkpoint at once when "send" is omitted', function () {
        $data = $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/applications/{$this->application->id}/checkpoints", [
            'checkpoint_name' => 'Report', 'type' => 'monitoring', 'respondent' => 'applicant',
        ])->assertStatus(201)->json('data');
        expect($data['sent_at'])->not->toBeNull();
        expect(MECheckpoint::find($data['id'])->sent_at)->not->toBeNull();
    });

    it('hides an unsent checkpoint from the applicant, refuses its submission, and sends it once', function () {
        $created = $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/applications/{$this->application->id}/checkpoints", [
            'checkpoint_name' => 'Report', 'type' => 'monitoring', 'respondent' => 'applicant', 'send' => false,
        ])->assertStatus(201);
        expect($created->json())->toHaveKey('data.sent_at')->and($created->json('data.sent_at'))->toBeNull();
        $id = $created->json('data.id');
        $before = handoffNotes($this->applicantUser);

        $this->actingAsApplicant()->getJson(HANDOFF_ME . "/applications/{$this->application->id}/checkpoints")
            ->assertOk()->assertJsonCount(0, 'data.checkpoints');
        $this->actingAsOrgOwner()->getJson(HANDOFF_ME . "/applications/{$this->application->id}/checkpoints")
            ->assertOk()->assertJsonCount(1, 'data.checkpoints');
        $this->actingAsApplicant()->postJson(HANDOFF_ME . "/checkpoints/$id/submit", ['written_report' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', 'This checkpoint has not been sent yet');

        // only the program owner may send it
        $this->actingAsApplicant()->postJson(HANDOFF_ME . "/checkpoints/$id/send")->assertForbidden();

        $sentAt = $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/checkpoints/$id/send")->assertOk()->json('data.sent_at');
        expect($sentAt)->not->toBeNull()->and(handoffNotes($this->applicantUser))->toBe($before + 1);

        // sending again changes nothing
        $this->travel(5)->minutes();
        $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/checkpoints/$id/send")->assertOk()->assertJsonPath('data.sent_at', $sentAt);
        expect(handoffNotes($this->applicantUser))->toBe($before + 1);

        $this->actingAsApplicant()->getJson(HANDOFF_ME . "/applications/{$this->application->id}/checkpoints")
            ->assertOk()->assertJsonCount(1, 'data.checkpoints');
        $this->actingAsApplicant()->postJson(HANDOFF_ME . "/checkpoints/$id/submit", ['written_report' => 'Done'])->assertCreated();
    });

    it('backfills sent_at for checkpoints that existed before the hand-off', function () {
        $id = DB::table('m_e_checkpoints')->insertGetId([
            'app_id' => $this->application->id, 'program_id' => $this->program->id, 'checkpoint_name' => 'Old', 'type' => 'monitoring',
            'status' => 'pending', 'sent_at' => null, 'created_at' => '2026-01-02 03:04:05', 'updated_at' => now(),
        ]);
        (require database_path('migrations/2026_10_31_000001_add_monitoring_handoff_and_fee_fields.php'))->up();
        expect((string) DB::table('m_e_checkpoints')->where('id', $id)->value('sent_at'))->toBe('2026-01-02 03:04:05');
    });
});

describe('Task 3: structured change requests', function () {
    it('stores flagged items on an M&E submission, returns them, enforces limits and clears them on verify', function () {
        $checkpoint = $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/applications/{$this->application->id}/checkpoints", [
            'checkpoint_name' => 'Report', 'type' => 'monitoring', 'respondent' => 'applicant',
        ])->json('data.id');
        $submission = $this->actingAsApplicant()->postJson(HANDOFF_ME . "/checkpoints/$checkpoint/submit", ['written_report' => 'Done'])->json('data.id');
        $items = [['field_id' => 'jobs_created', 'label' => 'Jobs created', 'note' => 'Use the payroll count.']];

        $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/submissions/$submission/request-changes", [
            'reviewer_note' => 'Fix jobs', 'flagged_items' => [['field_id' => 'x', 'label' => 'X', 'note' => str_repeat('a', 241)]],
        ])->assertStatus(422);
        $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/submissions/$submission/request-changes", ['flagged_items' => $items])->assertStatus(422);

        $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/submissions/$submission/request-changes", ['reviewer_note' => 'Fix jobs', 'flagged_items' => $items])
            ->assertOk()->assertJsonPath('data.flagged_items.0.field_id', 'jobs_created');
        $this->actingAsApplicant()->getJson(HANDOFF_ME . "/applications/{$this->application->id}/checkpoints")
            ->assertOk()->assertJsonPath('data.checkpoints.0.submission.flagged_items.0.note', 'Use the payroll count.');

        $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/submissions/$submission/verify")->assertOk();
        expect(\App\Models\Programs\Monitoring\MESubmission::find($submission)->flagged_items)->toBeNull();
    });

    it('stores flagged items on site visit work through both request-changes routes and marks the checkpoint', function () {
        $this->reviewerUser->update(['lipr_wallet' => 'wallet-1']);
        foreach ([fn ($order) => HANDOFF_ME . "/reviewer-orders/$order/site-visit/request-changes", fn ($order) => "/api/v1/programs/reviewer-orders/$order/request-modification"] as $route) {
            [$checkpointId, $visitId, $orderId] = handoffVisit($this);
            $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/accept")->assertOk();
            $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/submit", ['observed_actions' => 'Seen'])->assertOk();

            $this->actingAsOrgOwner()->postJson($route($orderId), [
                'modification_note' => 'Add the photo', 'flagged_items' => [['field_id' => 'photo', 'label' => 'Photo', 'note' => 'Missing']],
            ])->assertOk()->assertJsonPath('data.flagged_items.0.field_id', 'photo');
            expect(MECheckpoint::find($checkpointId)->status)->toBe('changes_requested');
            $this->actingAsReviewer()->getJson(HANDOFF_ME . "/site-visits/$visitId")->assertOk()
                ->assertJsonPath('data.order.flagged_items.0.label', 'Photo')
                ->assertJsonPath('data.order.modification_note', 'Add the photo');
        }
    });
});

describe('Task 4: site visit fee proposals', function () {
    it('lets only the assigned officer propose while pending, and the owner accept it', function () {
        [, $visitId, $orderId] = handoffVisit($this, fee: 300);
        $other = User::factory()->create(['user_type_id' => 6]);

        $this->actingAs($other, 'sanctum')->postJson(HANDOFF_ME . "/site-visits/$visitId/propose-fee", ['proposed_fee' => 450])->assertForbidden();
        $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/propose-fee", ['proposed_fee' => -1])->assertStatus(422);
        $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/propose-fee", ['proposed_fee' => 450, 'note' => 'Far away'])
            ->assertOk()->assertJsonPath('data.fee_status', 'pending_owner');

        // the visit cannot be accepted while the owner has not answered
        $this->reviewerUser->update(['lipr_wallet' => 'wallet-1']);
        $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/accept")->assertStatus(422);

        $this->actingAsReviewer()->postJson(HANDOFF_ME . "/reviewer-orders/$orderId/site-visit/answer-fee", ['decision' => 'accept'])->assertForbidden();
        $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/reviewer-orders/$orderId/site-visit/answer-fee", ['decision' => 'maybe'])->assertStatus(422);
        $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/reviewer-orders/$orderId/site-visit/answer-fee", ['decision' => 'accept'])
            ->assertOk()->assertJsonPath('data.fee_status', 'accepted');
        expect((float) ReviewerOrder::find($orderId)->fee_amount)->toBe(450.0);
        $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/reviewer-orders/$orderId/site-visit/answer-fee", ['decision' => 'accept'])->assertStatus(422);

        $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/accept")->assertOk();
        $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/propose-fee", ['proposed_fee' => 999])->assertStatus(422);
    });

    it('keeps the fee on decline and clears a proposal when the visit is reassigned', function () {
        [$checkpointId, $visitId, $orderId] = handoffVisit($this, fee: 300);
        $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/propose-fee", ['proposed_fee' => 450])->assertOk();
        $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/reviewer-orders/$orderId/site-visit/answer-fee", ['decision' => 'decline'])
            ->assertOk()->assertJsonPath('data.fee_status', 'declined');
        expect((float) ReviewerOrder::find($orderId)->fee_amount)->toBe(300.0);

        $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/propose-fee", ['proposed_fee' => 400])->assertOk();
        $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/checkpoints/$checkpointId/site-visit/assign", [
            'reviewer_id' => $this->reviewerUser->id, 'start_date' => now()->addWeeks(2)->toDateString(), 'assign_type' => 'internal', 'reviewer_fee' => 350,
        ])->assertStatus(201);
        $order = ReviewerOrder::find($orderId);
        expect($order->fee_status)->toBeNull()->and($order->proposed_fee)->toBeNull()
            ->and($order->fee_proposal_note)->toBeNull()->and($order->fee_proposed_at)->toBeNull();
    });
});

describe('Task 5: officer location and listed price', function () {
    it('returns the officer service location and price on a visit, nulls without a service, and on the provider search', function () {
        [, $visitId] = handoffVisit($this);
        $this->actingAsOrgOwner()->getJson(HANDOFF_ME . "/site-visits/$visitId")->assertOk()
            ->assertJsonPath('data.reviewer.lat', null)->assertJsonPath('data.reviewer.price', null)
            ->assertJsonPath('data.reviewer.service_location', null)->assertJsonPath('data.reviewer.service_name', null);

        $provider = User::factory()->create(['user_type_id' => 3, 'first_name' => 'Zed']);
        Services::create(['user_id' => $provider->id, 'name' => 'Field coaching', 'category' => 'business_coaching', 'price' => 2500, 'lat' => '-1.28', 'lng' => '36.82', 'location' => 'Nairobi']);
        [, $visit2] = handoffVisit($this, $provider);
        $this->actingAsOrgOwner()->getJson(HANDOFF_ME . "/site-visits/$visit2")->assertOk()
            ->assertJsonPath('data.reviewer.lat', '-1.28')->assertJsonPath('data.reviewer.lng', '36.82')
            ->assertJsonPath('data.reviewer.service_location', 'Nairobi')->assertJsonPath('data.reviewer.price', 2500)
            ->assertJsonPath('data.reviewer.service_name', 'Field coaching');

        $row = collect($this->actingAsOrgOwner()->getJson('/api/v1/programs/third-party-providers?per_page=50')->assertOk()->json('data'))
            ->firstWhere('id', $provider->id);
        expect($row['lat'])->toBe('-1.28')->and($row['price'])->toBe(2500)->and($row['service_location'])->toBe('Nairobi')
            ->and($row['services'][0])->toMatchArray(['price' => 2500, 'lat' => '-1.28', 'lng' => '36.82', 'location' => 'Nairobi']);
    });
});

describe('Task 6: provider work list', function () {
    it('returns the fee on inspection rows and does not fail for a provider with an MPRV audit', function () {
        $provider = User::factory()->create(['user_type_id' => 3]);
        [, $visitId, $orderId] = handoffVisit($this, $provider, 500);
        $this->actingAs($provider, 'sanctum')->postJson(HANDOFF_ME . "/site-visits/$visitId/propose-fee", ['proposed_fee' => 650])->assertOk();
        $milestone = \App\Models\Programs\ProgramMilestone::factory()->create(['app_id' => $this->application->id]);
        DB::table('milestone_verifications')->insert([
            'milestone_id' => $milestone->id, 'submitted_by' => $this->applicantUser->id, 'auditor_id' => $provider->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = collect($this->actingAs($provider, 'sanctum')->getJson('/api/v1/programs/provider/work')->assertOk()->json('data'));
        $inspection = $rows->firstWhere('kind', 'inspection');
        expect($inspection)->toMatchArray(['order_id' => $orderId, 'amount' => 500, 'currency' => ReviewerOrder::find($orderId)->currency,
            'fee_status' => 'pending_owner', 'proposed_fee' => 650, 'payment_status' => 'unpaid'])
            ->and($rows->firstWhere('kind', 'audit'))->toMatchArray(['order_id' => null, 'amount' => null, 'currency' => null, 'work_status' => null, 'payment_status' => null]);
    });
});

describe('Task 7: a paid visit is only accepted when it can be paid', function () {
    it('needs the officer wallet and enough program funds, and tells the owner about a shortfall', function () {
        [, $visitId] = handoffVisit($this, fee: 500);
        $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/accept")->assertStatus(422)
            ->assertJsonPath('message', 'Set up your wallet before accepting a paid site visit, so you can be paid when your report is approved.');

        $this->reviewerUser->update(['lipr_wallet' => 'wallet-1']);
        $this->wallet->update(['balance' => 100]);
        $ownerNotes = handoffNotes($this->orgUser);
        $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/accept")->assertStatus(422)
            ->assertJsonPath('required', 500)->assertJsonPath('available', 100)->assertJsonPath('shortfall', 400);
        expect(handoffNotes($this->orgUser))->toBe($ownerNotes + 1);

        $this->wallet->update(['balance' => 500]);
        $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/accept")->assertOk()->assertJsonPath('data.acceptance_status', 'accepted');
    });

    it('does not check a free visit', function () {
        [, $visitId] = handoffVisit($this);
        $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/accept")->assertOk();
    });
});

describe('Task 8: the tujitume theme', function () {
    it('saves and returns theme "tujitume"', function () {
        $response = $this->actingAsOrgOwner()->patchJson('/api/v1/user/settings', ['theme' => 'tujitume', 'accent_color' => '#6bc42e'])->assertOk();
        expect(data_get($response->json(), 'data.theme') ?? data_get($response->json(), 'data.settings.theme'))->toBe('tujitume');
        expect(DB::table('user_settings')->where('user_id', $this->orgUser->id)->value('theme'))->toBe('tujitume');
    });
});

describe('Task 9 and the plan: the visit follows its checkpoint and the plan carries its order', function () {
    it('fills a visit created outside the M&E tab from its checkpoint and business', function () {
        $checkpoint = MECheckpoint::create([
            'app_id' => $this->application->id, 'program_id' => $this->program->id, 'checkpoint_name' => 'Checkpoint 2', 'type' => 'monitoring',
            'respondent' => 'site_visit_officer', 'require_site_visit' => true, 'sent_at' => now(), 'requirement' => 'Count the cows',
            'kpis_to_track' => [['name' => 'Cows']], 'evidence_required' => [['label' => 'Photo']],
        ]);
        $visit = MESiteVisit::create(['checkpoint_id' => $checkpoint->id, 'app_id' => $this->application->id, 'reviewer_id' => $this->reviewerUser->id, 'status' => 'scheduled']);
        expect($visit->fresh()->kpi_targets)->toBe([['name' => 'Cows']])
            ->and($visit->fresh()->data_collection_fields)->toBe([['label' => 'Photo']])
            ->and($visit->fresh()->objective)->toBe('Count the cows');
    });

    it('re-copies evidence-only checkpoint changes to an accepted visit and reports the visit order in the plan', function () {
        [$checkpointId, $visitId] = handoffVisit($this);
        $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/accept")->assertOk();
        $this->actingAsOrgOwner()->patchJson(HANDOFF_ME . "/checkpoints/$checkpointId", ['evidence_required' => [['label' => 'Signed form']]])->assertOk();
        expect(MESiteVisit::find($visitId)->data_collection_fields)->toBe([['label' => 'Signed form']]);

        $plan = fn () => $this->actingAsOrgOwner()->getJson(HANDOFF_ME . "/applications/{$this->application->id}/checkpoints")->assertOk();
        $plan()->assertJsonPath('data.checkpoints.0.site_visit.order.acceptance_status', 'accepted')
            ->assertJsonPath('data.checkpoints.0.site_visit.order.work_status', 'in_progress');

        $this->actingAsReviewer()->postJson(HANDOFF_ME . "/site-visits/$visitId/submit", ['observed_actions' => 'Seen'])->assertOk();
        $plan()->assertJsonPath('data.checkpoints.0.status.value', 'submitted')
            ->assertJsonPath('data.checkpoints.0.site_visit.order.work_status', 'delivered');

        $orderId = ReviewerOrder::where('site_visit_id', $visitId)->value('id');
        $this->actingAsOrgOwner()->postJson(HANDOFF_ME . "/reviewer-orders/$orderId/site-visit/approve")->assertOk();
        $plan()->assertJsonPath('data.checkpoints.0.status.value', 'verified')
            ->assertJsonPath('data.checkpoints.0.site_visit.order.work_status', 'approved');
    });
});
