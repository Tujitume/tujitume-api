<?php

use App\Events\StepAgreementUpdated;
use App\Models\Auth\User;
use App\Models\Communication\Notifications;
use App\Models\Programs\MilestonePreAgreement;
use App\Models\Programs\ProgramMilestone;
use Illuminate\Support\Facades\Event;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// ISSUE-42 (owner decision): the step Conversation is live (a broadcast on the step's private channel) and reaches the
// applicant and the whole owner program team.

beforeEach(function () {
    $this->ms = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 1, 'is_template' => false, 'mprv_required' => true]);
    MilestonePreAgreement::create(['milestone_id' => $this->ms->id, 'verification_type' => 'mprv', 'status' => 'submitted', 'submitted_by' => $this->applicantUser->id]);
    // a Super Admin on the owner's team
    $this->admin = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
    \Illuminate\Support\Facades\DB::table('roles')->insertOrIgnore(['id' => 10001, 'name' => 'super_admin', 'access_types' => '[]']);
    \App\Models\Auth\OrganizationUserRole::create(['user_id' => $this->admin->id, 'organization_id' => $this->organization->id, 'role_id' => 10001]);
});

it('broadcasts a new message on the step channel and tells the whole owner team', function () {
    Event::fake([StepAgreementUpdated::class]);
    $this->actingAs($this->applicantUser, 'sanctum')->postJson("/api/v1/programs/milestones/{$this->ms->id}/agreements/mprv/comment", ['comment' => 'Here are the receipts.'])->assertSuccessful();

    Event::assertDispatched(StepAgreementUpdated::class, fn ($e) => $e->milestoneId === $this->ms->id && $e->action === 'comment');
    expect(Notifications::where('receiver_id', $this->orgUser->id)->count())->toBeGreaterThan(0)
        ->and(Notifications::where('receiver_id', $this->admin->id)->count())->toBeGreaterThan(0)
        ->and(Notifications::where('receiver_id', $this->applicantUser->id)->count())->toBe(0);
});

it('lets only people who may open the milestone listen on its channel', function () {
    $channel = 'step-agreement.'.$this->ms->id;
    $auth = fn (User $u) => app(\App\Service\Program\ProgramAccess::class)->canViewApplication($u, $this->application);
    expect($auth($this->applicantUser))->toBeTrue()->and($auth($this->orgUser))->toBeTrue()
        ->and($auth(User::factory()->create(['user_type_id' => 1])))->toBeFalse();
    expect((new StepAgreementUpdated($this->ms->id, 'mprv', 'comment', 1))->broadcastOn()->name)->toBe('private-'.$channel);
});
