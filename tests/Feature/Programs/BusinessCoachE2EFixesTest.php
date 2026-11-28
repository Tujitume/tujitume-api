<?php

// Fixes found by the Business Coach browser end-to-end run (28 Nov)

use App\Models\Auth\User;
use App\Models\Services\Services;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Find third-party auditors', function () {
    it('finds service providers by name and by a coaching service when a search word is typed', function () {
        $byName = User::factory()->create(['user_type_id' => 3, 'first_name' => 'Coachy', 'last_name' => 'Provider']);
        $byService = User::factory()->create(['user_type_id' => 3, 'first_name' => 'Zed', 'last_name' => 'Mentor']);
        Services::create(['user_id' => $byService->id, 'name' => 'Growth sessions', 'category' => 'business_coaching', 'price' => 1000]);

        $ids = fn ($search) => collect($this->actingAsOrgOwner()
            ->getJson('/api/v1/programs/third-party-providers?search=' . urlencode($search))
            ->assertOk()->json('data'))->pluck('id')->all();

        expect($ids('Provider'))->toContain($byName->id)->not->toContain($byService->id)
            ->and($ids('business coach'))->toContain($byService->id);
    });
});

describe('Provider added as a third-party Business Coach', function () {
    it('tells the provider in Business Coach words, never "reviewer"', function () {
        $provider = User::factory()->create(['user_type_id' => 3]);
        $this->actingAsOrgOwner()->postJson('/api/v1/organizations/providers', ['user_id' => $provider->id])->assertSuccessful();

        $text = \Illuminate\Support\Facades\DB::table('notifications')->where('receiver_id', $provider->id)->value('text');
        expect($text)->toContain('third-party Business Coach')->and(strtolower($text))->not->toContain('reviewer');
    });
});

describe('Internal Business Coach in the coach pickers', function () {
    it('lists a team member with the internal Business Coach role as an internal coach', function () {
        foreach ([10001 => 'super_admin', 10004 => 'internal_reviewer'] as $id => $name) {
            \App\Models\Auth\Role::find($id) ?? \App\Models\Auth\Role::forceCreate(['id' => $id, 'name' => $name, 'access_types' => '[]']);
        }
        \App\Models\Auth\OrganizationUserRole::create(['organization_id' => $this->organization->id, 'user_id' => $this->orgUser->id, 'role_id' => 10001, 'status' => 'active', 'accepted_at' => now()]);
        $coach = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
        \App\Models\Auth\OrganizationUserRole::create(['organization_id' => $this->organization->id, 'user_id' => $coach->id, 'role_id' => 10004, 'status' => 'active', 'accepted_at' => now()]);

        $row = collect($this->actingAsOrgOwner()->getJson("/api/v1/organizations/{$this->organization->id}/reviewers")->assertOk()->json('reviewers'))
            ->firstWhere('id', $coach->id);
        expect($row)->not->toBeNull()->and($row['reviewer_type'])->toBe('internal');
    });
});

describe('Program team decides on Business Coach orders', function () {
    it('lets an editor of the organization see and approve delivered work, and refuses a viewer', function () {
        foreach ([10002 => 'editor', 10003 => 'viewer'] as $id => $name) {
            \App\Models\Auth\Role::find($id) ?? \App\Models\Auth\Role::forceCreate(['id' => $id, 'name' => $name, 'access_types' => '[]']);
        }
        $member = function (int $roleId) {
            $user = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
            \App\Models\Auth\OrganizationUserRole::create(['organization_id' => $this->organization->id, 'user_id' => $user->id, 'role_id' => $roleId, 'status' => 'active', 'accepted_at' => now()]);

            return $user;
        };
        $this->program->update(['organization_id' => $this->organization->id]);
        $this->round->update(['review_period_end' => now()->subDay()]);
        $order = \App\Models\ReviewerOrder::create(['organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'program_id' => $this->program->id,
            'order_type' => 'round_review', 'round_id' => $this->round->id, 'fee_amount' => 0, 'work_status' => 'delivered', 'acceptance_status' => 'accepted', 'payment_status' => 'unpaid']);

        $viewer = $member(10003);
        $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/programs/reviewer-orders/{$order->id}/approve")->assertStatus(403);

        $editor = $member(10002);
        $ids = collect($this->actingAs($editor, 'sanctum')->getJson('/api/v1/programs/owner/reviewer-orders')->assertOk()->json('data.items'))->pluck('id');
        expect($ids)->toContain($order->id);
        $this->actingAs($editor, 'sanctum')->postJson("/api/v1/programs/reviewer-orders/{$order->id}/approve")->assertOk();
        expect($order->fresh()->work_status)->toBe('approved');
    });
});

describe('Site visit request in Pending', function () {
    it('opens the visit page for a service provider, and the drawer tab for a Business Coach', function () {
        $provider = User::factory()->create(['user_type_id' => 3]);
        $this->createVerifiedKyc($provider, 'service_provider');
        $linkFor = function (User $officer) {
            $checkpoint = $this->actingAsOrgOwner()->postJson("/api/v1/programs/monitoring/applications/{$this->application->id}/checkpoints", [
                'checkpoint_name' => 'Farm visit', 'type' => 'monitoring', 'respondent' => 'site_visit_officer', 'send' => true,
                'require_site_visit' => true, 'should_notify_applicant' => false,
            ])->assertStatus(201)->json('data.id');
            $this->actingAsOrgOwner()->postJson("/api/v1/programs/monitoring/checkpoints/$checkpoint/site-visit/assign", [
                'reviewer_id' => $officer->id, 'start_date' => now()->addWeek()->toDateString(), 'assign_type' => 'internal',
            ])->assertStatus(201);
            $visit = \App\Models\Programs\Monitoring\MESiteVisit::where('checkpoint_id', $checkpoint)->value('id');
            \Illuminate\Support\Facades\Cache::flush();
            $item = collect($this->actingAs($officer, 'sanctum')->getJson('/api/v1/pending-tasks?fresh=1')->assertOk()->json('data.groups'))
                ->flatMap(fn ($g) => $g['items'])->firstWhere('type', 'review.site_visit');

            return [$item['link'] ?? null, $visit];
        };

        [$link, $visit] = $linkFor($provider);
        expect($link)->toBe('dashboard.serviceProvider.programWork.siteVisitReport::visitId='.$visit);
        [$coachLink] = $linkFor($this->reviewerUser);
        expect($coachLink)->toContain('requestsTab=siteVisit');
    });
});
