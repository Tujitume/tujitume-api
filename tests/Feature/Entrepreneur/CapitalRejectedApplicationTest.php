<?php

namespace Tests\Feature\Entrepreneur;

use App\Models\Auth\User;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\StartupPitches;
use Tests\Feature\Programs\ProgramTestCase;

/**
 * Rejecting a capital application keeps it with status 2 (it used to be deleted): the entrepreneur sees it as
 * "rejected" in My Capital Applications, the Rejected card counts it, and it never counts as pending or active.
 */
class CapitalRejectedApplicationTest extends ProgramTestCase
{
    private User $capitalOwner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capitalOwner = User::factory()->create(['user_type_id' => 5]);
    }

    private function offer(array $a = []): CapitalOffer
    {
        return CapitalOffer::create($a + ['user_id' => $this->capitalOwner->id, 'offer_title' => 'Fund '.uniqid(), 'total_capital_available' => 100000, 'per_startup_allocation' => 1000, 'sectors' => ['Agriculture'], 'regions' => ['Kenya'], 'startup_stage' => ['seed'], 'visible' => 1, 'start_date' => '2026-01-01', 'end_date' => '2030-12-31']);
    }

    private function apply(CapitalOffer $o, int $status = 0): StartupPitches
    {
        return StartupPitches::create(['capital_id' => $o->id, 'user_id' => $this->applicantUser->id, 'capital_owner_id' => $this->capitalOwner->id, 'startup_name' => 'Mine', 'contact_person_name' => 'Me', 'contact_person_email' => 'me@example.test', 'sector' => 'Agriculture', 'headquarters_location' => 'Nairobi', 'stage' => 'seed', 'burn_rate' => 0, 'total_amount_requested' => 500, 'status' => $status]);
    }

    public function test_reject_keeps_the_application_with_a_rejected_status(): void
    {
        $pitch = $this->apply($this->offer());

        $this->actingAs($this->capitalOwner, 'sanctum')->getJson("/api/v1/capital/reject/{$pitch->id}")->assertOk();

        $this->assertDatabaseHas('startup_pitches', ['id' => $pitch->id, 'status' => StartupPitches::STATUS_REJECTED]);
    }

    public function test_only_the_capital_provider_it_was_sent_to_can_accept_or_reject_it(): void
    {
        $pitch = $this->apply($this->offer());
        $other = User::factory()->create(['user_type_id' => 5]);

        $this->actingAs($other, 'sanctum')->getJson("/api/v1/capital/reject/{$pitch->id}")->assertNotFound();
        $this->actingAs($other, 'sanctum')->getJson("/api/v1/capital/accept/{$pitch->id}")->assertNotFound();
        $this->assertDatabaseHas('startup_pitches', ['id' => $pitch->id, 'status' => StartupPitches::STATUS_PENDING]);

        $this->actingAs($this->capitalOwner, 'sanctum')->getJson('/api/v1/capital/accept/999999')->assertNotFound();
        $this->actingAs($this->capitalOwner, 'sanctum')->getJson("/api/v1/capital/accept/{$pitch->id}")->assertOk();
        $this->assertDatabaseHas('startup_pitches', ['id' => $pitch->id, 'status' => StartupPitches::STATUS_ACCEPTED]);
    }

    public function test_the_entrepreneur_lists_and_counts_rejected_applications(): void
    {
        $this->apply($this->offer());
        $this->apply($this->offer(), StartupPitches::STATUS_ACCEPTED);
        $rejected = $this->apply($this->offer(['offer_title' => 'Rejected Fund']));
        $this->actingAs($this->capitalOwner, 'sanctum')->getJson("/api/v1/capital/reject/{$rejected->id}")->assertOk();

        $url = '/api/v1/capital/my-applications';
        $page = $this->actingAs($this->applicantUser, 'sanctum')->getJson($url.'?page=1&per_page=10')->assertOk();
        $this->assertSame(['total' => 3, 'pending' => 1, 'approved' => 1, 'rejected' => 1], array_intersect_key($page->json('data.stats'), array_flip(['total', 'pending', 'approved', 'rejected'])));
        $this->assertEquals(['pending' => 1, 'approved' => 1, 'rejected' => 1], collect($page->json('data.facets.status'))->pluck('count', 'value')->all());

        $only = $this->actingAs($this->applicantUser, 'sanctum')->getJson($url.'?'.http_build_query(['export' => 1, 'filters' => ['status' => ['rejected']]]))->json('data.items');
        $this->assertCount(1, $only);
        $this->assertSame('rejected', $only[0]['application_status']);
        $this->assertSame('Rejected Fund', $only[0]['offer_title']);

        // the Capital Deal Room lists approved applications only
        $deals = $this->actingAs($this->applicantUser, 'sanctum')->getJson($url.'?page=1&per_page=10&stage=approved')->assertOk();
        $this->assertSame(1, $deals->json('data.pagination.total'));
    }

    public function test_applying_again_after_a_rejection_lists_the_new_application(): void
    {
        $offer = $this->offer();
        $old = $this->apply($offer, StartupPitches::STATUS_REJECTED);
        $old->forceFill(['created_at' => now()->subDay()])->save();
        $this->apply($offer);

        $page = $this->actingAs($this->applicantUser, 'sanctum')->getJson('/api/v1/capital/my-applications?page=1&per_page=10')->assertOk();
        $this->assertSame(1, $page->json('data.pagination.total'));
        $this->assertSame('pending', $page->json('data.items.0.application_status'));
        $this->assertSame(0, $page->json('data.stats.rejected'));
    }

    public function test_rejected_applications_are_not_pending_or_active_elsewhere(): void
    {
        $offer = $this->offer();
        $this->apply($offer);
        $this->apply($this->offer(), StartupPitches::STATUS_REJECTED);

        // Explore Capital marks the offer with the rejected status
        $discover = collect($this->actingAs($this->applicantUser, 'sanctum')->getJson('/api/v1/capital/capital-offers')->assertOk()->json('capital'));
        $this->assertEqualsCanonicalizing(['pending', 'rejected'], $discover->where('applied', true)->pluck('application_status')->all());

        // the capital owner's pitch count and pending count leave the rejected one out
        $owner = $this->actingAs($this->capitalOwner, 'sanctum');
        $offers = collect($owner->getJson('/api/v1/capital/capital-offers')->assertOk()->json('capital'));
        $this->assertSame(1, $offers->firstWhere('id', $offer->id)['pitch_count']);
        $mine = $owner->getJson('/api/v1/capital/my_pitches')->assertOk();
        $this->assertSame(1, $mine->json('pitch_count_pending'));

        // the entrepreneur dashboard ring shows it as rejected, not pending
        $stats = $this->actingAs($this->applicantUser, 'sanctum')->getJson('/api/v1/dashboard/entrepreneur/stats')->assertOk();
        $this->assertEquals(['pending' => 1, 'rejected' => 1], collect($stats->json('data.capital_application_statuses'))->pluck('count', 'status')->all());
        $this->assertSame(1, $stats->json('data.capital.pending_review'));
    }
}
