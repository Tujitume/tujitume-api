<?php

namespace Tests\Feature\Notifications;

use App\Models\Auth\User;
use App\Models\Communication\Notifications;
use App\Service\Notification\EmailLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** C6: a service provider never gets Business Coach links; external coaches keep theirs. */
class ProviderLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_coach_links_sent_to_a_provider_open_their_own_portal(): void
    {
        $provider = User::factory()->create(['user_type_id' => 3]);
        $coach = User::factory()->create(['user_type_id' => 6]);

        $a = Notifications::create(['receiver_id' => $provider->id, 'text' => 'x', 'link' => 'dashboard.reviewerGrantOrg.orders?deliver=@42#order-42']);
        $b = Notifications::create(['receiver_id' => $provider->id, 'text' => 'x', 'link' => 'dashboard.reviewerGrantOrg.siteVisitDetail::7']);
        $c = Notifications::create(['receiver_id' => $provider->id, 'text' => 'x', 'link' => 'dashboard.reviewerGrantOrg.applicationsMulti::id=3']);
        $d = Notifications::create(['receiver_id' => $coach->id, 'text' => 'x', 'link' => 'dashboard.reviewerGrantOrg.orders']);

        $this->assertSame('dashboard.serviceProvider.programWork.orders?deliver=@42#order-42', $a->fresh()->link);
        $this->assertSame('dashboard.serviceProvider.programWork.siteVisitReport::visitId=7', $b->fresh()->link);
        $this->assertSame('dashboard.serviceProvider.grantsDiscover', $c->fresh()->link);
        $this->assertSame('dashboard.reviewerGrantOrg.orders', $d->fresh()->link);

        $this->assertStringContainsString(rawurlencode('dashboard.serviceProvider.programWork.orders'), EmailLink::url('dashboard.reviewerGrantOrg.orders', $provider));
        $this->assertStringContainsString(rawurlencode('dashboard.reviewerGrantOrg.orders'), EmailLink::url('dashboard.reviewerGrantOrg.orders', $coach));
    }
}
