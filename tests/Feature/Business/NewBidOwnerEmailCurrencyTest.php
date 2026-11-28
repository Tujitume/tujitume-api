<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The "You have a new bid" email to the business owner shows the bid in the listing's currency (KSh), as the app does, never "USD". */
class NewBidOwnerEmailCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function ownerEmailBody(): string
    {
        $mail = collect(app('mailer')->getSymfonyTransport()->messages())
            ->first(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress() === 'owner@example.test'
                && str_contains($m->getOriginalMessage()->getSubject(), 'New Bid'));
        $this->assertNotNull($mail, 'the owner got no new-bid email');

        return $mail->getOriginalMessage()->getHtmlBody();
    }

    private function listing(): array
    {
        config(['mail.default' => 'array', 'app.currency' => 'KES']);
        $owner = User::factory()->create(['user_type_id' => 1, 'email' => 'owner@example.test']);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Solar kiosks', 'category' => 'Energy', 'location' => 'Nakuru',
            'investment_needed' => 1300000, 'share' => 10, 'active' => 1]);

        return [$investor, $listing];
    }

    public function test_a_monetary_bid_email_shows_kes(): void
    {
        [$investor, $listing] = $this->listing();
        DB::table('lipr_payments')->insert(['reference_id' => 'REF-K', 'transaction_id' => 'TX-K', 'user_id' => $investor->id, 'status' => 'successful', 'purpose' => 'bids',
            'amount' => 79949, 'amount_usd' => 614.86, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($investor)->postJson('/api/v1/lipr-status-bids', [
            'business_id' => $listing->id, 'referenceId' => 'REF-K', 'amountUSD' => 300000, 'amountPaid' => 79949,
        ])->assertOk();

        $body = $this->ownerEmailBody();
        $this->assertStringContainsString('KES 300,000.00', $body);
        $this->assertStringNotContainsString('USD', $body);
    }

    public function test_an_asset_bid_email_shows_kes(): void
    {
        [$investor, $listing] = $this->listing();

        $this->actingAs($investor)->postJson('/api/v1/bidCommitsEQP', [
            'listing_id' => $listing->id, 'amount' => 100000, 'serial' => 'Solar fridge SN-1',
        ])->assertOk();

        $body = $this->ownerEmailBody();
        $this->assertStringContainsString('KES 100,000.00', $body);
        $this->assertStringNotContainsString('USD', $body);
    }
}
