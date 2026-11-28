<?php

use App\Models\Capital\CapitalMilestone;
use App\Models\Capital\StartupPitches;
use App\Models\Communication\Notifications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(Tests\Feature\Capital\CapitalTestCase::class);

$application = fn ($test, array $extra = []) => $extra + [
    'capital_id' => $test->offer->id, 'startup_name' => 'Maji Startup', 'contact_person_name' => 'Amina', 'contact_person_email' => 'amina@example.test',
    'sector' => 'Agriculture', 'headquarters_location' => 'Kenya', 'stage' => 'seed', 'total_amount_requested' => 5000,
    'social_impact_areas' => ['clean water', 'rural jobs'],
];

describe('Applying for capital', function () use ($application) {
    it('stores the application without milestones, scores it and tells the provider and its acting team', function () use ($application) {
        $editor = $this->teamMember($this->capitalOwner, 'editor');
        $viewer = $this->teamMember($this->capitalOwner, 'viewer');

        $res = $this->actingAsApplicant()->postJson('/api/v1/capital/investment-application', $application($this))->assertOk();
        $pitch = StartupPitches::firstOrFail();
        expect($pitch->capital_owner_id)->toBe($this->capitalOwner->id)->and($pitch->business_id)->toBeNull()
            ->and($pitch->score)->toBe(65) // sector 30 + geo 15 + stage 10 + impact 10
            ->and($pitch->score_breakdown_parsed['sector'])->toBe(30.0)
            ->and($res->json('result'))->toBe('Strong Match');

        $link = 'dashboard.capitalOrg.pitch?pitch=@'.$pitch->id;
        expect(Notifications::where('link', $link)->pluck('receiver_id')->sort()->values()->all())->toBe(collect([$this->capitalOwner->id, $editor->id])->sort()->values()->all());
        expect(Notifications::where('receiver_id', $viewer->id)->exists())->toBeFalse();
    });

    it('stores milestones and files on s3 inside one transaction', function () use ($application) {
        Storage::fake('s3');
        $this->actingAsApplicant()->post('/api/v1/capital/investment-application', $application($this, [
            'pitch_deck_file' => UploadedFile::fake()->create('deck.pdf', 50, 'application/pdf'),
            'milestones' => [
                ['title' => 'Pilot', 'amount' => 2000, 'description' => 'first', 'deliverables' => ['file' => UploadedFile::fake()->create('plan.pdf', 20, 'application/pdf')]],
                ['title' => 'Scale', 'amount' => 3000],
            ],
        ]), ['Accept' => 'application/json'])->assertOk();
        $pitch = StartupPitches::firstOrFail();
        Storage::disk('s3')->assertExists($pitch->pitch_deck_file);
        expect(CapitalMilestone::where('app_id', $pitch->id)->pluck('title')->all())->toBe(['Pilot', 'Scale']);
        Storage::disk('s3')->assertExists(CapitalMilestone::where('title', 'Pilot')->value('document'));
    });

    it('needs a business when the entrepreneur has one, and only their own', function () use ($application) {
        $mine = $this->makeListing($this->applicantUser);
        $theirs = $this->makeListing(\App\Models\Auth\User::factory()->create(['user_type_id' => 1]));
        $this->actingAsApplicant()->postJson('/api/v1/capital/investment-application', $application($this))
            ->assertUnprocessable()->assertJsonPath('errors.business_id.0', 'Choose the business you are applying with.');
        $this->actingAsApplicant()->postJson('/api/v1/capital/investment-application', $application($this, ['business_id' => $theirs->id]))
            ->assertUnprocessable()->assertJsonPath('errors.business_id.0', 'Choose one of your own businesses.');
        $this->actingAsApplicant()->postJson('/api/v1/capital/investment-application', $application($this, ['business_id' => $mine->id]))->assertOk();
    });

    it('refuses closed or hidden offers, a second application, too much money and bad milestones', function () use ($application) {
        $closed = $this->makeOffer($this->capitalOwner, ['end_date' => now()->subDay()->toDateString()]);
        $this->actingAsApplicant()->postJson('/api/v1/capital/investment-application', $application($this, ['capital_id' => $closed->id]))->assertUnprocessable();
        $this->actingAsApplicant()->postJson('/api/v1/capital/investment-application', $application($this, ['total_amount_requested' => 50000]))->assertUnprocessable()->assertJsonValidationErrors('total_amount_requested');
        $this->actingAsApplicant()->postJson('/api/v1/capital/investment-application', $application($this, ['milestones' => [['title' => 'Too much', 'amount' => 9000]]]))->assertUnprocessable()->assertJsonValidationErrors('milestones');
        $this->actingAsApplicant()->postJson('/api/v1/capital/investment-application', $application($this, ['milestones' => [['amount' => 10]]]))->assertUnprocessable()->assertJsonValidationErrors('milestones.0.title');
        $this->actingAsApplicant()->postJson('/api/v1/capital/investment-application', $application($this))->assertOk();
        $this->actingAsApplicant()->postJson('/api/v1/capital/investment-application', $application($this))->assertUnprocessable()->assertJsonPath('message', 'You have already applied to this capital offer.');
        expect(StartupPitches::count())->toBe(1);
    });

    it('scores a draft application for an entrepreneur', function () use ($application) {
        $res = $this->actingAsApplicant()->postJson("/api/v1/capital/match-score/{$this->offer->id}", $application($this, ['bonus_points' => 'youth_led,gender_led']))->assertOk();
        expect($res->json('score'))->toEqual(75)->and($res->json('score_breakdown.bonus'))->toEqual(10);
        $this->actingAsApplicant()->postJson('/api/v1/capital/match-score/999999', [])->assertNotFound();
    });
});

describe('Deciding on an application', function () {
    it('accepts with a link the entrepreneur can open, and adds one tranche when none was planned', function () {
        $pitch = $this->makePitch();
        $this->asCapital($this->teamMember($this->capitalOwner, 'editor'))->getJson("/api/v1/capital/accept/{$pitch->id}")->assertOk();
        expect($pitch->fresh()->status)->toBe(StartupPitches::STATUS_ACCEPTED);
        $n = Notifications::where('receiver_id', $this->applicantUser->id)->latest('id')->first();
        expect($n->link)->toBe('dashboard.entrepreneur.capitalDealroom?pitch=@'.$pitch->id)->and($n->text)->toContain('accepted');
        expect(CapitalMilestone::where('app_id', $pitch->id)->value('amount'))->toEqual(5000);
    });

    it('rejects without deleting, with the reason and the right words', function () {
        $pitch = $this->makePitch();
        $this->asCapital()->postJson("/api/v1/capital/reject/{$pitch->id}", ['reason' => 'Outside our focus'])->assertOk();
        $pitch->refresh();
        expect($pitch->status)->toBe(StartupPitches::STATUS_REJECTED)->and($pitch->rejection_reason)->toBe('Outside our focus');
        $n = Notifications::where('receiver_id', $this->applicantUser->id)->latest('id')->first();
        expect($n->text)->toContain('not selected')->toContain('Outside our focus')->not->toContain('accepted')
            ->and($n->link)->toBe('dashboard.entrepreneur.capitalApplication?application=@'.$pitch->id);
        // the old GET still works
        $this->asCapital()->getJson('/api/v1/capital/reject/'.$this->makePitch($this->makeOffer($this->capitalOwner))->id)->assertOk();
    });
});

describe('Asking for the next tranche', function () {
    it('lets the entrepreneur ask once the terms are accepted and tells the provider', function () {
        [$pitch] = $this->fundablePitch();
        $this->actingAsApplicant()->getJson("/api/v1/capital/fund-release-request/{$pitch->id}")->assertOk()->assertJsonPath('milestone.title', 'Pilot');
        expect(Notifications::where('receiver_id', $this->capitalOwner->id)->where('link', 'dashboard.capitalOrg.investments?pitch=@'.$pitch->id.'&tab=funding')->exists())->toBeTrue();
        $this->actingAsApplicant()->getJson("/api/v1/capital/fund-release-request/{$pitch->id}")->assertStatus(429);
    });

    it('refuses before the terms are agreed, and to anyone else', function () {
        $pitch = $this->makePitch(status: StartupPitches::STATUS_ACCEPTED);
        $this->actingAsApplicant()->getJson("/api/v1/capital/fund-release-request/{$pitch->id}")->assertUnprocessable();
        $this->asCapital()->getJson("/api/v1/capital/fund-release-request/{$pitch->id}")->assertForbidden();
        $this->actingAs(\App\Models\Auth\User::factory()->create(['user_type_id' => 1]), 'sanctum')->getJson("/api/v1/capital/fund-release-request/{$pitch->id}")->assertForbidden();
        $this->actingAsApplicant()->getJson('/api/v1/capital/fund-release-request/999999')->assertNotFound();
    });
});
