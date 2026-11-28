<?php

namespace Tests\Feature\Programs;

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Models\Programs\Rounds\RoundReviewer;

/** The Team and Business Coaches pages page, search, filter and export on the server; the old full lists stay the same. */
class OrgPeopleListTest extends ProgramTestCase
{
    private const ROLE_IDS = ['super_admin' => 10001, 'editor' => 10002, 'viewer' => 10003, 'internal_reviewer' => 10004];

    /** The roles as seeded (the Business Coaches endpoint checks the super admin's role id) */
    private function role(string $name): Role
    {
        return Role::find(self::ROLE_IDS[$name]) ?? Role::forceCreate(['id' => self::ROLE_IDS[$name], 'name' => $name, 'access_types' => '[]']);
    }

    private function member(string $role, string $status = 'active', array $user = []): User
    {
        $person = User::factory()->create($user + ['user_type_id' => 4, 'organization_id' => $this->organization->id]);
        OrganizationUserRole::create([
            'organization_id' => $this->organization->id,
            'user_id' => $person->id,
            'role_id' => $this->role($role)->id,
            'status' => $status,
            'accepted_at' => $status === 'active' ? now() : null,
            'invited_at' => now()->subDay(),
        ]);

        return $person;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // The owner is the organization's super admin (what lets them see the team)
        OrganizationUserRole::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->orgUser->id,
            'role_id' => $this->role('super_admin')->id,
            'status' => 'active',
            'accepted_at' => now(),
        ]);
    }

    private function team(array $query = [])
    {
        return $this->actingAsOrgOwner()->getJson('/api/v1/organizations/team-members?'.http_build_query($query));
    }

    public function test_an_internal_business_coach_is_listed_and_filtered_as_one_not_as_a_viewer(): void
    {
        $coach = $this->member('internal_reviewer');
        $this->member('viewer');

        $rows = collect($this->team(['export' => 1])->assertOk()->json('data.items'));
        $this->assertSame('internal-reviewer', $rows->firstWhere('user_id', $coach->id)['role_key'] ?? $rows->firstWhere('id', $coach->id)['role_key'] ?? null);
        $only = $this->team(['export' => 1, 'filters' => ['role' => ['internal-reviewer']]])->assertOk()->json('data.items');
        $this->assertCount(1, $only);
    }

    public function test_the_team_pages_filters_searches_and_exports_on_the_server(): void
    {
        foreach (range(1, 14) as $i) {
            $this->member('editor');
        }
        foreach (range(1, 6) as $i) {
            $this->member('viewer', 'pending');
        }
        $this->member('viewer', 'revoked', ['first_name' => 'Zawadi', 'last_name' => 'Otieno']);
        // another organization's people never show
        $other = User::factory()->create(['user_type_id' => 4]);
        OrganizationUserRole::create(['organization_id' => \App\Models\Organizations\Organization::factory()->create(['owner_user_id' => $other->id])->id, 'user_id' => $other->id, 'role_id' => $this->role('editor')->id, 'status' => 'active']);

        $page = $this->team(['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(22, $page->json('data.pagination.total'));
        $this->assertSame(22, $page->json('data.stats.total'));
        $this->assertSame(15, $page->json('data.stats.active')); // the owner + 14 editors
        $this->assertSame(6, $page->json('data.stats.pending'));
        $this->assertSame(1, $page->json('data.stats.deactivated'));
        $this->assertSame(14, $page->json('data.stats.editors'));
        $this->assertSame(1, $page->json('data.stats.super_admins'));
        $this->assertEquals(['active' => 15, 'pending' => 6, 'deactivated' => 1], collect($page->json('data.facets.status'))->pluck('count', 'value')->all());

        // filters and their counts (the role counts follow the status filter)
        $pending = $this->team(['page' => 1, 'per_page' => 10, 'filters' => ['status' => ['pending']]])->assertOk();
        $this->assertSame(6, $pending->json('data.pagination.total'));
        $this->assertEquals(['viewer' => 6], collect($pending->json('data.facets.role'))->pluck('count', 'value')->all());
        $this->assertSame('editor', $this->team(['export' => 1, 'filters' => ['role' => ['editor']]])->assertOk()->json('data.items.0.role_key'));

        // search
        $found = $this->team(['page' => 1, 'per_page' => 10, 'search' => 'zawadi oti'])->json('data.items');
        $this->assertCount(1, $found);
        $this->assertSame('deactivated', $found[0]['status_key']);

        // export: every match, past the page size
        $all = $this->team(['export' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(22, $all->json('data.items'));
        $this->assertNull($all->json('data.pagination'));
        $this->assertCount(14, $this->team(['export' => 1, 'filters' => ['role' => ['editor']]])->json('data.items'));

        // sorted by name
        $names = collect($this->team(['export' => 1, 'sort' => 'name'])->json('data.items'))->pluck('first_name')->map(fn ($n) => mb_strtolower($n))->all();
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);

        // without list params: the old full list, as before
        $old = $this->team()->assertOk();
        $this->assertCount(22, $old->json('users'));
        $this->assertNull($old->json('data'));
        $this->assertArrayNotHasKey('status_key', $old->json('users.0'));
    }

    public function test_only_a_super_admin_sees_the_team(): void
    {
        $editor = $this->member('editor');
        $this->actingAs($editor, 'sanctum')->getJson('/api/v1/organizations/team-members?page=1&per_page=10')->assertForbidden();
        $this->actingAs($editor, 'sanctum')->getJson('/api/v1/organizations/team-members?export=1')->assertForbidden();
    }

    private function coaches(array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->orgUser, 'sanctum')->getJson("/api/v1/organizations/{$this->organization->id}/reviewers?".http_build_query($query));
    }

    public function test_the_business_coaches_page_on_the_server_and_export_every_match(): void
    {
        // 18 external coaches (accepted: they have a password), 4 still invited, and internal editors
        $external = User::factory()->count(18)->create(['user_type_id' => 6, 'organization_id' => $this->organization->id]);
        User::factory()->count(4)->create(['user_type_id' => 6, 'organization_id' => $this->organization->id, 'password' => null]);
        $this->member('editor', 'active', ['first_name' => 'Imani', 'last_name' => 'Wanjiru']);
        $this->member('editor', 'revoked');
        RoundReviewer::create(['round_id' => $this->round->id, 'user_id' => $external[0]->id]);
        ReviewerApplicationAssignment::create(['round_id' => $this->round->id, 'application_id' => $this->application->id, 'reviewer_id' => $external[0]->id, 'status' => 'assigned']);

        // the owner (a super admin) is listed too: 18 + 4 + 2 + owner
        $page = $this->coaches(['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(25, $page->json('data.pagination.total'));
        $this->assertSame(25, $page->json('data.stats.total'));
        $this->assertSame(4, $page->json('data.stats.pending'));
        $this->assertSame(1, $page->json('data.stats.deactivated'));
        $this->assertSame(1, $page->json('data.stats.assigned'));
        $this->assertEquals(['external' => 22, 'internal' => 3], collect($page->json('data.facets.type'))->pluck('count', 'value')->all());

        $this->assertSame(22, $this->coaches(['page' => 1, 'per_page' => 5, 'filters' => ['type' => ['external']]])->json('data.pagination.total'));
        $this->assertCount(1, $this->coaches(['export' => 1, 'filters' => ['work' => ['pending']]])->json('data.items'));
        $this->assertCount(4, $this->coaches(['export' => 1, 'filters' => ['status' => ['pending']]])->json('data.items'));

        $found = $this->coaches(['page' => 1, 'per_page' => 10, 'search' => 'imani wanj'])->json('data.items');
        $this->assertSame(['Imani'], collect($found)->pluck('first_name')->all());
        $this->assertSame('internal', $found[0]['reviewer_type']);

        $all = $this->coaches(['export' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(25, $all->json('data.items'));

        // without list params: the old full list, as before
        $old = $this->coaches()->assertOk();
        $this->assertCount(25, $old->json('reviewers'));
        $this->assertNull($old->json('data'));
    }

    public function test_the_business_coaches_list_keeps_its_access_rule(): void
    {
        $editor = $this->member('editor');
        $this->coaches(['page' => 1, 'per_page' => 10], $editor)->assertForbidden();
        $this->coaches(['export' => 1], $this->applicantUser)->assertForbidden();
    }

    /** Reviewed = the coach's assignment is completed; awaiting = assigned or in review; only this organization's programs count. */
    public function test_the_business_coaches_review_counts_come_from_their_application_assignments(): void
    {
        $coach = User::factory()->create(['user_type_id' => 6, 'organization_id' => $this->organization->id]);
        RoundReviewer::create(['round_id' => $this->round->id, 'user_id' => $coach->id]);
        $apps = ProgramApplication::factory()->count(5)->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'current_round_id' => $this->round->id]);
        foreach (['completed', 'completed', 'assigned', 'in_review', 'unassigned'] as $i => $status) {
            ReviewerApplicationAssignment::create(['round_id' => $this->round->id, 'application_id' => $apps[$i]->id, 'reviewer_id' => $coach->id, 'status' => $status]);
        }
        // the same coach's work for another organization is not counted here
        $elsewhere = ProgramRound::factory()->create(['program_id' => Program::factory()->create(['user_id' => User::factory()->create(['user_type_id' => 4])->id])->id, 'round_number' => 1]);
        ReviewerApplicationAssignment::create(['round_id' => $elsewhere->id, 'application_id' => ProgramApplication::factory()->create(['program_id' => $elsewhere->program_id, 'user_id' => $this->applicantUser->id])->id, 'reviewer_id' => $coach->id, 'status' => 'completed']);

        $page = $this->coaches(['page' => 1, 'per_page' => 50])->assertOk();
        $row = collect($page->json('data.items'))->firstWhere('id', $coach->id);
        $this->assertSame([4, 2, 2, 'pending'], [$row['assigned_count'], $row['reviewed_count'], $row['pending_count'], $row['work_key']]);
        $this->assertSame([4, 2, 2], [$page->json('data.stats.assigned'), $page->json('data.stats.reviewed'), $page->json('data.stats.awaiting')]);
        $this->assertSame([$coach->id], collect($this->coaches(['export' => 1, 'filters' => ['id' => [$coach->id]]])->json('data.items'))->pluck('id')->all());

        // every assignment finished: done
        ReviewerApplicationAssignment::where('reviewer_id', $coach->id)->update(['status' => 'completed']);
        $row = collect($this->coaches(['export' => 1])->json('data.items'))->firstWhere('id', $coach->id);
        $this->assertSame([5, 5, 0, 'done'], [$row['assigned_count'], $row['reviewed_count'], $row['pending_count'], $row['work_key']]);
    }

    /** The coach's own dashboard counts (GET programs/reviewer/assignment-counts). */
    public function test_a_business_coach_sees_their_reviewed_and_awaiting_counts(): void
    {
        $apps = ProgramApplication::factory()->count(4)->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'current_round_id' => $this->round->id]);
        foreach (['completed', 'assigned', 'in_review', 'in_review'] as $i => $status) {
            ReviewerApplicationAssignment::create(['round_id' => $this->round->id, 'application_id' => $apps[$i]->id, 'reviewer_id' => $this->reviewerUser->id, 'status' => $status]);
        }
        RoundReviewer::create(['round_id' => $this->round->id, 'user_id' => $this->reviewerUser->id]);

        $counts = $this->actingAsReviewer()->getJson('/api/v1/programs/reviewer/assignment-counts')->assertOk()->json();
        $this->assertSame(1, $counts['reviewed']);
        $this->assertSame(3, $counts['awaiting']);
        $this->assertSame([1, 2, 1, 3], [$counts['not_started'], $counts['in_review'], $counts['completed'], $counts['to_review']]);
    }

    /** The Team panel is described by the server: role / status options with labels and counts, joined + invited dates, invitation expired. */
    public function test_the_team_filters_are_described_and_each_one_filters(): void
    {
        $this->member('editor');
        $this->member('viewer', 'pending');
        $expired = $this->member('viewer', 'pending');
        OrganizationUserRole::where('user_id', $expired->id)->update(['invitation_expires_at' => now()->subDay(), 'invited_at' => now()->subDays(20)]);
        OrganizationUserRole::where('user_id', $this->orgUser->id)->update(['invitation_expires_at' => now()->subDay()]); // the owner is never "expired"

        $schema = collect($this->team(['page' => 1, 'per_page' => 10])->assertOk()->json('data.filter_schema'))->keyBy('key');
        $this->assertSame(['role', 'status', 'joined', 'invited', 'expired'], $schema->keys()->all());
        $this->assertSame(['options', 'options', 'dateRange', 'dateRange', 'boolean'], $schema->pluck('type')->all());
        $this->assertSame(['value' => 'editor', 'count' => 1, 'label' => 'app:editor'], collect($schema['role']['options'])->firstWhere('value', 'editor'));
        $this->assertSame(['active' => 2, 'pending' => 2, 'deactivated' => 0], collect($schema['status']['options'])->pluck('count', 'value')->all());
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 3]], $schema['expired']['options']);
        $this->assertContains('last30Days', $schema['joined']['presets']);

        $ids = fn (array $filters) => collect($this->team(['export' => 1, 'filters' => $filters])->json('data.items'))->pluck('id')->all();
        $this->assertSame([$expired->id], $ids(['expired' => 'yes']));
        $this->assertCount(3, $ids(['expired' => 'no']));
        $this->assertCount(2, $ids(['status' => ['pending']]));
        $this->assertSame([$expired->id], $ids(['invited' => now()->subDays(30)->toDateString().'~'.now()->subDays(10)->toDateString()]));
        $this->assertCount(0, $ids(['joined' => '~'.now()->subDays(5)->toDateString()]));
        // counts follow the other filters
        $status = collect(collect($this->team(['page' => 1, 'per_page' => 10, 'filters' => ['role' => ['viewer']]])->json('data.filter_schema'))->firstWhere('key', 'status')['options']);
        $this->assertSame(['active' => 0, 'pending' => 2, 'deactivated' => 0], $status->pluck('count', 'value')->all());
    }

    /** The Business Coaches panel: status / type / workload options, assigned / reviewed / rounds ranges, site visits, verified, added. */
    public function test_the_business_coach_filters_are_described_and_each_one_filters(): void
    {
        $busy = User::factory()->create(['user_type_id' => 6, 'organization_id' => $this->organization->id]);
        $idle = User::factory()->create(['user_type_id' => 6, 'organization_id' => $this->organization->id]);
        $this->createVerifiedKyc($busy, 'service_provider');
        RoundReviewer::create(['round_id' => $this->round->id, 'user_id' => $busy->id]);
        $apps = ProgramApplication::factory()->count(3)->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'current_round_id' => $this->round->id]);
        foreach (['completed', 'completed', 'assigned'] as $i => $status) {
            ReviewerApplicationAssignment::create(['round_id' => $this->round->id, 'application_id' => $apps[$i]->id, 'reviewer_id' => $busy->id, 'status' => $status]);
        }

        $schema = collect($this->coaches(['page' => 1, 'per_page' => 10])->assertOk()->json('data.filter_schema'))->keyBy('key');
        $this->assertSame(['status', 'type', 'work', 'applications', 'reviewed', 'rounds', 'site_visits', 'verified', 'added'], $schema->keys()->all());
        $this->assertSame(['options', 'options', 'options', 'numberRange', 'numberRange', 'numberRange', 'boolean', 'boolean', 'dateRange'], $schema->pluck('type')->all());
        $this->assertSame('grant:userManagement.listUi.coaches.typeExternal', collect($schema['type']['options'])->firstWhere('value', 'external')['label']);
        $this->assertSame(['pending' => 1, 'done' => 0, 'none' => 2], collect($schema['work']['options'])->pluck('count', 'value')->all());
        $this->assertSame([0, 3], [$schema['applications']['min'], $schema['applications']['max']]);
        // the owner (verified in setUp) and the busy coach
        $this->assertSame([['value' => 'yes', 'count' => 2], ['value' => 'no', 'count' => 1]], $schema['verified']['options']);
        $this->assertSame([['value' => 'yes', 'count' => 0], ['value' => 'no', 'count' => 3]], $schema['site_visits']['options']);

        $ids = fn (array $filters) => collect($this->coaches(['export' => 1, 'filters' => $filters])->json('data.items'))->pluck('id')->all();
        $this->assertSame([$busy->id], $ids(['applications' => '1~']));
        $this->assertSame([$busy->id], $ids(['reviewed' => '2~2']));
        $this->assertSame([$busy->id], $ids(['rounds' => '1~']));
        $this->assertEqualsCanonicalizing([$this->orgUser->id, $idle->id], $ids(['rounds' => '~0']));
        $this->assertSame([$idle->id], $ids(['verified' => 'no']));
        $this->assertCount(0, $ids(['site_visits' => 'yes']));
        $this->assertCount(3, $ids(['added' => now()->subDay()->toDateString().'~']));
        $this->assertSame([$busy->id], $ids(['work' => ['pending'], 'verified' => 'yes']));
    }
}
