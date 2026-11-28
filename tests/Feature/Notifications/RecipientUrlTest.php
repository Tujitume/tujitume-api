<?php

use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Auth\OrganizationUserRole;
use App\Models\Organizations\Workspace;
use App\Service\Business\Milestone\MilestoneNotificationService;
use App\Service\Notification\EmailLink;
use App\Service\Notification\RecipientUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.app_url' => 'https://tujitume.com/',
        'app.workspace_url_pattern' => 'https://{slug}.tujitume.com',
        'app.workspace_link_statuses' => ['active', 'pending_verification'],
    ]);
});

/** An organisation owner (and their organisation) whose workspace has the given name and status. */
function workspaceOwner(string $slug = 'trace', string $status = 'active'): User
{
    $owner = User::factory()->create(['user_type_id' => 4]);
    $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
    $owner->update(['organization_id' => $organization->id]);
    Workspace::factory()->create(['organization_id' => $organization->id, 'slug' => $slug, 'subdomain' => $slug, 'workspace_status' => $status]);

    return $owner->refresh();
}

/** HTML of every email sent so far, through the array mailer the tests run with. */
function sentEmails(): array
{
    return collect(app('mailer')->getSymfonyTransport()->messages())
        ->map(fn ($sent) => [
            'to'   => collect($sent->getOriginalMessage()->getTo())->map->getAddress()->all(),
            'html' => (string) $sent->getOriginalMessage()->getHtmlBody(),
        ])->all();
}

function emailTo(string $address): string
{
    $email = collect(sentEmails())->first(fn ($e) => in_array($address, $e['to'], true));
    expect($email)->not->toBeNull();

    return $email['html'];
}

it('sends an organisation member to their workspace address', function () {
    $owner = workspaceOwner('trace');

    expect(RecipientUrl::baseFor($owner))->toBe('https://trace.tujitume.com')
        ->and(EmailLink::url('dashboard.programOrg.programsDiscover', $owner))
        ->toBe('https://trace.tujitume.com/go?to=dashboard.programOrg.programsDiscover')
        // by id or email address too, as most mail code only has the address
        ->and(RecipientUrl::url('dashboard/milestones', $owner->email))->toBe('https://trace.tujitume.com/dashboard/milestones')
        ->and(RecipientUrl::url('dashboard', $owner->id))->toBe('https://trace.tujitume.com/dashboard');
});

it('sends a team member of that organisation to the same workspace', function () {
    $owner = workspaceOwner('trace');
    $member = User::factory()->create(['user_type_id' => 4, 'organization_id' => $owner->organization_id]);

    expect(RecipientUrl::baseFor($member))->toBe('https://trace.tujitume.com');
});

it('keeps entrepreneurs, investors, service providers, external Business Coaches and guests on the main site', function () {
    $owner = workspaceOwner('trace');

    foreach ([1, 2, 3] as $type) {
        $person = User::factory()->create(['user_type_id' => $type]);
        expect(EmailLink::url('milestones', $person))->toBe('https://tujitume.com/go?to=milestones');
    }

    // An external Business Coach carries the organisation's id but is not one of its own people
    $coach = User::factory()->create(['user_type_id' => 6, 'organization_id' => $owner->organization_id]);
    expect(RecipientUrl::baseFor($coach))->toBe('https://tujitume.com')
        ->and(RecipientUrl::baseFor('guest@example.com'))->toBe('https://tujitume.com')
        ->and(RecipientUrl::baseFor(null))->toBe('https://tujitume.com')
        ->and(EmailLink::url('milestones'))->toBe('https://tujitume.com/go?to=milestones');
});

it('links a workspace awaiting verification (its sign-in page already works) but not a suspended one', function () {
    expect(RecipientUrl::baseFor(workspaceOwner('trace', 'pending_verification')))->toBe('https://trace.tujitume.com');

    $suspended = workspaceOwner('halted', 'suspended');
    expect(RecipientUrl::baseFor($suspended))->toBe('https://tujitume.com')
        ->and(EmailLink::url('milestones', $suspended))->toBe('https://tujitume.com/go?to=milestones');
});

it('uses the statuses and the address pattern from the configuration', function () {
    $owner = workspaceOwner('trace', 'pending_verification');

    config(['app.workspace_link_statuses' => ['active'], 'app.workspace_url_pattern' => 'http://{slug}.localhost:81']);
    expect(RecipientUrl::baseFor($owner))->toBe('https://tujitume.com');

    config(['app.workspace_link_statuses' => ['active', 'pending_verification']]);
    expect(RecipientUrl::baseFor($owner))->toBe('http://trace.localhost:81');

    // No pattern: workspace links are off
    config(['app.workspace_url_pattern' => '']);
    expect(RecipientUrl::baseFor($owner))->toBe('https://tujitume.com');
});

it('never turns a reserved or invalid name into an address', function () {
    foreach (['api', 'www', 'admin', 'tujitume'] as $reserved) {
        $owner = workspaceOwner($reserved);
        expect(RecipientUrl::baseFor($owner))->toBe('https://tujitume.com')
            ->and(RecipientUrl::forSlug($reserved))->toBeNull();
    }

    expect(RecipientUrl::forSlug('evil.com/x'))->toBeNull()
        ->and(RecipientUrl::forSlug('a b'))->toBeNull()
        ->and(RecipientUrl::forSlug(''))->toBeNull()
        ->and(RecipientUrl::forSlug('Trace'))->toBe('https://trace.tujitume.com');
});

it('moves only the app\'s own links onto the recipient\'s address', function () {
    $owner = workspaceOwner('trace');
    $investor = User::factory()->create(['user_type_id' => 2]);

    expect(RecipientUrl::rebase('https://tujitume.com/dashboard/milestones?x=1', $owner))->toBe('https://trace.tujitume.com/dashboard/milestones?x=1')
        // the same email data reused for the next person goes back to the main site for them
        ->and(RecipientUrl::rebase('https://trace.tujitume.com/dashboard/milestones', $investor))->toBe('https://tujitume.com/dashboard/milestones')
        ->and(RecipientUrl::rebase('https://api.tujitume.com/api/v1/file', $owner))->toBe('https://api.tujitume.com/api/v1/file')
        ->and(RecipientUrl::rebase('https://checkout.stripe.com/pay', $owner))->toBe('https://checkout.stripe.com/pay')
        ->and(RecipientUrl::rebase('https://tujitume.com.evil.test/x', $owner))->toBe('https://tujitume.com.evil.test/x');
});

it('puts the workspace address on a milestone email to an organisation member, the main site for an entrepreneur', function () {
    $owner = workspaceOwner('trace');
    $entrepreneur = User::factory()->create(['user_type_id' => 1]);
    $milestone = (object) ['title' => 'First harvest', 'investors' => collect()];

    $service = new MilestoneNotificationService();
    $service->notify($owner, 'rmep_approved', $milestone);
    $service->notify($entrepreneur, 'rmep_approved', $milestone);

    expect(emailTo($owner->email))->toContain('href="https://trace.tujitume.com/dashboard/milestones"')
        ->and(emailTo($entrepreneur->email))->toContain('href="https://tujitume.com/dashboard/milestones"')
        ->not->toContain('beta.tujitume.com');
});

it('gives every email template the recipient\'s address, in any code path that sends mail', function () {
    $owner = workspaceOwner('trace');
    $investor = User::factory()->create(['user_type_id' => 2]);

    foreach ([$owner, $investor] as $person) {
        Mail::send('bids.verify_request', ['investor' => 'Jane', 'business_name' => 'Shamba'], fn ($m) => $m->to($person->email)->subject('Verify'));
    }

    expect(emailTo($owner->email))->toContain('href="https://trace.tujitume.com/dashboard"')
        ->and(emailTo($investor->email))->toContain('href="https://tujitume.com/dashboard"');
});

it('sends a team-member invitation to the workspace and an external Business Coach invitation to the main site', function () {
    $owner = workspaceOwner('trace');
    $role = Role::find(10002) ?? Role::forceCreate(['id' => 10002, 'name' => 'editor', 'access_types' => '[]']);
    $superAdmin = Role::find(10001) ?? Role::forceCreate(['id' => 10001, 'name' => 'super_admin', 'access_types' => '[]']);
    OrganizationUserRole::create(['organization_id' => $owner->organization_id, 'user_id' => $owner->id, 'role_id' => $superAdmin->id, 'status' => 'active']);

    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/register/org-team-member', [
        'user_type_id' => 4, 'organization_id' => $owner->organization_id, 'role_id' => $role->id,
        'email' => 'member@trace.test', 'first_name' => 'Amina', 'last_name' => 'Otieno',
    ])->assertCreated();

    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/register/external-reviewer', [
        'user_type_id' => 6, 'organization_id' => $owner->organization_id,
        'email' => 'coach@elsewhere.test', 'first_name' => 'Brian', 'last_name' => 'Mwangi',
    ])->assertCreated();

    expect(emailTo('member@trace.test'))->toContain('https://trace.tujitume.com/organization-invitations/accept?token=')
        ->and(emailTo('coach@elsewhere.test'))->toContain('https://tujitume.com/organization-invitations/accept?token=')
        ->not->toContain('trace.tujitume.com/organization-invitations');
    // The app calls them Business Coaches, never reviewers
    expect(emailTo('coach@elsewhere.test'))->toContain('as a Business Coach')->not->toContain('External Reviewer');
});

it('tells the signed-in member which address their links open on', function () {
    $owner = workspaceOwner('trace');
    $entrepreneur = User::factory()->create(['user_type_id' => 1]);

    $ownerPayload = (new \App\Http\Resources\User\UserResource($owner))->toArray(request()->setUserResolver(fn () => $owner));
    $entrepreneurPayload = (new \App\Http\Resources\User\UserResource($entrepreneur))->toArray(request()->setUserResolver(fn () => $entrepreneur));

    expect($ownerPayload['workspace_url'])->toBe('https://trace.tujitume.com')
        ->and($entrepreneurPayload['workspace_url'])->toBeNull();
});
