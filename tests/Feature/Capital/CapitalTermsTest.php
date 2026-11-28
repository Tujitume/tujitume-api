<?php

use App\Models\Capital\CapitalTermsAgreement;
use App\Models\Capital\StartupPitches;
use App\Models\Communication\Notifications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(Tests\Feature\Capital\CapitalTestCase::class);

beforeEach(function () {
    Storage::fake('s3');
    $this->pitch = $this->makePitch(status: StartupPitches::STATUS_ACCEPTED);
    $this->doc = fn () => UploadedFile::fake()->create('terms.pdf', 20, 'application/pdf');
    $this->post = fn ($user, string $url, array $body) => $this->actingAs($user, 'sanctum')->post($url, $body, ['Accept' => 'application/json']);
});

describe('Terms agreements', function () {
    it('runs offer, counter-offer, rejection and acceptance between the two sides', function () {
        $url = '/api/v1/capital/terms-agreements';
        // the entrepreneur cannot start, the provider's first offer needs a document
        ($this->post)($this->applicantUser, $url, ['pitch_id' => $this->pitch->id, 'terms' => 'x'])->assertUnprocessable();
        ($this->post)($this->capitalOwner, $url, ['pitch_id' => $this->pitch->id, 'amount' => 5000])->assertUnprocessable()->assertJsonValidationErrors('document');

        ($this->post)($this->capitalOwner, $url, ['pitch_id' => $this->pitch->id, 'amount' => 5000, 'document' => ($this->doc)()])->assertOk()->assertJsonPath('agreement.status', 'submitted');
        expect(Notifications::where('receiver_id', $this->applicantUser->id)->where('link', 'dashboard.entrepreneur.capitalDealroom?pitch=@'.$this->pitch->id.'&tab=terms')->exists())->toBeTrue();

        // counter-offer: text required, document optional
        ($this->post)($this->applicantUser, $url, ['pitch_id' => $this->pitch->id, 'amount' => 6000])->assertUnprocessable()->assertJsonValidationErrors('terms');
        ($this->post)($this->applicantUser, $url, ['pitch_id' => $this->pitch->id, 'amount' => 6000, 'terms' => 'We need 6000 over two tranches'])->assertOk()->assertJsonPath('agreement.status', 'counter_submitted');
        $counter = CapitalTermsAgreement::latest('id')->first();
        expect($counter->terms)->toBe('We need 6000 over two tranches')->and($counter->document)->toBeNull();
        expect(Notifications::where('receiver_id', $this->capitalOwner->id)->where('link', 'dashboard.capitalOrg.investments?pitch=@'.$this->pitch->id.'&tab=terms')->exists())->toBeTrue();

        $list = $this->actingAsApplicant()->getJson("/api/v1/capital/terms-agreements/{$this->pitch->id}")->assertOk();
        expect($list->json('agreements.0.latest'))->toBeTrue()->and($list->json('can_respond'))->toBeFalse()->and($list->json('viewer_side'))->toBe('applicant');
        expect($this->asCapital()->getJson("/api/v1/capital/terms-agreements/{$this->pitch->id}")->json('can_respond'))->toBeTrue();

        // only the other side answers; rejecting keeps the agreement's document and never marks the terms agreed
        $update = '/api/v1/capital/terms-agreements/update';
        ($this->post)($this->applicantUser, $update, ['agreement_id' => $counter->id, 'status' => 'rejected'])->assertForbidden();
        ($this->post)($this->capitalOwner, $update, ['agreement_id' => $counter->id, 'status' => 'rejected', 'reason' => 'Too high'])->assertOk();
        expect($counter->fresh()->status)->toBe('rejected')->and($counter->fresh()->reason)->toBe('Too high')->and($this->pitch->fresh()->terms_agreed)->toBe(0);
        ($this->post)($this->capitalOwner, $update, ['agreement_id' => $counter->id, 'status' => 'accepted', 'document' => ($this->doc)()])->assertUnprocessable();

        // a new offer, accepted with the signed document
        ($this->post)($this->capitalOwner, $url, ['pitch_id' => $this->pitch->id, 'amount' => 5500, 'document' => ($this->doc)()])->assertOk();
        $offer = CapitalTermsAgreement::latest('id')->first();
        $original = $offer->document;
        ($this->post)($this->applicantUser, $update, ['agreement_id' => $offer->id, 'status' => 'accepted'])->assertUnprocessable()->assertJsonValidationErrors('document');
        ($this->post)($this->applicantUser, $update, ['agreement_id' => $offer->id, 'status' => 'accepted', 'document' => ($this->doc)()])->assertOk();
        expect($offer->fresh()->status)->toBe('accepted')->and($offer->fresh()->document)->not->toBe($original)->and($this->pitch->fresh()->terms_agreed)->toBe(1);
        ($this->post)($this->capitalOwner, $url, ['pitch_id' => $this->pitch->id, 'document' => ($this->doc)()])->assertUnprocessable();
    });

    it('keeps who proposed a version after it is answered', function () {
        $url = '/api/v1/capital/terms-agreements';
        ($this->post)($this->capitalOwner, $url, ['pitch_id' => $this->pitch->id, 'amount' => 5000, 'document' => ($this->doc)()])->assertOk()->assertJsonPath('agreement.proposed_by', 'provider');
        ($this->post)($this->applicantUser, $url, ['pitch_id' => $this->pitch->id, 'amount' => 5000, 'terms' => 'Grace period first'])->assertOk()->assertJsonPath('agreement.proposed_by', 'applicant');
        $counter = CapitalTermsAgreement::latest('id')->first();
        ($this->post)($this->capitalOwner, '/api/v1/capital/terms-agreements/update', ['agreement_id' => $counter->id, 'status' => 'accepted', 'document' => ($this->doc)()])->assertOk();

        $list = $this->asCapital()->getJson("/api/v1/capital/terms-agreements/{$this->pitch->id}")->assertOk();
        expect($list->json('agreements.0.status'))->toBe('accepted')
            ->and($list->json('agreements.0.proposed_by'))->toBe('applicant')
            ->and($list->json('agreements.1.proposed_by'))->toBe('provider');
    });

    it('keeps terms to the two sides of an accepted application', function () {
        $stranger = $this->makeCapitalOwner();
        $this->asCapital($stranger)->getJson("/api/v1/capital/terms-agreements/{$this->pitch->id}")->assertForbidden();
        ($this->post)($stranger, '/api/v1/capital/terms-agreements', ['pitch_id' => $this->pitch->id, 'document' => ($this->doc)()])->assertForbidden();
        $this->asCapital()->getJson('/api/v1/capital/terms-agreements/999999')->assertNotFound();

        $pending = $this->makePitch($this->makeOffer($this->capitalOwner));
        ($this->post)($this->capitalOwner, '/api/v1/capital/terms-agreements', ['pitch_id' => $pending->id, 'document' => ($this->doc)()])->assertUnprocessable();

        ($this->post)($this->capitalOwner, '/api/v1/capital/terms-agreements/update', ['agreement_id' => 999999, 'status' => 'accepted'])->assertNotFound();
        ($this->post)($this->capitalOwner, '/api/v1/capital/terms-agreements/update', ['agreement_id' => 1, 'status' => 'maybe'])->assertUnprocessable();
    });
});
