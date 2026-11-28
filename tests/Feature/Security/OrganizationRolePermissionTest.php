<?php

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\User;
use App\Models\Communication\Schedule;
use App\Models\Organizations\Organization;
use App\Service\Organization\OrganizationPermission;
use Illuminate\Support\Facades\DB;

uses(Tests\Feature\Programs\ProgramTestCase::class);

/** A team member of the test organization (10001 super_admin, 10002 editor, 10003 viewer, 10004 internal_reviewer) */
function teamMember($test, int $roleId, string $status = 'active'): User
{
    foreach ([10001 => 'super_admin', 10002 => 'editor', 10003 => 'viewer', 10004 => 'internal_reviewer'] as $id => $name) {
        DB::table('roles')->insertOrIgnore(['id' => $id, 'name' => $name, 'access_types' => '[]']);
    }
    $member = User::factory()->create(['user_type_id' => 4, 'organization_id' => $test->organization->id]);
    OrganizationUserRole::query()->create(['organization_id' => $test->organization->id, 'user_id' => $member->id, 'role_id' => $roleId, 'status' => $status]);

    return $member;
}

/** Refused by the role rules (not by some other check further in) */
function refusedByRole($response): void
{
    $response->assertForbidden()->assertJsonPath('code', 'forbidden');
}

function notRefusedByRole($response): void
{
    expect($response->json('message'))->not->toBe(OrganizationPermission::DENIED);
}

describe('Company roles', function () {
    it('lets a viewer read but never create, edit or delete', function () {
        $this->actingAs(teamMember($this, 10003), 'sanctum');

        notRefusedByRole($this->getJson('/api/v1/programs/programs'));
        refusedByRole($this->postJson('/api/v1/programs/create-program', ['program_title' => 'X']));
        refusedByRole($this->postJson("/api/v1/programs/programs/{$this->program->id}", ['program_title' => 'Renamed']));
        refusedByRole($this->getJson("/api/v1/programs/visibility/{$this->program->id}"));
        refusedByRole($this->deleteJson("/api/v1/programs/rounds/{$this->round->id}"));
        refusedByRole($this->postJson("/api/v1/programs/applications/{$this->application->id}/scores", []));
        refusedByRole($this->postJson('/api/v1/events', ['title' => 'Demo day']));

        expect($this->program->fresh()->program_title)->not->toBe('Renamed');
        expect($this->round->fresh())->not->toBeNull();
    });

    it('lets an editor create and edit but never delete or manage the team', function () {
        $editor = teamMember($this, 10002);
        $viewer = teamMember($this, 10003);
        $this->actingAs($editor, 'sanctum');

        notRefusedByRole($this->postJson('/api/v1/programs/create-program', []));
        notRefusedByRole($this->postJson("/api/v1/programs/programs/{$this->program->id}", []));
        refusedByRole($this->deleteJson("/api/v1/programs/delete-program/{$this->program->id}"));
        refusedByRole($this->deleteJson("/api/v1/programs/rounds/{$this->round->id}"));
        // Unassigning a Business Coach and removing round questions are edits
        notRefusedByRole($this->deleteJson("/api/v1/programs/rounds/{$this->round->id}/applications/{$this->application->id}/assignment"));
        notRefusedByRole($this->deleteJson("/api/v1/programs/rounds/{$this->round->id}/questions", ['ids' => []]));
        refusedByRole($this->postJson('/api/v1/programs/update-profile', []));
        refusedByRole($this->postJson('/api/v1/programs/update-user', ['id' => $viewer->id, 'role_id' => 10001]));
        refusedByRole($this->postJson('/api/v1/programs/update-user', ['id' => $editor->id, 'role_id' => 10001]));
        refusedByRole($this->postJson('/api/v1/programs/delete/role-user', ['id' => $viewer->id]));

        expect($this->program->fresh())->not->toBeNull()->and($this->round->fresh())->not->toBeNull();
        expect($viewer->fresh())->not->toBeNull()->and($editor->organizationRole()->value('role_id'))->toBe(10002);
    });

    it('lets an internal reviewer do review work only', function () {
        $this->actingAs(teamMember($this, 10004), 'sanctum');

        notRefusedByRole($this->postJson("/api/v1/programs/applications/{$this->application->id}/scores", []));
        refusedByRole($this->postJson("/api/v1/programs/programs/{$this->program->id}", ['program_title' => 'Renamed']));
        refusedByRole($this->postJson('/api/v1/programs/create-program', []));
    });

    it('refuses a deactivated member everything', function () {
        $this->actingAs(teamMember($this, 10001, 'revoked'), 'sanctum');

        refusedByRole($this->getJson('/api/v1/programs/programs'));
        refusedByRole($this->postJson("/api/v1/programs/programs/{$this->program->id}", []));
    });

    it('keeps the owner and super admins in charge', function () {
        $this->actingAs($this->orgUser, 'sanctum');
        notRefusedByRole($this->deleteJson("/api/v1/programs/rounds/{$this->round->id}"));

        $admin = teamMember($this, 10001);
        notRefusedByRole($this->actingAs($admin, 'sanctum')->postJson("/api/v1/programs/programs/{$this->program->id}", []));
        expect(OrganizationPermission::roleOf($admin))->toBe('super_admin')
            ->and(OrganizationPermission::roleOf($this->orgUser))->toBe('owner')
            ->and(OrganizationPermission::roleOf($this->applicantUser))->toBeNull();
    });
});

describe('Team management', function () {
    it('lets the owner and super admins change roles, never their own or the owner\'s', function () {
        $admin = teamMember($this, 10001);
        $editor = teamMember($this, 10002);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/programs/update-user', ['id' => $editor->id, 'role_id' => 10003])->assertOk();
        expect($editor->organizationRole()->value('role_id'))->toBe(10003);

        $this->postJson('/api/v1/programs/update-user', ['id' => $admin->id, 'role_id' => 10002])->assertStatus(422);
        $this->postJson('/api/v1/programs/update-user', ['id' => $this->orgUser->id, 'role_id' => 10003])->assertStatus(422);
    });

    it('no longer changes roles through a GET link', function () {
        $viewer = teamMember($this, 10003);
        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/programs/accept-invitation?id={$viewer->id}&role_id=10001");

        expect($viewer->organizationRole()->value('role_id'))->toBe(10003);
    });

    it('never deletes an account from outside the team', function () {
        $member = teamMember($this, 10002);

        // An applicant, and a capital route, cannot delete or take over someone else's account
        $this->actingAs($this->applicantUser, 'sanctum')->postJson('/api/v1/programs/delete/role-user', ['id' => $member->id]);
        $this->postJson('/api/v1/capital/delete/role-user', ['id' => $this->orgUser->id]);
        $this->postJson('/api/v1/capital/update-user', ['id' => $this->orgUser->id, 'first_name' => 'Hacked', 'email' => 'taken@example.com', 'role_id' => 10001]);

        expect(User::find($member->id))->not->toBeNull()->and(User::find($this->orgUser->id))->not->toBeNull();
        expect($this->orgUser->fresh()->email)->not->toBe('taken@example.com')->and($this->orgUser->fresh()->first_name)->not->toBe('Hacked');
    });

    it('removes a team member only when the owner or a super admin asks', function () {
        $member = teamMember($this, 10003);
        $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/delete/role-user', ['id' => $member->id])->assertOk();
        expect(User::find($member->id))->toBeNull();
        $this->postJson('/api/v1/programs/delete/role-user', ['id' => $this->orgUser->id])->assertStatus(422);
        expect(User::find($this->orgUser->id))->not->toBeNull();
    });

    it('keeps the organization when a team member deletes their own account', function () {
        $member = teamMember($this, 10002);
        $method = new ReflectionMethod(\App\Http\Controllers\Account\UserController::class, 'deleteOrganizationDataForUser');
        $method->invoke(app(\App\Http\Controllers\Account\UserController::class), $member);

        expect(Organization::find($this->organization->id))->not->toBeNull()
            ->and(OrganizationUserRole::where('user_id', $member->id)->exists())->toBeFalse();
    });
});

it('deletes only your own availability', function () {
    $theirs = Schedule::query()->create(['user_id' => $this->orgUser->id, 'day' => ['Monday'], 'start_hour' => ['07:00'], 'end_hour' => ['09:00']]);

    $this->actingAs($this->applicantUser, 'sanctum')->postJson("/api/v1/delete-schedule/{$theirs->id}")->assertNotFound();
    expect(Schedule::find($theirs->id))->not->toBeNull();
});

// Crawl 27 Nov: an internal Business Coach's dashboard said "No programs yet" while its cards counted the organization's programs
it('lists the organization\'s programs to an internal Business Coach, as to a viewer', function () {
    $coach = teamMember($this, 10004);
    $ids = collect($this->actingAs($coach, 'sanctum')->getJson('/api/v1/programs/programs?page=1&per_page=25')->assertOk()->json('data.items'))->pluck('id');
    expect($ids)->toContain($this->program->id);
    // ... and still cannot create one
    refusedByRole($this->postJson('/api/v1/programs/create-program', ['program_title' => 'X']));
});
