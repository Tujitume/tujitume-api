<?php

use App\Models\Auth\User;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\CapitalWatchlist;
use App\Models\Capital\StartupPitches;

uses(Tests\Feature\Capital\CapitalTestCase::class);

describe('Capital offers list', function () {
    it('gives a capital provider their own offers, hidden ones too, in both shapes', function () {
        $hidden = $this->makeOffer($this->capitalOwner, ['offer_title' => 'Hidden', 'visible' => 0]);
        $this->makeOffer($this->makeCapitalOwner(), ['offer_title' => 'Someone else']);

        $titles = collect($this->asCapital()->getJson('/api/v1/capital/capital-offers')->assertOk()->json('capital'))->pluck('offer_title');
        expect($titles->all())->toEqualCanonicalizing([$this->offer->offer_title, 'Hidden']);

        $list = $this->asCapital()->getJson('/api/v1/capital/capital-offers?page=1&per_page=10')->assertOk();
        expect($list->json('data.pagination.total'))->toBe(2)->and($list->json('data.items.0'))->toHaveKeys(['pitch_count', 'visible', 'timing', 'in_my_watchlist']);

        // a team member sees the owner's offers
        $editor = $this->teamMember($this->capitalOwner, 'editor');
        expect($this->asCapital($editor)->getJson('/api/v1/capital/capital-offers')->json('capital'))->toHaveCount(2);
    });

    it('gives entrepreneurs and service providers the visible offers open today', function () {
        $this->makeOffer($this->capitalOwner, ['offer_title' => 'Hidden', 'visible' => 0]);
        $this->makeOffer($this->capitalOwner, ['offer_title' => 'Closed', 'end_date' => now()->subDay()->toDateString()]);
        $this->makeOffer($this->capitalOwner, ['offer_title' => 'Later', 'start_date' => now()->addWeek()->toDateString()]);
        $sp = User::factory()->create(['user_type_id' => 3]);

        foreach ([$this->applicantUser, $sp] as $who) {
            $titles = collect($this->actingAs($who, 'sanctum')->getJson('/api/v1/capital/capital-offers')->assertOk()->json('capital'))->pluck('offer_title')->all();
            expect($titles)->toBe([$this->offer->offer_title]);
        }
        // the public home list too
        $this->app['auth']->forgetGuards();
        expect(collect($this->getJson('/api/v1/home/capital-offers')->assertOk()->json('capital'))->pluck('offer_title')->all())->toBe([$this->offer->offer_title]);
    });

    it('returns 404 for an unknown or hidden offer and shows the owner their hidden one', function () {
        $this->actingAsApplicant()->getJson('/api/v1/capital/get_capital/999999')->assertNotFound();
        $hidden = $this->makeOffer($this->capitalOwner, ['visible' => 0]);
        $this->actingAsApplicant()->getJson("/api/v1/capital/get_capital/{$hidden->id}")->assertNotFound();
        $this->asCapital()->getJson("/api/v1/capital/get_capital/{$hidden->id}")->assertOk()->assertJsonPath('capital-data.is_mine', true);
        $this->actingAsApplicant()->getJson("/api/v1/capital/get_capital/{$this->offer->id}")->assertOk()->assertJsonPath('capital-data.is_open', true);
    });
});

describe('Capital authorization', function () {
    it('keeps offer management to the offer owner and its team', function () {
        $other = $this->makeCapitalOwner();
        $id = $this->offer->id;
        $body = ['id' => $id, 'offer_title' => 'X', 'per_startup_allocation' => 1, 'startup_stage' => ['seed'], 'sectors' => ['A'], 'regions' => ['K']];

        $this->asCapital($other)->postJson('/api/v1/capital/update-capital', $body)->assertForbidden()->assertJsonPath('message', 'Only the capital provider who owns this offer can change it.');
        $this->asCapital($other)->getJson("/api/v1/capital/visibility/{$id}")->assertForbidden();
        $this->asCapital($other)->getJson("/api/v1/capital/delete-capital/{$id}")->assertForbidden();
        $this->asCapital($other)->getJson("/api/v1/capital/pitches/{$id}")->assertForbidden();

        $this->asCapital()->postJson('/api/v1/capital/update-capital', $body)->assertOk();
        expect($this->offer->fresh()->offer_title)->toBe('X')->and($this->offer->fresh()->sectors)->toBe(['A']);
        $this->asCapital()->getJson("/api/v1/capital/visibility/{$id}")->assertOk()->assertJsonPath('visible', 0);
        $this->asCapital()->getJson("/api/v1/capital/pitches/{$id}")->assertOk();

        // an editor edits but never deletes; a viewer only reads
        $editor = $this->teamMember($this->capitalOwner, 'editor');
        $viewer = $this->teamMember($this->capitalOwner, 'viewer');
        $this->asCapital($editor)->getJson("/api/v1/capital/visibility/{$id}")->assertOk();
        $this->asCapital($editor)->getJson("/api/v1/capital/delete-capital/{$id}")->assertForbidden();
        $this->asCapital($viewer)->getJson("/api/v1/capital/visibility/{$id}")->assertForbidden();
        $this->asCapital($viewer)->getJson("/api/v1/capital/pitches/{$id}")->assertOk();

        $this->asCapital()->getJson("/api/v1/capital/delete-capital/{$id}")->assertOk();
        expect(CapitalOffer::find($id))->toBeNull();
        $this->asCapital()->getJson('/api/v1/capital/delete-capital/999999')->assertNotFound();
    });

    it('lets entrepreneurs only discover, apply and follow their own applications', function () {
        $this->actingAsApplicant()->postJson('/api/v1/capital/create-capital-offer', [])->assertForbidden()->assertJsonPath('message', 'Only capital providers can do this.');
        $this->actingAsApplicant()->getJson("/api/v1/capital/visibility/{$this->offer->id}")->assertForbidden();
        $this->actingAsApplicant()->getJson('/api/v1/capital/analytics')->assertForbidden();
        $this->actingAsApplicant()->getJson('/api/v1/capital/get-watchlist')->assertForbidden();
        $this->actingAsApplicant()->getJson("/api/v1/capital/pitches/{$this->offer->id}")->assertForbidden();
        $this->actingAsApplicant()->getJson('/api/v1/capital/pitches/latest')->assertOk();
        $this->actingAsApplicant()->getJson('/api/v1/capital/my_pitches')->assertOk();
        $this->actingAsApplicant()->getJson('/api/v1/capital/my-applications')->assertOk();

        // a service provider only discovers
        $sp = User::factory()->create(['user_type_id' => 3]);
        $this->actingAs($sp, 'sanctum')->postJson('/api/v1/capital/investment-application', [])->assertForbidden()->assertJsonPath('message', 'Only business owners can apply for capital.');
        $this->actingAs($sp, 'sanctum')->getJson('/api/v1/capital/my_pitches')->assertForbidden();

        // a capital provider cannot apply
        $this->asCapital()->postJson('/api/v1/capital/investment-application', [])->assertForbidden();
    });

    it('shows one application only to its two sides', function () {
        $pitch = $this->makePitch();
        $stranger = User::factory()->create(['user_type_id' => 1]);
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/capital/pitch/{$pitch->id}")->assertForbidden();
        $this->asCapital($this->makeCapitalOwner())->getJson("/api/v1/capital/pitch/{$pitch->id}")->assertForbidden();
        $this->actingAsApplicant()->getJson("/api/v1/capital/pitch/{$pitch->id}")->assertOk()->assertJsonPath('pitch.viewer_side', 'applicant');
        $this->asCapital($this->teamMember($this->capitalOwner, 'viewer'))->getJson("/api/v1/capital/pitch/{$pitch->id}")->assertOk()->assertJsonPath('pitch.viewer_side', 'provider');
        $this->actingAsApplicant()->getJson('/api/v1/capital/pitch/999999')->assertNotFound();
    });

    it('keeps the watchlist on the owner, for the team to share', function () {
        $pitch = $this->makePitch();
        $editor = $this->teamMember($this->capitalOwner, 'editor');
        $this->asCapital($editor)->getJson("/api/v1/capital/store-watchlist/{$pitch->id}")->assertOk()->assertJsonPath('in_my_watchlist', true);
        expect(CapitalWatchlist::where('capital_owner_id', $this->capitalOwner->id)->where('pitch_id', $pitch->id)->exists())->toBeTrue();
        expect($this->asCapital()->getJson('/api/v1/capital/get-watchlist')->json('pitches'))->toHaveCount(1);
        expect($this->asCapital()->getJson('/api/v1/capital/my_pitches')->json('pitches.0.in_my_watchlist'))->toBeTrue();

        $this->asCapital($this->makeCapitalOwner())->getJson("/api/v1/capital/store-watchlist/{$pitch->id}")->assertNotFound();
        $this->asCapital()->getJson("/api/v1/capital/store-watchlist/{$pitch->id}")->assertOk()->assertJsonPath('in_my_watchlist', false);
    });

    it('lists the provider its applications and the entrepreneur theirs', function () {
        $this->makePitch();
        $this->makePitch($this->makeOffer($this->makeCapitalOwner()));
        $this->asCapital()->getJson('/api/v1/capital/my_pitches')->assertOk()->assertJsonPath('pitch_count', 1)->assertJsonPath('pitch_count_pending', 1);
        $this->actingAsApplicant()->getJson('/api/v1/capital/my_pitches')->assertOk()->assertJsonPath('pitch_count', 2);
    });
});
