<?php

use App\Models\Capital\CapitalWatchlist;
use App\Models\Capital\StartupPitches;

uses(Tests\Feature\Capital\CapitalTestCase::class);

$q = fn (array $params) => '?'.http_build_query($params);
$schema = fn ($res) => collect($res->json('data.filter_schema'))->keyBy('key');

describe('Applications inbox (my_pitches) as a server list', function () use ($q, $schema) {
    beforeEach(function () {
        $second = $this->makeOffer($this->capitalOwner, ['offer_title' => 'Second Fund']);
        $this->a = $this->makePitch();
        $this->a->update(['score' => 92, 'startup_name' => 'Alpha', 'total_amount_requested' => 1000]);
        $this->a->forceFill(['created_at' => now()->subDay()])->save();
        $this->b = $this->makePitch($second, StartupPitches::STATUS_ACCEPTED);
        $this->b->update(['score' => 75, 'startup_name' => 'Beta', 'sector' => 'Energy', 'total_amount_requested' => 9000]);
        $this->c = $this->makePitch(status: StartupPitches::STATUS_REJECTED);
        $this->c->update(['score' => 40, 'startup_name' => 'Gamma', 'total_amount_requested' => 3000]);
        $this->c->forceFill(['created_at' => now()->subMonths(2)])->save();
        CapitalWatchlist::create(['pitch_id' => $this->b->id, 'capital_owner_id' => $this->capitalOwner->id]);
        // another provider's application never shows
        $this->makePitch($this->makeOffer($this->makeCapitalOwner()));
        $this->second = $second;
    });

    it('pages, describes its filters and counts over every application', function () use ($q, $schema) {
        $res = $this->asCapital()->getJson('/api/v1/capital/my_pitches'.$q(['page' => 1, 'per_page' => 2]))->assertOk();
        expect($res->json('data.pagination.total'))->toBe(3)->and($res->json('data.items'))->toHaveCount(2)
            ->and($res->json('data.stats'))->toMatchArray(['total' => 3, 'pending' => 1, 'accepted' => 1, 'rejected' => 1, 'avg_match_score' => 69, 'shortlisted' => 1])
            ->and((float) $res->json('data.stats.accept_rate'))->toEqual(33.33)
            ->and($res->json('data.items.0.startup_name'))->toBe('Beta') // newest first
            ->and($res->json('data.items.0'))->toHaveKeys(['offer_title', 'application_status', 'match_band', 'in_my_watchlist', 'applied_at']);
        expect($schema($res)->keys()->all())->toBe(['status', 'offer', 'match', 'sector', 'shortlist', 'applied', 'requested']);
        expect(collect($schema($res)['offer']['options'])->firstWhere('value', (string) $this->second->id)['label'])->toBe('Second Fund');

        // a team member sees the same inbox
        $viewer = $this->teamMember($this->capitalOwner, 'viewer');
        expect($this->asCapital($viewer)->getJson('/api/v1/capital/my_pitches'.$q(['page' => 1]))->json('data.pagination.total'))->toBe(3);
    });

    it('applies every filter, the search and the sorts', function () use ($q) {
        $names = fn (array $params) => collect($this->asCapital()->getJson('/api/v1/capital/my_pitches'.$q($params + ['export' => 1]))->assertOk()->json('data.items'))->pluck('startup_name')->all();
        expect($names(['filters' => ['status' => ['accepted']]]))->toBe(['Beta'])
            ->and($names(['filters' => ['offer' => [(string) $this->second->id]]]))->toBe(['Beta'])
            ->and($names(['filters' => ['match' => ['90-100', '<60']]]))->toBe(['Alpha', 'Gamma'])
            ->and($names(['filters' => ['sector' => ['Energy']]]))->toBe(['Beta'])
            ->and($names(['filters' => ['shortlist' => 'yes']]))->toBe(['Beta'])
            ->and($names(['filters' => ['applied' => now()->subWeek()->toDateString().'~']]))->toEqualCanonicalizing(['Alpha', 'Beta'])
            ->and($names(['search' => 'gamma']))->toBe(['Gamma'])
            ->and($names(['sort' => '-score']))->toBe(['Alpha', 'Beta', 'Gamma'])
            ->and($names(['sort' => 'requested']))->toBe(['Alpha', 'Gamma', 'Beta'])
            ->and($names(['sort' => 'applied']))->toBe(['Gamma', 'Alpha', 'Beta']);
    });

    it('keeps the old response without list parameters, and lists an entrepreneur their own', function () use ($q) {
        $old = $this->asCapital()->getJson('/api/v1/capital/my_pitches')->assertOk()->json();
        expect($old)->toHaveKeys(['pitches', 'avg_match_score', 'accept_rate', 'pitch_count', 'pitch_count_pending'])->not->toHaveKey('data');
        expect($this->actingAsApplicant()->getJson('/api/v1/capital/my_pitches'.$q(['page' => 1]))->json('data.pagination.total'))->toBe(4);
    });
});

describe('Provider offers list stats and filters', function () use ($q, $schema) {
    it('totals money and applications over all the owner offers, filters shown/hidden and sorts by applications', function () use ($q, $schema) {
        $hidden = $this->makeOffer($this->capitalOwner, ['offer_title' => 'Hidden Fund', 'visible' => 0, 'total_capital_available' => 50000, 'available_amount' => 20000]);
        $this->makePitch($hidden);
        $this->makePitch($hidden, StartupPitches::STATUS_ACCEPTED, \App\Models\Auth\User::factory()->create(['user_type_id' => 1]));
        $this->makePitch(); // on $this->offer
        $this->makeOffer($this->makeCapitalOwner(), ['total_capital_available' => 999999]);

        $res = $this->asCapital()->getJson('/api/v1/capital/capital-offers'.$q(['page' => 1, 'per_page' => 1]))->assertOk();
        expect($res->json('data.stats'))->toMatchArray(['total' => 2, 'total_capital' => 150000, 'available_total' => 120000, 'applications_total' => 3, 'hidden' => 1]);
        expect($schema($res)->has('visible'))->toBeTrue();

        $titles = fn (array $p) => collect($this->asCapital()->getJson('/api/v1/capital/capital-offers'.$q($p + ['export' => 1]))->json('data.items'))->pluck('offer_title')->all();
        expect($titles(['filters' => ['visible' => ['hidden']]]))->toBe(['Hidden Fund'])
            ->and($titles(['filters' => ['visible' => ['shown']]]))->toBe([$this->offer->offer_title])
            ->and($titles(['sort' => '-applications']))->toBe(['Hidden Fund', $this->offer->offer_title]);

        // the entrepreneur's Explore Capital list has no visibility filter
        expect($schema($this->actingAsApplicant()->getJson('/api/v1/capital/capital-offers'.$q(['page' => 1])))->has('visible'))->toBeFalse();
    });
});
