<?php

namespace Tests\Feature\Programs;

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;

/**
 * The owner's own tables page, search, filter, sort and export on the server: My Programs (GET programs/programs),
 * the Applications overview (GET programs/applications-overview) and the Program Deal Room
 * (GET programs/owner/awarded-applications). Without list parameters the first two answer exactly as before.
 */
class OwnerProgramListsTest extends ProgramTestCase
{
    private function makePrograms(int $count, array $attributes = []): void
    {
        Program::factory()->count($count)->create($attributes + ['user_id' => $this->orgUser->id]);
    }

    public function test_my_programs_pages_filters_searches_and_exports_on_the_server(): void
    {
        // setUp already made one published single-round program with one application
        $this->makePrograms(20, ['status' => 'draft', 'visible' => 0, 'use_match_score' => false]);
        $this->makePrograms(5, ['status' => 'published', 'visible' => 1, 'program_type' => 'multi_round', 'total_program_amount' => 250000]);
        Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'published', 'program_title' => 'Zawadi Youth Fund']);
        // someone else's programs never show
        Program::factory()->count(3)->create(['user_id' => $this->applicantUser->id, 'status' => 'published']);

        $url = '/api/v1/programs/programs?';
        $page = $this->actingAsOrgOwner()->getJson($url.http_build_query(['page' => 1, 'per_page' => 10]))->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertCount(10, $page->json('programs'));
        $this->assertSame(27, $page->json('data.pagination.total'));
        $this->assertSame(27, $page->json('data.stats.total'));
        $this->assertSame(7, $page->json('data.stats.active'));
        $this->assertSame(1, $page->json('data.stats.applications'));
        $this->assertEquals(['draft' => 20, 'published' => 7], collect($page->json('data.facets.status'))->pluck('count', 'value')->all());
        $this->assertEquals(['single_round' => 22, 'multi_round' => 5], collect($page->json('data.facets.type'))->pluck('count', 'value')->all());

        $this->assertSame(5, $this->actingAsOrgOwner()->getJson($url.http_build_query(['page' => 1, 'per_page' => 10, 'filters' => ['type' => ['multi_round']]]))->json('data.pagination.total'));
        $this->assertCount(20, $this->actingAsOrgOwner()->getJson($url.http_build_query(['export' => 1, 'filters' => ['visibility' => ['hidden']]]))->json('data.items'));
        $this->assertSame(['Zawadi Youth Fund'], collect($this->actingAsOrgOwner()->getJson($url.http_build_query(['page' => 1, 'per_page' => 10, 'search' => 'zawadi youth']))->json('data.items'))->pluck('program_title')->all());

        // export: every match, not one page
        $all = $this->actingAsOrgOwner()->getJson($url.http_build_query(['export' => 1, 'per_page' => 10]))->assertOk();
        $this->assertCount(27, $all->json('data.items'));
        $this->assertNull($all->json('data.pagination'));
        $this->assertFalse($all->json('data.export_truncated'));

        // most applications first
        $sorted = $this->actingAsOrgOwner()->getJson($url.http_build_query(['page' => 1, 'per_page' => 5, 'sort' => '-applications']))->json('data.items');
        $this->assertSame($this->program->id, $sorted[0]['id']);

        // no list parameters: the old full list, without the list extras
        $old = $this->actingAsOrgOwner()->getJson('/api/v1/programs/programs')->assertOk();
        $this->assertCount(27, $old->json('programs'));
        $this->assertArrayNotHasKey('stats', $old->json('data'));
        $this->assertArrayHasKey('liked', $old->json('programs.0'));
    }

    public function test_the_applications_overview_lists_on_the_server_and_keeps_the_old_response(): void
    {
        $this->makePrograms(14, ['status' => 'published']);
        $this->makePrograms(3, ['status' => 'draft', 'program_type' => 'multi_round', 'program_title' => 'Kilimo Growth']);
        Program::factory()->count(2)->create(['user_id' => $this->applicantUser->id]);

        $url = '/api/v1/programs/applications-overview?';
        $page = $this->actingAsOrgOwner()->getJson($url.http_build_query(['page' => 2, 'per_page' => 10]))->assertOk();
        $this->assertCount(8, $page->json('data.items'));
        $this->assertSame(18, $page->json('data.pagination.total'));
        $this->assertSame(18, $page->json('data.stats.programs'));
        $this->assertSame(1, $page->json('data.stats.applications'));
        $this->assertEquals(['none' => 17, 'needs' => 1], collect($page->json('data.facets.review'))->pluck('count', 'value')->all());

        $multi = $this->actingAsOrgOwner()->getJson($url.http_build_query(['export' => 1, 'filters' => ['type' => ['multi']]]))->json('data.items');
        $this->assertCount(3, $multi);
        $this->assertSame([$this->program->id], collect($this->actingAsOrgOwner()->getJson($url.http_build_query(['export' => 1, 'filters' => ['review' => ['needs']]]))->json('data.items'))->pluck('id')->all());
        $this->assertCount(3, $this->actingAsOrgOwner()->getJson($url.http_build_query(['page' => 1, 'per_page' => 10, 'search' => 'kilimo']))->json('data.items'));
        $this->assertCount(18, $this->actingAsOrgOwner()->getJson($url.http_build_query(['export' => 1, 'per_page' => 5]))->json('data.items'));

        // the old response: every program and the totals, no envelope
        $old = $this->actingAsOrgOwner()->getJson('/api/v1/programs/applications-overview')->assertOk();
        $this->assertCount(18, $old->json('programs'));
        $this->assertSame(18, $old->json('totals.programs'));
        $this->assertNull($old->json('data'));

        $this->actingAsApplicant()->getJson($url.'page=1')->assertForbidden();
    }

    public function test_the_owner_deal_room_lists_every_awardee_on_the_server(): void
    {
        $second = Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'published', 'program_type' => 'multi_round', 'program_title' => 'Second Program']);
        $award = fn (Program $p, int $n, array $more = []) => ProgramApplication::factory()->count($n)->create($more + [
            'program_id' => $p->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'awarded',
        ]);
        $award($this->program, 18, ['funding_setup_status' => 'completed', 'sector' => 'Agriculture']);
        $award($second, 6, ['funding_setup_status' => 'not_started', 'sector' => 'Not specified']);
        $award($second, 1, ['startup_name' => 'Zawadi Hardware', 'sector' => 'Agriculture', 'funding_setup_status' => 'revision_requested']);
        // not awarded, and another owner's awardee, are left out
        $award($this->program, 2, ['status' => 'pending']);
        $elsewhere = Program::factory()->create(['user_id' => $this->applicantUser->id, 'status' => 'published']);
        $award($elsewhere, 2);

        $first = ProgramApplication::where('program_id', $this->program->id)->where('status', 'awarded')->first();
        ProgramMilestone::factory()->create(['app_id' => $first->id, 'status' => 'completed', 'sequence_order' => 1]);
        ProgramMilestone::factory()->create(['app_id' => $first->id, 'status' => 'submitted', 'sequence_order' => 2]);

        $url = '/api/v1/programs/owner/awarded-applications?';
        $page = $this->actingAsOrgOwner()->getJson($url.http_build_query(['page' => 1, 'per_page' => 10]))->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(25, $page->json('data.pagination.total'));
        $this->assertSame(25, $page->json('data.stats.total'));
        $this->assertSame(2, $page->json('data.stats.programs'));
        $this->assertSame(2, $page->json('data.stats.milestones'));
        $this->assertSame(1, $page->json('data.stats.milestones_to_review'));
        $this->assertSame(['not_started' => 6, 'in_progress' => 0, 'revision_requested' => 1, 'awaiting_applicant_revision' => 0, 'completed' => 18], $page->json('data.stats.setup'));
        $this->assertEquals(['single' => 18, 'multi' => 7], collect($page->json('data.facets.type'))->pluck('count', 'value')->all());
        $this->assertEquals(['Agriculture' => 19], collect($page->json('data.facets.sector'))->pluck('count', 'value')->all());
        $this->assertSame('Second Program', collect($page->json('data.facets.program'))->firstWhere('value', $second->id)['label']);

        $this->assertCount(6, $this->actingAsOrgOwner()->getJson($url.http_build_query(['export' => 1, 'filters' => ['setup' => ['not_started']]]))->json('data.items'));
        $this->assertCount(7, $this->actingAsOrgOwner()->getJson($url.http_build_query(['export' => 1, 'filters' => ['program' => [$second->id]]]))->json('data.items'));
        $this->assertSame(['Zawadi Hardware'], collect($this->actingAsOrgOwner()->getJson($url.http_build_query(['page' => 1, 'per_page' => 10, 'search' => 'zawadi']))->json('data.items'))->pluck('startup_name')->all());

        $all = $this->actingAsOrgOwner()->getJson($url.http_build_query(['export' => 1, 'per_page' => 10]))->assertOk();
        $this->assertCount(25, $all->json('data.items'));
        $row = collect($all->json('data.items'))->firstWhere('id', $first->id);
        $this->assertSame([2, 1, 1], [$row['milestone_count'], $row['milestones_done'], $row['milestones_to_review']]);

        $top = $this->actingAsOrgOwner()->getJson($url.http_build_query(['page' => 1, 'per_page' => 1, 'sort' => '-milestones']))->json('data.items.0.id');
        $this->assertSame($first->id, $top);

        // only program accounts; another account sees none of these
        $this->actingAsApplicant()->getJson($url.'page=1')->assertForbidden();
        $other = User::factory()->create(['user_type_id' => 4]);
        $this->assertSame(0, $this->actingAs($other, 'sanctum')->getJson($url.'page=1')->assertOk()->json('data.pagination.total'));
    }

    /** A program-team member of the owner's organization (ProgramAccess::owns) sees its awardees; an outsider does not. */
    public function test_the_deal_room_lists_cover_the_organization_team(): void
    {
        ProgramApplication::factory()->count(3)->create([
            'program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'awarded',
        ]);
        $teamMember = function (string $role, ?int $organizationId = null) {
            $person = User::factory()->create(['user_type_id' => 4, 'organization_id' => $organizationId ?? $this->organization->id]);
            OrganizationUserRole::create([
                'organization_id' => $organizationId ?? $this->organization->id, 'user_id' => $person->id,
                'role_id' => Role::firstOrCreate(['name' => $role], ['access_types' => []])->id, 'status' => 'active', 'accepted_at' => now(),
            ]);

            return $person;
        };
        $viewer = $teamMember('viewer');
        $editor = $teamMember('editor');
        $outsiderOwner = User::factory()->create(['user_type_id' => 4]);
        $outsiderOrg = \App\Models\Organizations\Organization::factory()->create(['owner_user_id' => $outsiderOwner->id]);
        $outsider = $teamMember('editor', $outsiderOrg->id);

        $list = '/api/v1/programs/owner/awarded-applications?page=1&per_page=10';
        $perProgram = "/api/v1/programs/{$this->program->id}/applications/awarded";
        foreach ([$viewer, $editor] as $member) {
            $page = $this->actingAs($member, 'sanctum')->getJson($list)->assertOk();
            $this->assertSame(3, $page->json('data.pagination.total'));
            $this->assertSame(3, $page->json('data.stats.total'));
            $this->assertSame($this->program->id, $page->json('data.facets.program.0.value'));
            $this->assertCount(3, $this->actingAs($member, 'sanctum')->getJson($perProgram)->assertOk()->json('applications'));
        }

        // another organization's team member, and a Business Coach of this organization, see none of it
        $this->assertSame(0, $this->actingAs($outsider, 'sanctum')->getJson($list)->assertOk()->json('data.pagination.total'));
        $this->actingAs($outsider, 'sanctum')->getJson($perProgram)->assertForbidden();
        $coach = User::factory()->create(['user_type_id' => 6, 'organization_id' => $this->organization->id]);
        $this->actingAs($coach, 'sanctum')->getJson($perProgram)->assertForbidden();
    }

    public function test_my_programs_describe_their_filters_and_apply_each_kind(): void
    {
        // setUp: one published single-round program (USD, 100000, a funded wallet) with one application
        $this->makePrograms(2, ['status' => 'draft', 'visible' => 0, 'program_type' => 'multi_round', 'total_rounds' => 3, 'total_program_amount' => 500000,
            'currency' => 'KES', 'require_kyc_verification_to_apply' => true, 'application_deadline' => now()->subDays(5)->toDateString()]);
        $url = '/api/v1/programs/programs?';
        $get = fn (array $q) => $this->actingAsOrgOwner()->getJson($url.http_build_query($q))->assertOk();

        $schema = collect($get(['page' => 1, 'per_page' => 10])->json('data.filter_schema'))->keyBy('key');
        $this->assertSame(['status', 'type', 'visibility', 'match', 'currency', 'amount', 'applications', 'rounds', 'opens', 'deadline', 'created', 'has_applications', 'kyc_required', 'funded'], $schema->keys()->all());
        $this->assertSame([['value' => 'single_round', 'count' => 1, 'label' => 'app:singleRound'], ['value' => 'multi_round', 'count' => 2, 'label' => 'app:multiRound']], $schema['type']['options']);
        $this->assertEquals(['KES' => 2, 'USD' => 1], collect($schema['currency']['options'])->pluck('count', 'value')->all());
        $this->assertSame([100000, 500000], [$schema['amount']['min'], $schema['amount']['max']]);
        $this->assertSame([0, 1], [$schema['applications']['min'], $schema['applications']['max']]);
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 2]], $schema['funded']['options']);
        $this->assertContains('next7Days', $schema['deadline']['presets']);

        $ids = fn (array $filters) => collect($get(['export' => 1, 'filters' => $filters])->json('data.items'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$this->program->id], $ids(['applications' => '1~']));
        $this->assertCount(2, $ids(['amount' => '200000~', 'currency' => ['KES']]));
        $this->assertCount(2, $ids(['rounds' => '2~3']));
        $this->assertSame([$this->program->id], $ids(['has_applications' => 'yes']));
        $this->assertCount(2, $ids(['kyc_required' => 'yes']));
        $this->assertSame([$this->program->id], $ids(['funded' => 'yes']));
        $this->assertCount(2, $ids(['deadline' => '~'.now()->subDay()->toDateString()]));
        $this->assertSame([$this->program->id], $ids(['visibility' => ['visible'], 'match' => ['on']]));
        // counts follow the other filters
        $type = collect($get(['page' => 1, 'per_page' => 10, 'filters' => ['funded' => 'no']])->json('data.filter_schema'))->firstWhere('key', 'type');
        $this->assertEquals(['single_round' => 0, 'multi_round' => 2], collect($type['options'])->pluck('count', 'value')->all());
    }

    public function test_the_applications_overview_describes_its_filters_and_applies_each_kind(): void
    {
        // setUp: one published program whose one application waits for a decision
        $quiet = Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'published', 'program_type' => 'multi_round', 'total_rounds' => 2,
            'total_program_amount' => 900000, 'visible' => 0, 'application_deadline' => now()->addDays(3)->toDateString()]);
        ProgramApplication::factory()->create(['program_id' => $quiet->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'draft']);
        ProgramApplication::factory()->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'approved', 'match_score' => 80]);
        $this->program->update(['application_deadline' => now()->subDays(2)->toDateString()]);

        $url = '/api/v1/programs/applications-overview?';
        $get = fn (array $q) => $this->actingAsOrgOwner()->getJson($url.http_build_query($q))->assertOk();
        $data = $get(['page' => 1, 'per_page' => 10])->json('data');
        $schema = collect($data['filter_schema'])->keyBy('key');
        $this->assertSame(['review', 'status', 'type', 'method', 'ai', 'match', 'currency', 'applications', 'to_review', 'score', 'funding', 'rounds', 'deadline', 'last_applied', 'has_applications', 'has_drafts', 'visible'], $schema->keys()->all());
        $this->assertSame(['value' => 'needs', 'count' => 1, 'label' => 'app:needsReview'], $schema['review']['options'][0]);
        $this->assertSame([0, 2], [$schema['applications']['min'], $schema['applications']['max']]);
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 1]], $schema['has_drafts']['options']);
        $this->assertArrayHasKey('review', $data['facets']); // the old facets are still sent

        $ids = fn (array $filters) => collect($get(['export' => 1, 'filters' => $filters])->json('data.items'))->pluck('id')->all();
        $this->assertSame([$this->program->id], $ids(['applications' => '2~']));
        $this->assertSame([$quiet->id], $ids(['funding' => '500000~']));
        $this->assertSame([$this->program->id], $ids(['score' => '70~90']));
        $this->assertSame([$quiet->id], $ids(['has_applications' => 'no']));
        $this->assertSame([$quiet->id], $ids(['has_drafts' => 'yes', 'visible' => 'no']));
        $this->assertSame([$quiet->id], $ids(['deadline' => now()->toDateString().'~'.now()->addDays(7)->toDateString()]));
        $this->assertSame([$this->program->id], $ids(['last_applied' => now()->subDay()->toDateString().'~']));
        $this->assertSame([$quiet->id], $ids(['type' => ['multi'], 'rounds' => '2~']));
    }

    public function test_the_deal_room_describes_its_filters_and_applies_each_kind(): void
    {
        $second = Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'published', 'program_type' => 'multi_round', 'program_title' => 'Second Program']);
        $award = fn (Program $p, array $more = []) => ProgramApplication::factory()->create($more + [
            'program_id' => $p->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'awarded',
        ]);
        $big = $award($this->program, ['awarded_amount' => 50000, 'total_amount_requested' => 60000, 'headquarters_location' => 'Westlands, Nairobi, Kenya']);
        $small = $award($second, ['awarded_amount' => 5000, 'total_amount_requested' => 8000, 'funding_setup_status' => 'completed']);
        $unverified = User::factory()->create(['user_type_id' => 1]);
        $plain = $award($second, ['user_id' => $unverified->id, 'awarded_amount' => 10000]);
        ProgramMilestone::factory()->create(['app_id' => $big->id, 'status' => 'submitted', 'sequence_order' => 1]);
        ProgramMilestone::factory()->create(['app_id' => $big->id, 'status' => 'completed', 'sequence_order' => 2]);
        ProgramMilestone::factory()->create(['app_id' => $small->id, 'status' => 'completed', 'sequence_order' => 1]);

        // the second program has an M&E plan: a checkpoint for $small only, so $plain is in an M&E program without checkpoints
        \App\Models\Programs\Monitoring\MECheckpoint::create([
            'app_id' => $small->id, 'program_id' => $second->id, 'checkpoint_name' => 'Quarter 1 report', 'type' => 'monitoring', 'status' => 'pending', 'display_order' => 0,
        ]);
        $url = '/api/v1/programs/owner/awarded-applications?';
        $get = fn (array $q) => $this->actingAsOrgOwner()->getJson($url.http_build_query($q))->assertOk();
        $data = $get(['page' => 1, 'per_page' => 10])->json('data');
        $schema = collect($data['filter_schema'])->keyBy('key');
        $this->assertSame(['program', 'setup', 'type', 'sector', 'location', 'awarded', 'requested', 'milestones', 'to_review', 'has_milestones', 'me_program', 'has_checkpoints', 'kyc', 'applied'], $schema->keys()->all());
        $this->assertSame('Second Program', collect($schema['program']['options'])->firstWhere('value', (string) $second->id)['label']);
        $this->assertTrue($schema['program']['searchable']);
        $this->assertEquals(['not_started' => 2, 'in_progress' => 0, 'revision_requested' => 0, 'awaiting_applicant_revision' => 0, 'completed' => 1], collect($schema['setup']['options'])->pluck('count', 'value')->all());
        $this->assertSame(1, collect($schema['location']['options'])->firstWhere('value', 'Nairobi, Kenya')['count']);
        $this->assertSame([5000, 50000], [$schema['awarded']['min'], $schema['awarded']['max']]);
        $this->assertSame([0, 2], [$schema['milestones']['min'], $schema['milestones']['max']]);
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 2]], $schema['to_review']['options']);
        $this->assertSame([['value' => 'yes', 'count' => 2], ['value' => 'no', 'count' => 1]], $schema['kyc']['options']);
        // the old facets keep their shape
        $this->assertEquals(['single' => 1, 'multi' => 2], collect($data['facets']['type'])->pluck('count', 'value')->all());
        $this->assertSame($second->id, collect($data['facets']['program'])->firstWhere('label', 'Second Program')['value']);

        $ids = fn (array $filters) => collect($get(['export' => 1, 'filters' => $filters])->json('data.items'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$big->id, $plain->id], $ids(['awarded' => '10000~']));
        $this->assertSame([$small->id], $ids(['requested' => '~9000', 'type' => ['multi']]));
        $this->assertSame([$big->id], $ids(['milestones' => '2~']));
        $this->assertSame([$big->id], $ids(['to_review' => 'yes']));
        $this->assertSame([$plain->id], $ids(['has_milestones' => 'no']));
        $this->assertSame([$plain->id], $ids(['kyc' => 'no']));
        $this->assertSame([$small->id, $plain->id], $ids(['me_program' => 'yes']));
        $this->assertSame([$big->id], $ids(['me_program' => 'no']));
        $this->assertSame([$small->id], $ids(['has_checkpoints' => 'yes']));
        $this->assertSame([$plain->id], $ids(['me_program' => 'yes', 'has_checkpoints' => 'no']));
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 2]], $schema['has_checkpoints']['options']);
        $this->assertSame([$big->id], $ids(['location' => ['Nairobi, Kenya']]));
        $this->assertSame([$small->id], $ids(['setup' => ['completed']]));
        $this->assertCount(3, $ids(['applied' => now()->subDay()->toDateString().'~']));
        $this->assertSame([], $ids(['applied' => '~'.now()->subDays(2)->toDateString()]));
        // counts follow the other filters
        $kyc = collect($get(['page' => 1, 'per_page' => 10, 'filters' => ['program' => [$this->program->id]]])->json('data.filter_schema'))->firstWhere('key', 'kyc');
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 0]], $kyc['options']);
    }
}
