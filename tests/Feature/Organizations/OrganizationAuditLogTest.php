<?php

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\User;
use App\Models\Organizations\OrganizationAuditLog;

uses(Tests\Feature\Programs\ProgramTestCase::class);

/** A team member of the test organization with a role (10001 super_admin, 10002 editor, 10003 viewer) */
function auditMember($test, int $roleId, string $status = 'active'): User
{
    foreach ([10001 => 'super_admin', 10002 => 'editor', 10003 => 'viewer', 10004 => 'internal_reviewer'] as $id => $name) {
        \Illuminate\Support\Facades\DB::table('roles')->insertOrIgnore(['id' => $id, 'name' => $name, 'access_types' => '[]']);
    }
    $member = User::factory()->create(['user_type_id' => 4, 'organization_id' => $test->organization->id, 'first_name' => 'Wanjiru', 'last_name' => 'Kamau']);
    OrganizationUserRole::query()->create(['organization_id' => $test->organization->id, 'user_id' => $member->id, 'role_id' => $roleId, 'status' => $status]);

    return $member;
}

describe('Organization audit log', function () {
    it('records who changed what, with the old and new value', function () {
        $editor = auditMember($this, 10002);
        $this->actingAs($editor, 'sanctum');

        $this->program->update(['program_title' => 'Green Fund 2027', 'total_program_amount' => 9000000]);

        $log = OrganizationAuditLog::query()->where('action', 'updated')->where('subject_type', 'program')->latest('id')->firstOrFail();
        expect($log->organization_id)->toBe($this->organization->id)
            ->and($log->actor_user_id)->toBe($editor->id)
            ->and($log->actor_name)->toBe('Wanjiru Kamau')
            ->and($log->actor_role)->toBe('editor')
            ->and($log->subject_label)->toBe('Green Fund 2027')
            ->and($log->changes['program_title']['new'])->toBe('Green Fund 2027')
            ->and($log->changes)->not->toHaveKey('updated_at');
    });

    it('keeps no change for people outside an organization and never stores secrets', function () {
        $this->actingAs($this->applicantUser, 'sanctum');
        $this->application->update(['startup_name' => 'Renamed by applicant']);
        expect(OrganizationAuditLog::query()->where('subject_type', 'program_application')->count())->toBe(0);

        $this->actingAs($this->orgUser, 'sanctum');
        $this->application->update(['startup_name' => 'Renamed by org', 'escrow_funded_reference' => 'x']);
        $log = OrganizationAuditLog::query()->where('subject_type', 'program_application')->firstOrFail();
        expect($log->actor_role)->toBe('owner')->and($log->changes['startup_name']['old'])->toBe('Renamed by applicant');

        expect(\App\Service\Organization\OrganizationAudit::changes(new class extends \Illuminate\Database\Eloquent\Model {
            protected $attributes = ['password' => 'secret', 'invitation_token_hash' => 'abc', 'name' => 'Ok'];
        }, 'created'))->toMatchArray(['password' => ['old' => null, 'new' => '[hidden]'], 'invitation_token_hash' => ['old' => null, 'new' => '[hidden]']]);
    });

    it('records deletions with the old values', function () {
        $round = \App\Models\Programs\Rounds\ProgramRound::factory()->create(['program_id' => $this->program->id, 'round_number' => 2]);
        $this->actingAs($this->orgUser, 'sanctum');
        $round->delete();

        $log = OrganizationAuditLog::query()->where('action', 'deleted')->where('subject_type', 'program_round')->firstOrFail();
        expect($log->changes['program_id']['old'])->toBe($this->program->id)->and($log->changes['program_id']['new'])->toBeNull();
    });

    it('is readable by the owner and active super admins only', function () {
        $this->actingAs($this->orgUser, 'sanctum');
        $this->program->update(['program_title' => 'Seen in the log']);

        $this->getJson('/api/v1/organizations/audit-logs?page=1&per_page=10')->assertOk()
            ->assertJsonPath('data.items.0.subject_label', 'Seen in the log')
            ->assertJsonPath('data.items.0.actor_role', 'owner');

        $this->actingAs(auditMember($this, 10001), 'sanctum')->getJson('/api/v1/organizations/audit-logs')->assertOk();
        $this->actingAs(auditMember($this, 10002), 'sanctum')->getJson('/api/v1/organizations/audit-logs')->assertForbidden();
        $this->actingAs(auditMember($this, 10003), 'sanctum')->getJson('/api/v1/organizations/audit-logs')->assertForbidden();
        $this->actingAs(auditMember($this, 10001, 'revoked'), 'sanctum')->getJson('/api/v1/organizations/audit-logs')->assertForbidden();
        $this->actingAs($this->applicantUser, 'sanctum')->getJson('/api/v1/organizations/audit-logs')->assertForbidden();
    });

    it('searches, filters and exports on the server', function () {
        $this->actingAs($this->orgUser, 'sanctum');
        $this->program->update(['program_title' => 'Alpha']);
        $this->round->update(['round_name' => 'Beta round']);

        $items = $this->getJson('/api/v1/organizations/audit-logs?export=1&filters[subject][]=program_round')->assertOk()->json('data.items');
        expect(collect($items)->pluck('subject_type')->unique()->all())->toBe(['program_round']);
        expect($this->getJson('/api/v1/organizations/audit-logs?page=1&per_page=10&search=Alpha')->json('data.pagination.total'))->toBe(1);
    });

    it('names the ids in a change and the program and round it belongs to, for people who are not developers', function () {
        $this->actingAs($this->orgUser, 'sanctum');
        $this->application->update(['startup_name' => 'Maji Safi Water Kiosks']);
        $this->round->update(['round_name' => 'Application review']);
        $score = \App\Models\Programs\Rounds\ApplicationScore::query()->create([
            'application_id' => $this->application->id, 'round_id' => $this->round->id, 'reviewer_id' => $this->orgUser->id,
            'source' => 'human', 'total_score' => 86.4, 'criterion_scores' => [], 'scored_at' => now(),
        ]);

        // Saved under what it is about, so search finds it
        $log = OrganizationAuditLog::query()->where('subject_type', 'application_score')->firstOrFail();
        expect($log->subject_label)->toBe('Maji Safi Water Kiosks');

        $item = collect($this->getJson('/api/v1/organizations/audit-logs?search=Maji&filters[subject][]=application_score')->assertOk()->json('data.items'))->first();
        $owner = trim($this->orgUser->first_name . ' ' . $this->orgUser->last_name) ?: $this->orgUser->email;
        expect($item['names']['round_id']['new'])->toBe('Application review')
            ->and($item['names']['application_id']['new'])->toBe('Maji Safi Water Kiosks')
            ->and($item['names']['reviewer_id']['new'])->toBe($owner)
            ->and($item['context']['program'])->toBe($this->program->fresh()->program_title)
            ->and($item['context']['round'])->toBe('Application review');

        // An old row logged as "#id" still gets a title when read
        $log->update(['subject_label' => '#' . $score->id]);
        $item = collect($this->getJson('/api/v1/organizations/audit-logs?filters[subject][]=application_score')->json('data.items'))->first();
        expect($item['title'])->toBe('Maji Safi Water Kiosks');
    });

    it('exports block by block, so a big log never has to fit in one response', function () {
        $this->actingAs($this->orgUser, 'sanctum');
        foreach (range(1, 5) as $n) {
            $this->program->update(['program_title' => "Title {$n}"]);
        }
        $all = $this->getJson('/api/v1/organizations/audit-logs?export=1')->assertOk()->json('data.items');

        $first = $this->getJson('/api/v1/organizations/audit-logs?export=1&page=1')->assertOk();
        expect($first->json('data.pagination.per_page'))->toBe(\App\Support\ListQuery::EXPORT_CHUNK)
            ->and($first->json('data.pagination.total'))->toBe(count($all))
            ->and(count($first->json('data.items')))->toBe(count($all));
    });

    it('records who deleted a program, and each of its rounds', function () {
        $program = \App\Models\Programs\Program::factory()->create(['user_id' => $this->orgUser->id, 'organization_id' => $this->organization->id, 'program_title' => 'Short-lived Fund']);
        \App\Models\Programs\Rounds\ProgramRound::factory()->create(['program_id' => $program->id, 'round_number' => 1]);
        $this->actingAs($this->orgUser, 'sanctum');

        $this->deleteJson("/api/v1/programs/delete-program/{$program->id}")->assertOk();

        $deleted = OrganizationAuditLog::query()->where('action', 'deleted')->get();
        expect($deleted->where('subject_type', 'program')->first()?->subject_label)->toBe('Short-lived Fund')
            ->and($deleted->where('subject_type', 'program')->first()?->actor_user_id)->toBe($this->orgUser->id)
            ->and($deleted->where('subject_type', 'program_round')->count())->toBe(1);

        $items = $this->getJson('/api/v1/organizations/audit-logs?filters[action][]=deleted&filters[subject][]=program')->json('data.items');
        expect($items[0]['subject_label'])->toBe('Short-lived Fund');
    });

    it('leaves out light / dark mode (one person\'s own choice) but keeps branding', function () {
        $this->actingAs($this->orgUser, 'sanctum');
        $setting = \App\Models\Auth\UserSetting::query()->firstOrCreate(['user_id' => $this->orgUser->id]);
        $before = OrganizationAuditLog::query()->where('subject_type', 'user_setting')->count();

        $setting->update(['mode' => $setting->mode === 'dark' ? 'light' : 'dark']);
        expect(OrganizationAuditLog::query()->where('subject_type', 'user_setting')->count())->toBe($before);

        $setting->update(['mode' => $setting->mode === 'dark' ? 'light' : 'dark', 'accent_color' => '#123456']);
        $log = OrganizationAuditLog::query()->where('subject_type', 'user_setting')->latest('id')->firstOrFail();
        expect($log->changes)->toHaveKey('accent_color')->not->toHaveKey('mode');
    });

    it('drops mode from entries logged before', function () {
        $base = ['organization_id' => $this->organization->id, 'actor_user_id' => $this->orgUser->id, 'actor_name' => 'Owner', 'action' => 'updated', 'subject_type' => 'user_setting', 'created_at' => now()];
        $only = OrganizationAuditLog::query()->create($base + ['changes' => ['mode' => ['old' => 'system', 'new' => 'light']]]);
        $mixed = OrganizationAuditLog::query()->create($base + ['changes' => ['mode' => ['old' => 'light', 'new' => 'dark'], 'accent_color' => ['old' => '#000', 'new' => '#fff']]]);
        (include database_path('migrations/2026_11_28_000301_drop_personal_mode_from_audit_logs.php'))->up();

        expect(OrganizationAuditLog::query()->find($only->id))->toBeNull()
            ->and(OrganizationAuditLog::query()->find($mixed->id)->changes)->toBe(['accent_color' => ['old' => '#000', 'new' => '#fff']]);
    });

    it('shows times on the device clock until Settings > Timezone is chosen, then on the chosen one', function () {
        \Illuminate\Support\Carbon::setTestNow('2026-11-26 04:00:00');
        $this->actingAs($this->orgUser, 'sanctum');
        $this->program->update(['program_title' => 'Clock check']);
        \App\Models\Auth\UserSetting::query()->where('user_id', $this->orgUser->id)->delete(); // never opened Settings

        $at = fn () => $this->withHeader('X-Timezone', 'America/New_York')->getJson('/api/v1/organizations/audit-logs?search=Clock')->json('data.items.0.created_at');
        expect($at())->toMatch('#^25/11/2026 23:00(:00)?$#'); // 04:00 UTC is 23:00 the evening before in New York

        // Opening Settings creates the row with the device's zone (not UTC)
        $this->withHeader('X-Timezone', 'America/New_York')->getJson('/api/v1/user/settings')->assertOk();
        expect(\App\Models\Auth\UserSetting::query()->where('user_id', $this->orgUser->id)->value('timezone'))->toBe('America/New_York');

        \App\Models\Auth\UserSetting::query()->where('user_id', $this->orgUser->id)->update(['timezone' => 'Africa/Nairobi']);
        expect($at())->toMatch('#^26/11/2026 07:00(:00)?$#'); // the chosen zone wins over the device
        \Illuminate\Support\Carbon::setTestNow();
    });
});
