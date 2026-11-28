<?php

namespace Tests\Feature\DemoRequests;

use App\Models\Admin;
use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\User;
use App\Models\Business\BusinessSubscriptions;
use App\Models\DemoRequest;
use App\Models\Organizations\Organization;
use App\Models\Organizations\Workspace;
use App\Models\ProgramRegistrationInvite;
use App\Models\Programs\Program;
use Database\Seeders\ProgramIndustrySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Landing-page request → admin review → 7-day demo account → "Get full access" (one-time sign-up link) →
 * invite-only program-provider sign-up → first payment (workspace ready, demo deleted); and demos that end.
 */
class DemoRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private int $industryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ProgramIndustrySeeder::class]);
        $this->industryId = (int) \App\Models\ProgramIndustry::where('url', 'sacco-housing')->value('id');
        config(['app.app_url' => 'https://tujitume.test', 'app.workspace_url_pattern' => 'https://{slug}.tujitume.test']);
    }

    private function sentEmails(): array
    {
        return array_map(fn ($sent) => $sent->getOriginalMessage(), app('mailer')->getSymfonyTransport()->messages()->all());
    }

    private function lastEmailTo(string $email): ?\Symfony\Component\Mime\Email
    {
        $found = null;
        foreach ($this->sentEmails() as $message) {
            if (collect($message->getTo())->contains(fn ($a) => strcasecmp($a->getAddress(), $email) === 0)) $found = $message;
        }

        return $found;
    }

    private function tokenIn(?\Symfony\Component\Mime\Email $email, string $param): string
    {
        $this->assertNotNull($email);
        $this->assertSame(1, preg_match('/[?&]'.$param.'=([A-Za-z0-9]{64})/', (string) $email->getHtmlBody(), $m), 'link with '.$param.' in the email');

        return $m[1];
    }

    private function submit(array $overrides = [])
    {
        return $this->postJson('/api/v1/contact/request-demo', $overrides + [
            'name' => 'Jane Wanjiru',
            'org' => 'Impala SACCO',
            'email' => 'jane@impala.test',
            'request_type' => 'demo',
            'program_industry_id' => $this->industryId,
            'notes' => 'Member housing loans',
            'timezone' => 'America/New_York',
        ]);
    }

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Ops', 'email' => 'ops@tujitume.test', 'password' => Hash::make('secret')]);
    }

    private function approvedDemo(): DemoRequest
    {
        $this->submit()->assertCreated();
        $r = DemoRequest::firstOrFail();
        $this->actingAs($this->admin(), 'admin')->post(route('admin.demo_requests.approve', $r))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(session('error'), (string) session('error'));

        return $r->fresh();
    }

    private function registrationPayload(string $token, string $email): array
    {
        return [
            'user_type_id' => 4, 'invite_token' => $token,
            'first_name' => 'Jane', 'last_name' => 'Wanjiru', 'email' => $email, 'password' => 'Secret123',
            'org_name' => 'Impala SACCO', 'org_display_name' => 'Impala', 'org_legal_name' => 'Impala SACCO Ltd',
            'organization_type' => 'cooperative', 'year_established' => 2010, 'org_email' => 'info@impala.test',
            'org_phone' => '+254700000000', 'org_website' => 'https://impala.test', 'description' => 'Housing SACCO',
            'program_industry_id' => $this->industryId, 'focus_sectors' => ['housing'], 'operating_countries' => ['KE'],
            'target_regions' => ['Nairobi'], 'country' => 'KE', 'region' => 'Nairobi', 'city' => 'Nairobi',
            'financial_year_start_month' => 1, 'workspace_slug' => 'impala',
        ];
    }

    public function test_a_request_is_saved_confirmed_by_email_and_a_second_one_is_refused_while_it_is_reviewed(): void
    {
        $this->submit()->assertCreated();

        $r = DemoRequest::firstOrFail();
        $this->assertSame('pending', $r->status);
        $this->assertSame('demo', $r->request_type);
        $this->assertSame($this->industryId, (int) $r->program_industry_id);
        $this->assertSame('America/New_York', $r->timezone);
        $this->assertNotNull($this->lastEmailTo('jane@impala.test'), 'thank-you email to the requester');
        $this->assertNotNull($this->lastEmailTo(config('mail.tujitume.contact_email')), 'notice to the team');

        $this->submit(['email' => 'JANE@impala.test'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'request_already_open')
            ->assertJsonFragment(['message' => 'Your "Book a Demo" request is being reviewed. We will email you at jane@impala.test as soon as it is.']);

        // Another kind of request is fine
        $this->submit(['request_type' => 'proposal'])->assertCreated();
        $this->assertSame(2, DemoRequest::count());
    }

    public function test_approving_a_demo_creates_a_hidden_seven_day_account_whose_link_sets_the_password(): void
    {
        $r = $this->approvedDemo();

        $this->assertSame('trial_active', $r->status);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $r->trial_expires_at->timestamp, 5);

        $user = User::findOrFail($r->trial_user_id);
        $org = Organization::findOrFail($r->trial_organization_id);
        $this->assertSame(4, (int) $user->user_type_id);
        $this->assertNull($user->password);
        $this->assertTrue($org->is_trial);
        $this->assertSame($this->industryId, (int) $org->program_industry_id);
        $this->assertTrue(Workspace::where('organization_id', $org->id)->where('slug', 'like', 'impala-sacco-demo-%')->exists());
        $this->assertSame('America/New_York', $user->settings?->timezone ?? \App\Models\Auth\UserSetting::where('user_id', $user->id)->value('timezone'));
        $this->assertSame(1, Program::where('organization_id', $org->id)->count());
        $this->assertTrue(BusinessSubscriptions::where('investor_id', $user->id)->where('trial', 1)->exists());

        // A demo account's programs never reach applicants, even when published
        $program = Program::where('organization_id', $org->id)->first();
        $program->update(['visible' => true, 'status' => 'published']);
        $this->assertFalse((bool) $program->fresh()->visible);

        // The emailed link opens on the demo workspace and sets the password (one use)
        $email = $this->lastEmailTo('jane@impala.test');
        $this->assertStringContainsString('.tujitume.test/organization-invitations/accept?token=', (string) $email->getHtmlBody());
        $this->assertStringContainsString('(America/New_York)', (string) $email->getHtmlBody());
        $token = $this->tokenIn($email, 'token');

        $this->postJson('/api/v1/organization-invitations/accept', [
            'token' => $token, 'display_name' => 'Jane', 'phone' => '0700000000',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123',
        ])->assertOk();
        $this->assertTrue(Hash::check('Secret123', $user->fresh()->password));
        $this->assertSame('active', OrganizationUserRole::where('user_id', $user->id)->value('status'));

        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/v1/trial/status')
            ->assertOk()->assertJsonPath('data.is_trial', true);
    }

    public function test_a_rejection_needs_a_reason_and_emails_it(): void
    {
        $this->submit()->assertCreated();
        $r = DemoRequest::firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post(route('admin.demo_requests.reject', $r), [])->assertSessionHasErrors('rejection_reason');
        $this->actingAs($admin, 'admin')->post(route('admin.demo_requests.reject', $r), ['rejection_reason' => 'Outside our region for now'])->assertRedirect();

        $this->assertSame('rejected', $r->fresh()->status);
        $this->assertStringContainsString('Outside our region for now', (string) $this->lastEmailTo('jane@impala.test')->getHtmlBody());
        // Rejected is not open: they may ask again
        $this->submit()->assertCreated();
    }

    public function test_a_proposal_is_sent_from_the_edited_template(): void
    {
        $this->submit(['request_type' => 'proposal'])->assertCreated();
        $r = DemoRequest::firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post(route('admin.demo_requests.templates.save', 'proposal'), [
            'subject' => 'Proposal for {org}', 'body' => "Hi {name},\nPlan: Gold for {program_type}.",
        ])->assertRedirect();
        $this->actingAs($admin, 'admin')->get(route('admin.demo_requests.show', $r))
            ->assertOk()->assertSee('Proposal for Impala SACCO')->assertSee('Plan: Gold for SACCO Housing.');

        $this->actingAs($admin, 'admin')->post(route('admin.demo_requests.proposal', $r), [
            'proposal_subject' => 'Proposal for Impala SACCO', 'proposal_body' => "Hi Jane,\nPlan: Gold, USD 29.99 a month.",
        ])->assertRedirect();

        $this->assertSame('proposal_sent', $r->fresh()->status);
        $email = $this->lastEmailTo('jane@impala.test');
        $this->assertSame('Proposal for Impala SACCO', $email->getSubject());
        $this->assertStringContainsString('USD 29.99 a month', (string) $email->getHtmlBody());
    }

    public function test_program_provider_sign_up_needs_a_one_time_invite_for_that_email(): void
    {
        [$invite, $token] = ProgramRegistrationInvite::issue('new@fund.test', 'New Fund');

        $this->postJson('/api/v1/register', $this->registrationPayload('', 'new@fund.test'))->assertStatus(403);
        $this->postJson('/api/v1/register', $this->registrationPayload($token, 'other@fund.test'))->assertStatus(422)->assertJsonValidationErrors('email');

        $this->getJson('/api/v1/program-invites/'.$token)->assertOk()->assertJsonPath('data.email', 'new@fund.test');
        $this->postJson('/api/v1/register', $this->registrationPayload($token, 'new@fund.test'))->assertCreated();

        $this->assertNotNull($invite->fresh()->used_at);
        // Used once: the link no longer works
        $this->getJson('/api/v1/program-invites/'.$token)->assertNotFound();
        $this->postJson('/api/v1/register', $this->registrationPayload($token, 'new@fund.test') + ['workspace_slug' => 'other'])->assertStatus(403);
    }

    public function test_full_access_from_the_demo_then_payment_opens_the_workspace_and_deletes_the_demo(): void
    {
        $r = $this->approvedDemo();
        $trialUser = User::findOrFail($r->trial_user_id);
        $trialOrgId = $r->trial_organization_id;

        $emailsBefore = count($this->sentEmails());
        $url = $this->actingAs($trialUser, 'sanctum')->postJson('/api/v1/trial/request-full-access')->assertOk()->json('data.invite_url');
        $this->assertSame('full_access_requested', $r->fresh()->status);

        // Opened straight away: no email to the requester, only the team is told
        $this->assertStringStartsWith('https://tujitume.test/auth/create/program?invite=', $url);
        $this->assertSame(1, preg_match('/invite=([A-Za-z0-9]{64})/', $url, $m));
        $token = $m[1];
        $newEmails = array_slice($this->sentEmails(), $emailsBefore);
        $this->assertCount(1, $newEmails);
        $this->assertSame('Full access requested: Impala SACCO', $newEmails[0]->getSubject());

        // Same address as the demo account: allowed; the demo account stops being reachable
        $this->app['auth']->forgetGuards();
        $response = $this->postJson('/api/v1/register', $this->registrationPayload($token, 'jane@impala.test'))->assertCreated();
        $newUserId = $response->json('user.id');
        $this->assertSame('registered', $r->fresh()->status);
        // The full account replaces the demo at once: no countdown left, the demo and its data are gone
        $this->assertFalse(Organization::whereKey($trialOrgId)->exists(), 'demo deleted when the full account is created');
        $this->assertFalse(User::whereKey($trialUser->id)->exists());
        $this->assertNotNull($r->fresh()->trial_closed_at);
        $this->assertSame($newUserId, (int) $r->fresh()->converted_user_id);
        $this->assertTrue(User::whereKey($newUserId)->exists(), 'the new account is untouched');

        // First paid subscription
        BusinessSubscriptions::create(['plan' => 'gold', 'investor_id' => $newUserId, 'amount' => 29.99, 'method' => 'lipr',
            'start_date' => now(), 'expire_date' => now()->addDays(30), 'token_remaining' => 30, 'trial' => 0]);

        $r->refresh();
        $this->assertSame('converted', $r->status);
        $this->assertNotNull($r->workspace_ready_sent_at);
        $this->assertSame(0, Program::where('organization_id', $trialOrgId)->count());

        // Later sweeps leave the converted request and the new account alone
        $this->travelTo(now()->addDays(30));
        $this->artisan('demo-trials:sweep')->assertSuccessful();
        $this->assertSame('converted', $r->fresh()->status);
        $this->assertTrue(User::whereKey($newUserId)->exists());

        $ready = $this->lastEmailTo('jane@impala.test');
        $this->assertSame('Your Tujitume workspace is ready', $ready->getSubject());
        $this->assertStringContainsString('https://impala.tujitume.test/login', (string) $ready->getHtmlBody());
        // The app's sign-in page is /login (there is no /auth/login route)
        $this->assertStringNotContainsString('/auth/login', (string) $ready->getHtmlBody());

        // Only once
        BusinessSubscriptions::create(['plan' => 'gold', 'investor_id' => $newUserId, 'amount' => 29.99, 'trial' => 0, 'expire_date' => now()->addDays(60)]);
        $this->assertSame(1, collect($this->sentEmails())->filter(fn ($m) => $m->getSubject() === 'Your Tujitume workspace is ready')->count());
    }

    public function test_the_sweep_reminds_then_deletes_a_demo_at_its_exact_end(): void
    {
        $r = $this->approvedDemo();
        $orgId = $r->trial_organization_id;

        $this->travelTo($r->trial_expires_at->copy()->subHours(47));
        $this->artisan('demo-trials:sweep')->assertSuccessful();
        $this->assertNotNull($r->fresh()->trial_reminder_sent_at);
        $this->assertSame('Your Tujitume demo ends soon', $this->lastEmailTo('jane@impala.test')->getSubject());

        $this->travelTo($r->trial_expires_at->copy()->subMinute());
        $this->artisan('demo-trials:sweep')->assertSuccessful();
        $this->assertTrue(Organization::whereKey($orgId)->exists());

        $this->travelTo($r->trial_expires_at->copy()->addMinute());
        $this->artisan('demo-trials:sweep')->assertSuccessful();
        $this->assertSame('expired', $r->fresh()->status);
        $this->assertFalse(Organization::whereKey($orgId)->exists());
        $this->assertFalse(User::whereKey($r->trial_user_id)->exists());
        $this->assertSame('Your Tujitume demo has ended', $this->lastEmailTo('jane@impala.test')->getSubject());
    }

    public function test_a_demo_with_an_open_full_access_link_is_kept_past_its_end(): void
    {
        $r = $this->approvedDemo();
        $this->actingAs(User::findOrFail($r->trial_user_id), 'sanctum')->postJson('/api/v1/trial/request-full-access')->assertOk();

        $this->travelTo($r->trial_expires_at->copy()->addHour());
        $this->artisan('demo-trials:sweep')->assertSuccessful();
        $this->assertTrue(Organization::whereKey($r->trial_organization_id)->exists());

        $this->travelTo(now()->addDays(ProgramRegistrationInvite::VALID_DAYS));
        $this->artisan('demo-trials:sweep')->assertSuccessful();
        $this->assertSame('expired', $r->fresh()->status);
        $this->assertFalse(Organization::whereKey($r->trial_organization_id)->exists());
    }

    public function test_admin_pages_render(): void
    {
        $r = $this->approvedDemo();
        $admin = Admin::firstOrFail();

        foreach (['pending', 'trials', 'answered', 'converted', 'closed', 'all'] as $tab) {
            $this->actingAs($admin, 'admin')->get(route('admin.demo_requests.index', ['status' => $tab]))->assertOk();
        }
        $this->actingAs($admin, 'admin')->get(route('admin.demo_requests.index', ['status' => 'trials']))->assertSee('Impala SACCO');
        $this->actingAs($admin, 'admin')->get(route('admin.demo_requests.show', $r))->assertOk()->assertSee('Demo account (temporary)');
        $this->actingAs($admin, 'admin')->get(route('admin.demo_requests.templates'))->assertOk();
        $this->actingAs($admin, 'admin')->get(route('admin.demo_requests.invites'))->assertOk();

        $this->actingAs($admin, 'admin')->post(route('admin.demo_requests.extend', $r), ['days' => 3])->assertRedirect();
        $this->assertEqualsWithDelta(now()->addDays(10)->timestamp, $r->fresh()->trial_expires_at->timestamp, 10);

        $this->actingAs($admin, 'admin')->post(route('admin.demo_requests.invites.create'), ['email' => 'cold@lead.test'])->assertRedirect();
        $this->assertTrue(ProgramRegistrationInvite::where('email', 'cold@lead.test')->exists());

        // Guests cannot reach the admin pages
        $this->app['auth']->forgetGuards();
        $this->get(route('admin.demo_requests.index'))->assertRedirect();
    }

    public function test_the_admin_opens_a_demo_account_once_with_a_short_sign_in_that_cannot_request_full_access(): void
    {
        $r = $this->approvedDemo();

        $redirect = $this->actingAs(Admin::firstOrFail(), 'admin')->post(route('admin.demo_requests.open-account', $r))->assertRedirect();
        $this->assertSame(1, preg_match('/auth\/admin-view\?code=([A-Za-z0-9]{64})/', (string) $redirect->headers->get('Location'), $m));
        $this->app['auth']->forgetGuards();

        $token = $this->postJson('/api/v1/auth/admin-view', ['code' => $m[1]])->assertOk()->json('token');
        $this->postJson('/api/v1/auth/admin-view', ['code' => $m[1]])->assertForbidden(); // once

        $sanctum = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        $this->assertSame((int) $r->trial_user_id, (int) $sanctum->tokenable_id);
        $this->assertStringStartsWith('admin-view:', $sanctum->name);
        $this->assertEqualsWithDelta(now()->addHours(2)->timestamp, $sanctum->expires_at->timestamp, 5);

        $this->withToken($token)->getJson('/api/v1/trial/status')->assertOk()->assertJsonPath('data.admin_view', true);
        $this->withToken($token)->postJson('/api/v1/trial/request-full-access')->assertForbidden();
        $this->assertNull($r->fresh()->full_access_requested_at);
    }

    public function test_a_demo_account_is_not_asked_for_kyc_or_a_wallet(): void
    {
        $r = $this->approvedDemo();
        $user = User::findOrFail($r->trial_user_id);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc')->assertForbidden();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc/submit')->assertForbidden();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/lipr-onboard')->assertForbidden();
        $this->assertNull($user->fresh()->lipr_wallet);
    }

    public function test_the_emails_always_come_from_tujitume_even_to_someone_in_an_organisation(): void
    {
        $owner = User::factory()->create(['user_type_id' => 4, 'email' => 'jane@impala.test']);
        $org = Organization::create(['owner_user_id' => $owner->id, 'name' => 'Amari', 'legal_name' => 'Amari', 'organization_type' => 'other',
            'email' => 'jane@impala.test', 'focus_sectors' => [], 'operating_countries' => [], 'target_regions' => [], 'financial_year_start_month' => 1, 'status' => 'active']);
        $owner->update(['organization_id' => $org->id]);

        $this->submit()->assertCreated();

        $mail = $this->lastEmailTo('jane@impala.test');
        $this->assertNotNull($mail);
        $this->assertStringNotContainsString('Amari', collect($mail->getFrom())->map->getName()->implode(' '));
        $this->assertStringNotContainsString('The Amari', (string) $mail->getHtmlBody());
        $this->assertStringContainsString('Tujitume', (string) $mail->getHtmlBody());
    }

    public function test_the_demo_ready_email_is_short_has_a_full_access_link_and_the_demo_is_in_shillings(): void
    {
        $r = $this->approvedDemo();

        $ready = $this->lastEmailTo('jane@impala.test');
        $this->assertSame('Your Tujitume demo account is ready', $ready->getSubject());
        $html = (string) $ready->getHtmlBody();
        $this->assertStringContainsString('Create your full account', $html);
        $token = $this->tokenIn($ready, 'invite');
        $this->assertNotNull(ProgramRegistrationInvite::findUsable($token));
        $this->assertSame('trial_active', $r->fresh()->status, 'the link alone does not ask for full access');

        $program = Program::where('organization_id', $r->trial_organization_id)->firstOrFail();
        $this->assertSame('KES', $program->currency);
        $this->assertEquals(250000, (float) $program->funding_per_business);
        $this->assertSame('KES', \App\Models\Auth\UserSetting::where('user_id', $r->trial_user_id)->value('currency'));

        // The emailed link signs up the full account
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/register', $this->registrationPayload($token, 'jane@impala.test'))->assertCreated();
        $this->assertSame('registered', $r->fresh()->status);
    }

    public function test_the_sign_up_link_brings_the_phone_given_in_the_demo_and_sign_up_frees_it(): void
    {
        $r = $this->approvedDemo();
        $demoUser = User::findOrFail($r->trial_user_id);
        $demoUser->update(['phone' => '+254712345678']);

        $url = $this->actingAs($demoUser, 'sanctum')->postJson('/api/v1/trial/request-full-access')->json('data.invite_url');
        preg_match('/invite=([A-Za-z0-9]{64})/', $url, $m);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/program-invites/'.$m[1])->assertOk()
            ->assertJsonPath('data.email', 'jane@impala.test')
            ->assertJsonPath('data.phone', '+254712345678')
            ->assertJsonPath('data.org_name', 'Impala SACCO');

        $this->postJson('/api/v1/register', ['phone' => '+254712345678'] + $this->registrationPayload($m[1], 'jane@impala.test'))->assertCreated();
        $this->assertNull($demoUser->fresh(), 'the demo account is gone');
        $this->assertSame(1, User::where('phone', '+254712345678')->count());
    }

    public function test_the_full_account_keeps_the_demo_password_so_sign_up_does_not_ask_again(): void
    {
        $r = $this->approvedDemo();
        $demoUser = User::findOrFail($r->trial_user_id);
        $demoUser->update(['password' => 'DemoPass123']);

        $url = $this->actingAs($demoUser, 'sanctum')->postJson('/api/v1/trial/request-full-access')->json('data.invite_url');
        preg_match('/invite=([A-Za-z0-9]{64})/', $url, $m);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/program-invites/'.$m[1])->assertOk()->assertJsonPath('data.password_set', true);

        $payload = $this->registrationPayload($m[1], 'jane@impala.test');
        unset($payload['password']);
        $id = $this->postJson('/api/v1/register', $payload)->assertCreated()->json('user.id');

        $this->assertTrue(Hash::check('DemoPass123', User::findOrFail($id)->password));
        $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('web')->validate(['email' => 'jane@impala.test', 'password' => 'DemoPass123']));
    }

    public function test_without_a_demo_password_sign_up_still_needs_one(): void
    {
        [, $token] = ProgramRegistrationInvite::issue('new@impala.test', 'New Person');
        $this->getJson('/api/v1/program-invites/'.$token)->assertOk()->assertJsonPath('data.password_set', false);

        $payload = $this->registrationPayload($token, 'new@impala.test');
        unset($payload['password']);
        $this->postJson('/api/v1/register', $payload)->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_a_demo_organisation_never_brands_emails_but_the_real_account_does(): void
    {
        $r = $this->approvedDemo();
        $this->assertFalse(\App\Service\Notification\EmailBrand::forEmail('jane@impala.test')['custom']);
        $this->assertSame('Tujitume', \App\Service\Notification\EmailBrand::forEmail('jane@impala.test')['name']);

        $url = $this->actingAs(User::findOrFail($r->trial_user_id), 'sanctum')->postJson('/api/v1/trial/request-full-access')->json('data.invite_url');
        preg_match('/invite=([A-Za-z0-9]{64})/', $url, $m);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/register', $this->registrationPayload($m[1], 'jane@impala.test'))->assertCreated();

        $brand = \App\Service\Notification\EmailBrand::forEmail('jane@impala.test');
        $this->assertTrue($brand['custom']);
        $this->assertSame('Impala SACCO', $brand['name']);
    }
}
