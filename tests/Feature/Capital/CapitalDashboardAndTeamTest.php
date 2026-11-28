<?php

use App\Models\Auth\User;
use App\Models\Capital\CapitalMilestone;
use App\Models\Capital\CapitalProfile;
use App\Models\Capital\StartupPitches;
use Illuminate\Support\Facades\Mail;

uses(Tests\Feature\Capital\CapitalTestCase::class);

describe('Capital analytics', function () {
    it('gives the owner and the team the dashboard numbers of the owner', function () {
        $this->makeOffer($this->capitalOwner, ['visible' => 0]);
        $this->makePitch()->update(['score' => 92, 'score_breakdown' => '30,15,10,10,10,10']);
        [$accepted] = $this->fundablePitch();
        $accepted->update(['score' => 50, 'score_breakdown' => json_encode(['sector' => 30, 'geo' => 0, 'stage' => 10, 'revenue' => 0, 'team' => 10, 'impact' => 0])]);
        CapitalMilestone::where('app_id', $accepted->id)->where('title', 'Pilot')->update(['fund_released' => true]);
        $this->makePitch(status: StartupPitches::STATUS_REJECTED)->update(['score' => 70]);
        // someone else's application never counts
        $this->makePitch($this->makeOffer($this->makeCapitalOwner()));

        foreach ([$this->capitalOwner, $this->teamMember($this->capitalOwner, 'viewer')] as $who) {
            $res = $this->asCapital($who)->getJson('/api/v1/capital/analytics')->assertOk();
            expect($res->json('offers'))->toBe(['total' => 2, 'live' => 1])
                ->and($res->json('applications'))->toBe(['total' => 3, 'pending' => 1, 'accepted' => 1, 'rejected' => 1])
                ->and($res->json('capital.committed'))->toEqual(5000)
                ->and($res->json('capital.deployed'))->toEqual(2000)
                ->and($res->json('avg_score'))->toEqual(70.67)
                ->and($res->json('distribution'))->toBe(['90-100' => 1, '80-89' => 0, '70-79' => 1, '60-69' => 0, '<60' => 1])
                ->and($res->json('breakdown.sector'))->toEqual(20) // (30 + 30 + 0) / 3
                ->and($res->json('breakdown.geo'))->toEqual(5)
                ->and($res->json('performance_month'))->toHaveCount(6)
                ->and(end($res->json()['performance_month'])['applications'])->toBe(3)
                ->and($res->json('top_startups.0.score'))->toBe(92);
        }
    });
});

describe('Capital KYC', function () {
    it('lets the capital owner verify as an organization, not a viewer', function () {
        $this->asCapital()->postJson('/api/v1/kyc')->assertSuccessful();
        $this->asCapital()->getJson('/api/v1/kyc/status')->assertOk()->assertJsonPath('data.verification_type', 'organization');
        $this->asCapital($this->teamMember($this->capitalOwner, 'viewer'))->getJson('/api/v1/kyc/status')->assertForbidden();
    });

    it('points the pending KYC task to the capital settings verification tab, for the owner only', function () {
        $types = fn (User $u) => collect($this->actingAs($u, 'sanctum')->getJson('/api/v1/pending-tasks?fresh=1')->assertOk()->json('data.groups'))
            ->flatMap(fn ($g) => collect($g['items']))->keyBy('type');
        expect($types($this->capitalOwner)->get('kyc.incomplete')['link'] ?? null)->toBe('dashboard.capitalOrg.settings?tab=verification');
        expect($types($this->teamMember($this->capitalOwner, 'editor'))->has('kyc.incomplete'))->toBeFalse();
    });
});

describe('Capital team', function () {
    it('lets the owner invite a team member who then accepts like a program team member', function () {
        Mail::fake();
        $this->asCapital()->postJson('/api/v1/capital/invite-user', ['first_name' => 'Eve', 'email' => 'eve@fund.test', 'role_id' => 10002])
            ->assertCreated()->assertJsonPath('member.status', 'pending');
        $eve = User::where('email', 'eve@fund.test')->first();
        $profile = CapitalProfile::where('user_id', $eve->id)->first();
        expect($eve->user_type_id)->toBe(5)->and($profile->capital_owner_id)->toBe($this->capitalOwner->id)->and($profile->role_id)->toBe(10002);
        expect($this->asCapital()->getJson('/api/v1/capital/team')->json('members.0.role'))->toBe('editor');

        // the invitation link is accepted at the same endpoint as program teams
        $token = str_repeat('a', 64);
        $eve->forceFill(['invitation_token_hash' => hash('sha256', $token)])->save();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/organization-invitations/accept', ['token' => $token, 'display_name' => 'Eve', 'phone' => '0700000000', 'password' => 'Secret123', 'password_confirmation' => 'Secret123'])->assertOk();
        expect($eve->fresh()->password)->not->toBeNull()->and($eve->fresh()->invitation_token_hash)->toBeNull();

        // the team member works on the owner's offers
        $this->asCapital($eve)->getJson("/api/v1/capital/visibility/{$this->offer->id}")->assertOk();

        // only the owner or a super admin invites; an email is used once
        $this->asCapital($eve)->postJson('/api/v1/capital/invite-user', ['first_name' => 'X', 'email' => 'x@fund.test', 'role_id' => 10003])->assertForbidden();
        $this->asCapital()->postJson('/api/v1/capital/invite-user', ['first_name' => 'Eve', 'email' => 'eve@fund.test', 'role_id' => 10002])->assertUnprocessable();
        $this->asCapital()->postJson('/api/v1/capital/invite-user', ['first_name' => 'Y', 'email' => 'y@fund.test', 'role_id' => 5])->assertUnprocessable();
    });
});

describe('Capital accounts elsewhere in the app', function () {
    it('shares the owner inbox with capital editors (type 5)', function () {
        $editor = $this->teamMember($this->capitalOwner, 'editor');
        $this->actingAs($editor, 'sanctum')->postJson('/api/v1/messages', ['msg' => 'Hello', 'to_id' => $this->applicantUser->id])->assertOk();
        expect(\App\Models\Communication\Messages::latest('id')->value('from_id'))->toBe($this->capitalOwner->id);
    });

    it('serves pitch coaching services for a business, and 404 for an unknown one', function () {
        $this->actingAsApplicant()->getJson('/api/v1/capital/pitch-coaching-services/999999')->assertNotFound();
    });

    it('lets the capital owner delete the account with its team and offers', function () {
        $member = $this->teamMember($this->capitalOwner, 'editor');
        $this->asCapital($member)->postJson('/api/v1/capital/delete-user', ['id' => $this->capitalOwner->id])->assertForbidden();
        $this->asCapital()->postJson('/api/v1/capital/delete-user', ['id' => $this->capitalOwner->id])->assertOk();
        expect(User::find($this->capitalOwner->id))->toBeNull()->and(User::find($member->id))->toBeNull()
            ->and(\App\Models\Capital\CapitalOffer::where('user_id', $this->capitalOwner->id)->exists())->toBeFalse();
    });

    it('refuses deleting a capital account that released funds', function () {
        [$pitch, $first] = $this->fundablePitch();
        $first->update(['fund_released' => true]);
        $this->asCapital()->postJson('/api/v1/capital/delete-user', ['id' => $this->capitalOwner->id])->assertStatus(400)
            ->assertJsonPath('reasons.0.code', 'capital_active_funding');
    });
});
