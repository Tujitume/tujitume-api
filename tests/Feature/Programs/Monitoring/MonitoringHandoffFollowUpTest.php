<?php

use App\Models\Auth\User;
use App\Models\Communication\Notifications;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\ReviewerOrder;

uses(Tests\Feature\Programs\ProgramTestCase::class);

/*
 * Follow-ups to the monitoring hand-off (backend-fix-for-dev.txt TASK 9, NOTIFICATIONS and the PART 2 checks):
 * the visit keeps the officer's answers when its checkpoint is edited, confirm_location round-trips, and the
 * officer's site visit notices open the visit itself.
 */

const FOLLOWUP_ME = '/api/v1/programs/monitoring';

/** A sent site-visit checkpoint with an officer assigned; returns [checkpointId, visitId, orderId]. */
function followUpVisit($test, User $officer, array $checkpoint = [], array $assign = []): array
{
    $checkpointId = $test->actingAs($test->orgUser, 'sanctum')->postJson(FOLLOWUP_ME . "/applications/{$test->application->id}/checkpoints", array_merge([
        'checkpoint_name' => 'Farm visit', 'type' => 'monitoring', 'respondent' => 'site_visit_officer', 'send' => true,
        'require_site_visit' => true, 'should_notify_applicant' => false,
    ], $checkpoint))->assertStatus(201)->json('data.id');
    $test->actingAs($test->orgUser, 'sanctum')->postJson(FOLLOWUP_ME . "/checkpoints/$checkpointId/site-visit/assign", array_merge([
        'reviewer_id' => $officer->id, 'start_date' => now()->addWeek()->toDateString(), 'assign_type' => 'internal', 'reviewer_fee' => 0,
    ], $assign))->assertStatus(201);
    $visitId = MESiteVisit::where('checkpoint_id', $checkpointId)->value('id');

    return [$checkpointId, $visitId, ReviewerOrder::where('site_visit_id', $visitId)->value('id')];
}

describe('the visit follows its checkpoint without losing the officer\'s answers', function () {
    it('keeps earlier answers on fields that stay when the checkpoint is edited after changes were requested', function () {
        $this->reviewerUser->update(['lipr_wallet' => 'wallet-1']);
        [$checkpointId, $visitId, $orderId] = followUpVisit($this, $this->reviewerUser, [
            'kpis_to_track' => [['kpi' => 'Cows', 'target' => '10']],
            'evidence_required' => [['label' => 'Photo'], ['label' => 'Farm size']],
        ]);
        $this->actingAsReviewer()->postJson(FOLLOWUP_ME . "/site-visits/$visitId/accept")->assertOk();
        $this->actingAsReviewer()->postJson(FOLLOWUP_ME . "/site-visits/$visitId/submit", [
            'observed_actions' => 'Seen',
            'kpi_targets' => [['kpi' => 'Cows', 'target' => '10', 'actual' => '12']],
            'data_collection_fields' => [['label' => 'Photo', 'value' => 'photo.jpg'], ['label' => 'Farm size', 'value' => '4 acres']],
        ])->assertOk();
        $this->actingAsOrgOwner()->postJson(FOLLOWUP_ME . "/reviewer-orders/$orderId/site-visit/request-changes", ['modification_note' => 'Add the signature'])->assertOk();

        // the owner adds a field and drops another while the visit is back with the officer
        $this->actingAsOrgOwner()->patchJson(FOLLOWUP_ME . "/checkpoints/$checkpointId", [
            'evidence_required' => [['label' => 'Farm size'], ['label' => 'Signature']],
        ])->assertOk();

        $visit = MESiteVisit::find($visitId);
        expect($visit->data_collection_fields)->toBe([['label' => 'Farm size', 'value' => '4 acres'], ['label' => 'Signature']])
            ->and($visit->kpi_targets)->toBe([['kpi' => 'Cows', 'target' => '10', 'actual' => '12']]);
    });

    it('leaves the visit copy alone once the report is delivered', function () {
        [$checkpointId, $visitId] = followUpVisit($this, $this->reviewerUser, ['evidence_required' => [['label' => 'Photo']]]);

        ReviewerOrder::where('site_visit_id', $visitId)->update(['work_status' => 'delivered']);
        $this->actingAsOrgOwner()->patchJson(FOLLOWUP_ME . "/checkpoints/$checkpointId", ['evidence_required' => [['label' => 'Changed']]])->assertOk();
        expect(MESiteVisit::find($visitId)->data_collection_fields)->toBe([['label' => 'Photo']]);
    });
});

describe('confirm_location', function () {
    it('stores confirm_location on the visit and returns it as a boolean on the visit and in the plan', function () {
        [, $visitId] = followUpVisit($this, $this->reviewerUser, assign: ['confirm_location' => true]);

        $this->actingAsReviewer()->getJson(FOLLOWUP_ME . "/site-visits/$visitId")->assertOk()
            ->assertJsonPath('data.confirm_location', true);
        $this->actingAsOrgOwner()->getJson(FOLLOWUP_ME . "/applications/{$this->application->id}/checkpoints")->assertOk()
            ->assertJsonPath('data.checkpoints.0.site_visit.confirm_location', true);

        [, $otherVisitId] = followUpVisit($this, $this->reviewerUser, ['checkpoint_name' => 'Second visit']);
        $this->actingAsReviewer()->getJson(FOLLOWUP_ME . "/site-visits/$otherVisitId")->assertOk()
            ->assertJsonPath('data.confirm_location', false);
    });
});

describe('officer site visit notices open the visit', function () {
    it('links the assignment and the fee answer to the visit, in the provider portal for a service provider', function () {
        $provider = User::factory()->create(['user_type_id' => 3]);
        foreach ([[$this->reviewerUser, 'dashboard.reviewerGrantOrg.siteVisitDetail::id='], [$provider, 'dashboard.serviceProvider.programWork.siteVisitReport::visitId=']] as [$officer, $prefix]) {
            [, $visitId, $orderId] = followUpVisit($this, $officer, ['checkpoint_name' => 'Visit for ' . $officer->id], ['reviewer_fee' => 300]);
            expect(Notifications::where('receiver_id', $officer->id)->latest('id')->value('link'))->toBe($prefix . $visitId);

            $this->actingAs($officer, 'sanctum')->postJson(FOLLOWUP_ME . "/site-visits/$visitId/propose-fee", ['proposed_fee' => 450])->assertOk();
            $this->actingAsOrgOwner()->postJson(FOLLOWUP_ME . "/reviewer-orders/$orderId/site-visit/answer-fee", ['decision' => 'decline'])->assertOk();
            $notice = Notifications::where('receiver_id', $officer->id)->latest('id')->first();
            expect($notice->link)->toBe($prefix . $visitId);
        }
    });
});
