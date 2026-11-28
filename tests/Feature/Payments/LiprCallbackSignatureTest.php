<?php

namespace Tests\Feature\Payments;

use App\Service\LiprMpesa\LiprCallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ISSUE-48: a LIPR callback without the app's own signature is refused before it can change anything. */
class LiprCallbackSignatureTest extends TestCase
{
    use RefreshDatabase;

    private function forged(): array
    {
        return ['transaction' => ['reference' => 'FAKE', 'id' => 'X', 'transactionStatus' => 'Successful', 'amount' => 9000], 'metadata' => ['listingId' => 1]];
    }

    public function test_every_callback_route_refuses_a_post_without_a_signature(): void
    {
        foreach (['', '/disbursement-direct', '/transfer-to-supplier', '/disbursement-escrow', '/reviewer-payment', '/reviewer-payment-leg2', '/service-milestone', '/timeout'] as $path) {
            $this->postJson('/api/lipr-callback'.$path, $this->forged())->assertStatus(403);
            $this->postJson('/api/lipr-callback'.$path.'?sig=abc', $this->forged())->assertStatus(403);
        }
    }

    public function test_a_signature_for_another_path_does_not_work(): void
    {
        $sig = LiprCallback::signature('/lipr-callback/timeout');
        $this->postJson('/api/lipr-callback/service-milestone?sig='.$sig, $this->forged())->assertStatus(403);
        $this->postJson('/api/lipr-callback/timeout?sig='.$sig, [])->assertOk();
    }

    public function test_urls_handed_to_lipr_are_signed_and_verify(): void
    {
        config(['app.api_url' => 'https://api.example.test/api/']);
        $url = LiprCallback::url('/lipr-callback/service-milestone');
        $this->assertStringStartsWith('https://api.example.test/api/lipr-callback/service-milestone?sig=', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertTrue(LiprCallback::valid('/api/lipr-callback/service-milestone', $q['sig']));
    }

    public function test_verification_can_be_switched_off_for_the_deploy_window(): void
    {
        config(['services.lipr.verify_callbacks' => false]);
        $this->postJson('/api/lipr-callback/timeout', [])->assertOk();
    }
}
