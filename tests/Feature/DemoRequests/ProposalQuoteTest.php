<?php

namespace Tests\Feature\DemoRequests;

use App\Models\Admin;
use App\Models\DemoRequest;
use App\Service\DemoRequests\ProposalQuote;
use Database\Seeders\ProgramIndustrySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The admin pricing calculator on a proposal / pilot request (Pilot Pricing and Proposal Flow; AI credits not yet). */
class ProposalQuoteTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;
    private DemoRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ProgramIndustrySeeder::class]);
        $this->admin = Admin::create(['name' => 'Ops', 'email' => 'ops@tujitume.test', 'password' => Hash::make('secret')]);
        $this->postJson('/api/v1/contact/request-demo', [
            'name' => 'Jane Wanjiru', 'org' => 'Impala SACCO', 'email' => 'jane@impala.test', 'request_type' => 'pilot',
            'program_industry_id' => (int) \App\Models\ProgramIndustry::where('url', 'sacco-housing')->value('id'),
            'notes' => 'Member housing loans', 'timezone' => 'Africa/Nairobi',
        ])->assertCreated();
        $this->request = DemoRequest::firstOrFail();
    }

    private function save(array $terms)
    {
        return $this->actingAs($this->admin, 'admin')->post(route('admin.demo_requests.quote', $this->request), $terms + [
            'implementation_fee' => 2500, 'subscription_monthly' => 1500, 'billing_period' => 'monthly',
            'discount_percent' => 0, 'discount_months' => 0, 'custom_development_fee' => 0, 'review_after_days' => 60,
        ]);
    }

    public function test_the_documents_example_first_month_is_4000(): void
    {
        $this->save([])->assertRedirect()->assertSessionHasNoErrors();
        $quote = $this->request->quotes()->firstOrFail();
        $totals = ProposalQuote::calculate($quote);

        $this->assertSame(4000.0, $totals['first_invoice']);
        $this->assertSame(2500.0 + 12 * 1500, $totals['first_year_total']);
        $this->assertStringContainsString('First invoice: USD 4,000.00', ProposalQuote::summary($quote));
        $this->actingAs($this->admin, 'admin')->get(route('admin.demo_requests.show', $this->request))
            ->assertOk()->assertSee('Proposal terms (internal calculator)')->assertSee('USD 4,000.00')->assertSee('Put the terms in the message');
    }

    public function test_quarterly_billing_with_a_two_month_pilot_discount(): void
    {
        $this->save(['billing_period' => 'quarterly', 'discount_percent' => 20, 'discount_months' => 2, 'custom_development_fee' => 1000,
            'concession_reason' => 'First SACCO pilot in Nakuru'])->assertSessionHasNoErrors();
        $t = ProposalQuote::calculate($this->request->quotes()->firstOrFail());

        $this->assertSame(1200.0, $t['discounted_monthly']);
        // 2 discounted months + 1 full month + setup + development
        $this->assertSame(2500.0 + 1000 + 2 * 1200 + 1500, $t['first_invoice']);
        $this->assertSame(2500.0 + 1000 + 2 * 1200 + 10 * 1500, $t['first_year_total']);
    }

    public function test_guardrails(): void
    {
        // A subscription is needed (no minimum amount)
        $this->save(['subscription_monthly' => 0])->assertSessionHasErrors('subscription_monthly');
        // At most 50% off
        $this->save(['discount_percent' => 60, 'discount_months' => 3, 'concession_reason' => 'x'])->assertSessionHasErrors('discount_percent');
        // A discount must end (months) and last at most 6 months
        $this->save(['discount_percent' => 10, 'concession_reason' => 'x'])->assertSessionHasErrors('discount_months');
        $this->save(['discount_percent' => 10, 'discount_months' => 7, 'concession_reason' => 'x'])->assertSessionHasErrors('discount_months');
        // Every concession has a reason
        $this->save(['discount_percent' => 10, 'discount_months' => 3])->assertSessionHasErrors('concession_reason');
        // Review between 60 and 90 days
        $this->save(['review_after_days' => 30])->assertSessionHasErrors('review_after_days');
        $this->assertSame(0, $this->request->quotes()->count());
    }

    public function test_each_save_is_a_version_and_a_lower_price_needs_a_reason(): void
    {
        $this->save([])->assertSessionHasNoErrors();
        $this->save(['subscription_monthly' => 1200])->assertSessionHasErrors('concession_reason');
        $this->save(['subscription_monthly' => 1200, 'concession_reason' => 'Two programs instead of three'])->assertSessionHasNoErrors();
        // A higher price is not a concession
        $this->save(['subscription_monthly' => 1800])->assertSessionHasNoErrors();

        $versions = $this->request->quotes()->get();
        $this->assertCount(3, $versions);
        $this->assertSame([1800.0, 1200.0, 1500.0], $versions->pluck('subscription_monthly')->all());
        $this->assertSame('Two programs instead of three', $versions[1]->concession_reason);
        $this->assertSame($this->admin->id, (int) $versions[0]->created_by_admin_id);
    }

    public function test_dates_follow_the_first_payment(): void
    {
        $this->save(['discount_percent' => 10, 'discount_months' => 3, 'review_after_days' => 75, 'concession_reason' => 'Pilot'])->assertSessionHasNoErrors();
        $quote = $this->request->quotes()->firstOrFail();
        $this->assertNull(ProposalQuote::milestones($this->request, $quote)['review_due_at']);

        $this->request->update(['converted_at' => '2026-12-01 09:00:00']);
        $dates = ProposalQuote::milestones($this->request->fresh(), $quote);
        $this->assertSame('2027-03-01', $dates['discount_ends_at']->toDateString());
        $this->assertSame('2027-02-14', $dates['review_due_at']->toDateString());
    }

    public function test_the_email_fills_itself_from_the_saved_terms(): void
    {
        $before = \App\Service\DemoRequests\DemoRequestMailer::templateFor($this->request);
        $this->assertStringContainsString('[Pricing: fill in the proposal terms calculator', $before['body']);
        $this->assertStringContainsString('Scope: Member housing loans', $before['body']);

        $this->save(['discount_percent' => 20, 'discount_months' => 3, 'concession_reason' => 'Pilot', 'scope_notes' => 'One housing program, 80 members'])->assertSessionHasNoErrors();
        $body = \App\Service\DemoRequests\DemoRequestMailer::templateFor($this->request->fresh())['body'];

        $this->assertStringStartsWith('Hi Jane,', $body);
        $this->assertStringContainsString('Scope: One housing program, 80 members', $body);
        $this->assertStringContainsString('- Implementation (one time): USD 2,500.00', $body);
        $this->assertStringContainsString('20% off the subscription for the first 3 months (USD 1,200.00 a month)', $body);
        $this->assertStringContainsString('- First invoice: USD 3,700.00', $body);
        $this->assertStringNotContainsString('[', $body);
        $this->actingAs($this->admin, 'admin')->get(route('admin.demo_requests.show', $this->request))->assertOk()->assertSee('First invoice: USD 3,700.00');

        // A template saved before {terms} existed still gets the terms, before the sign-off
        \App\Service\DemoRequests\DemoRequestMailer::saveTemplate('pilot', 'Pilot for {org}', "Hi {name},\n\nOld text.\n\nKind regards,\nThe team");
        $body = \App\Service\DemoRequests\DemoRequestMailer::templateFor($this->request->fresh())['body'];
        $this->assertMatchesRegularExpression('/Old text\.\n\nProposed terms\n.*First invoice.*\n\nKind regards/s', $body);
    }

    public function test_a_demo_request_has_no_calculator(): void
    {
        $this->request->update(['request_type' => DemoRequest::TYPE_DEMO]);
        $this->actingAs($this->admin, 'admin')->get(route('admin.demo_requests.show', $this->request))
            ->assertOk()->assertDontSee('Proposal terms (internal calculator)');
    }

    public function test_only_admins(): void
    {
        $this->post(route('admin.demo_requests.quote', $this->request), [])->assertRedirect();
        $this->assertSame(0, $this->request->quotes()->count());
    }
}
