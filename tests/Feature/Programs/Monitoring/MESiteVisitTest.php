<?php

use App\Http\Resources\Program\Monitoring\MESiteVisitResource;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\ReviewerOrder;
use Illuminate\Http\Request;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Monitoring site visits', function () {
    it('requires authentication to assign a site visit', function () {
        assertProgramUnauthenticated('POST', 'monitoring/checkpoints/999999/site-visit/assign');
    });

    it('returns the site visit order modification note and flagged items', function () {
        $visit = new MESiteVisit();
        $visit->setRelation('reviewerOrder', new ReviewerOrder([
            'modification_note' => 'Please correct the employment total.',
            'flagged_items' => [['field_id' => 'employment_total', 'label' => 'Employment total', 'note' => 'Use the verified count.']],
        ]));

        $order = (new MESiteVisitResource($visit))->toArray(Request::create('/'))['order'];

        expect($order['modification_note'])->toBe('Please correct the employment total.')
            ->and($order['flagged_items'])->toHaveCount(1)
            ->and($order['flagged_items'][0]['field_id'])->toBe('employment_total');
    });
});

describe('Nothing reaches the officer until the program owner sends the checkpoint', function () {
    it('keeps an assigned-but-unsent site visit silent and hidden, then delivers it on send', function () {
        $base = '/api/v1/programs/monitoring';
        $checkpoint = $this->actingAsOrgOwner()->postJson("$base/applications/{$this->application->id}/checkpoints", [
            'checkpoint_name' => 'Farm visit', 'type' => 'monitoring', 'respondent' => 'site_visit_officer', 'send' => false,
            'require_site_visit' => true, 'should_notify_applicant' => true,
        ])->assertStatus(201)->json('data.id');

        $this->actingAsOrgOwner()->postJson("$base/checkpoints/$checkpoint/site-visit/assign", [
            'reviewer_id' => $this->reviewerUser->id, 'start_date' => now()->addWeek()->toDateString(), 'assign_type' => 'internal',
        ])->assertStatus(201);
        $visitId = \App\Models\Programs\Monitoring\MESiteVisit::where('checkpoint_id', $checkpoint)->value('id');

        // set up, not sent: the officer has no notification, no visit in their list, and cannot act on it
        expect(\App\Models\Communication\Notifications::where('receiver_id', $this->reviewerUser->id)->count())->toBe(0);
        expect(\App\Models\Communication\Notifications::where('receiver_id', $this->applicantUser->id)->count())->toBe(0);
        $this->actingAsReviewer()->getJson("$base/reviewer/{$this->reviewerUser->id}/site-visits")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsReviewer()->postJson("$base/site-visits/$visitId/accept")->assertStatus(422);
        $this->actingAsReviewer()->getJson("$base/site-visits/$visitId")->assertStatus(422);
        $this->actingAsReviewer()->getJson('/api/v1/programs/reviewer/orders')->assertOk()->assertJsonCount(0, 'data');

        // the program owner sends it: now the officer and the applicant are told, and the visit appears
        $this->actingAsOrgOwner()->postJson("$base/checkpoints/$checkpoint/send")->assertOk();
        expect(\App\Models\Communication\Notifications::where('receiver_id', $this->reviewerUser->id)->count())->toBe(1);
        expect(\App\Models\Communication\Notifications::where('receiver_id', $this->applicantUser->id)->count())->toBe(1);
        $this->actingAsReviewer()->getJson("$base/reviewer/{$this->reviewerUser->id}/site-visits")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsReviewer()->getJson('/api/v1/programs/reviewer/orders')->assertOk()->assertJsonCount(1, 'data');
    });

    it('notifies the officer straight away when the checkpoint was already sent', function () {
        $base = '/api/v1/programs/monitoring';
        $checkpoint = $this->actingAsOrgOwner()->postJson("$base/applications/{$this->application->id}/checkpoints", [
            'checkpoint_name' => 'Farm visit', 'type' => 'monitoring', 'respondent' => 'site_visit_officer', 'send' => true,
            'require_site_visit' => true, 'should_notify_applicant' => false,
        ])->assertStatus(201)->json('data.id');
        $this->actingAsOrgOwner()->postJson("$base/checkpoints/$checkpoint/site-visit/assign", [
            'reviewer_id' => $this->reviewerUser->id, 'start_date' => now()->addWeek()->toDateString(), 'assign_type' => 'internal',
        ])->assertStatus(201);
        expect(\App\Models\Communication\Notifications::where('receiver_id', $this->reviewerUser->id)->count())->toBe(1);
    });
});
