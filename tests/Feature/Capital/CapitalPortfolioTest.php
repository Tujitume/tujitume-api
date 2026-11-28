<?php

use App\Models\Capital\CapitalMilestone;
use App\Models\Capital\CapitalTermsAgreement;
use App\Models\Capital\StartupPitches;

uses(Tests\Feature\Capital\CapitalTestCase::class);

$q = fn (array $params) => '?'.http_build_query($params);

beforeEach(function () {
    // A: funded, first milestone released (2000 of 5000), an audit open on the second
    [$this->a, $first, $this->aSecond] = $this->fundablePitch();
    $this->a->update(['startup_name' => 'Alpha Water']);
    $first->update(['fund_released' => true, 'release_status' => 'released', 'status' => 1, 'released_at' => now()->subDays(3), 'release_reference' => 'ch_9']);
    $this->aSecond->update(['audit_status' => 'pending', 'auditor_id' => 999]);

    // B: funded on a second offer, agreed 8000, nothing released
    $second = $this->makeOffer($this->capitalOwner, ['offer_title' => 'Second Fund']);
    $this->b = $this->makePitch($second, StartupPitches::STATUS_ACCEPTED);
    $this->b->update(['startup_name' => 'Beta Solar', 'sector' => 'Energy']);
    CapitalMilestone::create(['app_id' => $this->b->id, 'title' => 'Panels', 'amount' => 8000, 'document' => '']);
    CapitalTermsAgreement::create(['capital_id' => $second->id, 'pitch_id' => $this->b->id, 'business_owner_id' => $this->b->user_id, 'capital_owner_id' => $this->capitalOwner->id, 'status' => 'accepted', 'amount' => 8000, 'document' => 'x.pdf']);

    // never in the portfolio: accepted without agreed terms, pending, another provider's deal
    $noTerms = $this->makePitch(status: StartupPitches::STATUS_ACCEPTED);
    CapitalTermsAgreement::create(['capital_id' => $this->offer->id, 'pitch_id' => $noTerms->id, 'business_owner_id' => $noTerms->user_id, 'capital_owner_id' => $this->capitalOwner->id, 'status' => 'submitted', 'document' => 'x.pdf']);
    $this->makePitch();
    $this->makePitch($this->makeOffer($this->makeCapitalOwner()), StartupPitches::STATUS_ACCEPTED);
});

it('lists every funded business with its money, next milestone and totals', function () use ($q) {
    $res = $this->asCapital()->getJson('/api/v1/capital/portfolio'.$q(['page' => 1, 'per_page' => 10]))->assertOk();
    expect($res->json('data.pagination.total'))->toBe(2)
        ->and($res->json('data.stats'))->toMatchArray(['deals' => 2, 'open_audits' => 1, 'fully_released' => 0])
        ->and((float) $res->json('data.stats.committed'))->toEqual(13000.0)
        ->and((float) $res->json('data.stats.released'))->toEqual(2000.0)
        ->and((float) $res->json('data.stats.to_release'))->toEqual(11000.0);

    $alpha = collect($res->json('data.items'))->firstWhere('startup_name', 'Alpha Water');
    expect($alpha)->toMatchArray(['status' => 'releasing', 'audit_open' => true, 'milestones_count' => 2, 'released_count' => 1, 'progress' => 40])
        ->and((float) $alpha['committed'])->toEqual(5000.0)->and((float) $alpha['to_release'])->toEqual(3000.0)
        ->and($alpha['next_milestone']['title'])->toBe('Scale')->and($alpha['last_released_at'])->not->toBeNull()
        ->and($alpha['milestones'][0])->toMatchArray(['title' => 'Pilot', 'released' => true, 'release_reference' => 'ch_9']);
    expect(collect($res->json('data.filter_schema'))->pluck('key')->all())->toBe(['status', 'offer', 'sector', 'audit', 'last_release', 'funded', 'committed']);

    // the team sees the owner's portfolio; others are refused
    $viewer = $this->teamMember($this->capitalOwner, 'viewer');
    expect($this->asCapital($viewer)->getJson('/api/v1/capital/portfolio'.$q(['page' => 1]))->json('data.pagination.total'))->toBe(2);
    $this->actingAsApplicant()->getJson('/api/v1/capital/portfolio')->assertForbidden();
});

it('searches, filters, sorts and exports on the server', function () use ($q) {
    $names = fn (array $params) => collect($this->asCapital()->getJson('/api/v1/capital/portfolio'.$q($params + ['export' => 1]))->assertOk()->json('data.items'))->pluck('startup_name')->all();
    expect($names(['search' => 'solar']))->toBe(['Beta Solar'])
        ->and($names(['filters' => ['status' => ['not_started']]]))->toBe(['Beta Solar'])
        ->and($names(['filters' => ['audit' => 'yes']]))->toBe(['Alpha Water'])
        ->and($names(['filters' => ['sector' => ['Energy']]]))->toBe(['Beta Solar'])
        ->and($names(['filters' => ['committed' => '6000~']]))->toBe(['Beta Solar'])
        ->and($names(['sort' => '-released']))->toBe(['Alpha Water', 'Beta Solar'])
        ->and($names(['sort' => 'to_release']))->toBe(['Alpha Water', 'Beta Solar'])
        ->and($names(['sort' => 'name']))->toBe(['Alpha Water', 'Beta Solar']);
});

it('asks the provider to pay a quoted audit fee in the Pending drawer', function () {
    $this->aSecond->update(['audit_status' => 'quoted', 'audit_fee' => 500]);
    \Illuminate\Support\Facades\Cache::flush();
    $types = collect($this->asCapital()->getJson('/api/v1/pending-tasks?fresh=1')->assertOk()->json('data.groups'))->flatMap(fn ($g) => collect($g['items'])->pluck('type'));
    expect($types)->toContain('capital.audit_fee');
    // a viewer cannot pay, so it is not their task
    \Illuminate\Support\Facades\Cache::flush();
    $viewer = $this->teamMember($this->capitalOwner, 'viewer');
    $types = collect($this->asCapital($viewer)->getJson('/api/v1/pending-tasks?fresh=1')->json('data.groups'))->flatMap(fn ($g) => collect($g['items'])->pluck('type'));
    expect($types)->not->toContain('capital.audit_fee');
});
