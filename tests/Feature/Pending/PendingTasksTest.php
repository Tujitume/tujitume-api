<?php

use App\Models\Auth\User;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Programs\ProgramTestCase;

uses(ProgramTestCase::class);

function pendingFor($t, ?User $user = null, bool $fresh = true)
{
    Cache::flush();

    return $t->actingAs($user ?? $t->orgUser, 'sanctum')->getJson('/api/v1/pending-tasks'.($fresh ? '?fresh=1' : ''));
}
function pendingTypes($res): array
{
    return collect($res->json('data.groups'))->flatMap(fn ($g) => collect($g['items'])->pluck('type'))->all();
}
function pendingGroup($res, string $key): ?array
{
    return collect($res->json('data.groups'))->firstWhere('key', $key);
}

it('requires sign-in', function () {
    $this->getJson('/api/v1/pending-tasks')->assertStatus(401);
});

it('is empty when nothing is waiting', function () {
    $res = pendingFor($this, $this->reviewerUser)->assertOk();
    expect($res->json('data.total'))->toBe(0)->and($res->json('data.groups'))->toBe([]);
});

it('asks an unverified person to finish verification and shows a rejection reason', function () {
    $new = User::factory()->create(['user_type_id' => 1]);
    expect(pendingTypes(pendingFor($this, $new)))->toBe(['kyc.incomplete']);

    DB::table('kyc_verifications')->insert(['user_id' => $new->id, 'verification_type' => 'entrepreneur', 'status' => 'rejected', 'rejection_reason' => 'Blurry ID', 'created_at' => now(), 'updated_at' => now()]);
    $res = pendingFor($this, $new);
    expect(pendingTypes($res))->toBe(['kyc.rejected'])->and($res->json('data.groups.0.items.0.subtitle'))->toBe('Blurry ID');

    DB::table('kyc_verifications')->where('user_id', $new->id)->update(['status' => 'verified']);
    expect(pendingFor($this, $new)->json('data.total'))->toBe(0);
});

it('does not ask team members to verify on behalf of the organization', function () {
    $member = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
    expect(pendingTypes(pendingFor($this, $member)))->not->toContain('kyc.incomplete');
});

it('lists review requests, with a link that opens the Pending drawer on the review tab', function () {
    DB::table('round_reviewers')->insert(['round_id' => $this->round->id, 'user_id' => $this->reviewerUser->id, 'reviewer_type' => 'external', 'acceptance_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    $res = pendingFor($this, $this->reviewerUser);
    $item = pendingGroup($res, 'review')['items'][0];
    expect($item['type'])->toBe('review.request')->and($item['link'])->toBe('dashboard.reviewerGrantOrg?requests=1&requestsTab=review');
});

it('counts applications a reviewer still has to score, grouped by round', function () {
    foreach ([1, 2] as $_) {
        $app = ProgramApplication::factory()->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $this->round->id, 'round_status' => 'submitted']);
        ReviewerApplicationAssignment::create(['round_id' => $this->round->id, 'application_id' => $app->id, 'reviewer_id' => $this->reviewerUser->id, 'status' => 'assigned']);
    }
    $res = pendingFor($this, $this->reviewerUser);
    $g = pendingGroup($res, 'review');
    expect($g['count'])->toBe(2)->and($g['items'][0]['type'])->toBe('review.to_score')->and($g['items'][0]['count'])->toBe(2)
        ->and($g['items'][0]['link'])->toContain('applicationsMultiRound')->toContain('roundId='.$this->round->id);

    ReviewerApplicationAssignment::query()->update(['status' => 'completed']);
    expect(pendingFor($this, $this->reviewerUser)->json('data.total'))->toBe(0);
});

it('tells a program owner which rounds are fully scored and which applications they must score themselves', function () {
    $this->program->update(['program_type' => 'multi_round', 'total_rounds' => 2]);
    $this->round->update(['assignment_type' => 'owner_only', 'status' => 'published']);
    $this->application->update(['round_status' => 'submitted']);
    $types = pendingTypes(pendingFor($this));
    expect($types)->toContain('owner.round_to_score')->not->toContain('owner.round_to_finalize');

    $this->application->update(['round_status' => 'scored']);
    $res = pendingFor($this);
    expect(pendingTypes($res))->toContain('owner.round_to_finalize')->not->toContain('owner.round_to_score');
    $fin = collect(pendingGroup($res, 'manage')['items'])->firstWhere('type', 'owner.round_to_finalize');
    expect($fin['link'])->toBe('dashboard.programOrg.applicationsMultiRound::id='.$this->program->id.'::roundId='.$this->round->id.'?finalize=1');
});

it('shows single-round applications waiting for the owner, but not draft or decided ones', function () {
    $this->program->update(['program_type' => 'single_round']);
    $this->application->update(['status' => 'pending']);
    expect(pendingTypes(pendingFor($this)))->toContain('owner.application');
    foreach (['approved', 'rejected', 'draft'] as $status) {
        $this->application->update(['status' => $status]);
        expect(pendingTypes(pendingFor($this)))->not->toContain('owner.application');
    }
});

it('shows milestone and funding-plan work to the right side of a program', function () {
    $this->application->update(['funding_setup_status' => 'awaiting_owner_review', 'status' => 'approved']);
    DB::table('program_milestones')->insert(['app_id' => $this->application->id, 'sequence_order' => 1, 'title' => 'Buy seed', 'amount' => 1000, 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now()]);
    expect(pendingTypes(pendingFor($this)))->toContain('owner.funding_review')->toContain('owner.mprv');
    expect(pendingTypes(pendingFor($this, $this->applicantUser)))->not->toContain('owner.mprv');

    $this->application->update(['funding_setup_status' => 'revision_requested']);
    DB::table('program_milestones')->update(['status' => 'revision_requested']);
    $mine = pendingTypes(pendingFor($this, $this->applicantUser));
    expect($mine)->toContain('applicant.funding')->toContain('applicant.milestone')->not->toContain('owner.mprv');
});

it('lists draft applications for the applicant only', function () {
    $this->application->update(['status' => 'draft']);
    expect(pendingTypes(pendingFor($this, $this->applicantUser)))->toContain('applicant.draft');
    expect(pendingTypes(pendingFor($this)))->not->toContain('applicant.draft');
});

it('lists delivered work and fee proposals for the program owner', function () {
    DB::table('reviewer_orders')->insert(['organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'program_id' => $this->program->id, 'order_type' => 'round_review', 'round_id' => $this->round->id, 'fee_amount' => 100, 'work_status' => 'delivered', 'acceptance_status' => 'accepted', 'fee_status' => 'pending_owner', 'created_at' => now(), 'updated_at' => now()]);
    $types = pendingTypes(pendingFor($this));
    expect($types)->toContain('owner.order_delivered')->toContain('owner.fee_proposal');
    DB::table('reviewer_orders')->update(['work_status' => 'modification_requested']);
    expect(pendingTypes(pendingFor($this, $this->reviewerUser)))->toContain('review.modification');
});

it('walks a service booking through provider confirmation, payment and release', function () {
    $provider = User::factory()->create(['user_type_id' => 3]);
    $customer = User::factory()->create(['user_type_id' => 2]);
    $this->createVerifiedKyc($provider, 'service_provider');
    $this->createVerifiedKyc($customer, 'entrepreneur');
    $svc = DB::table('services')->insertGetId(['user_id' => $provider->id, 'name' => 'Audit help', 'price' => 100, 'category' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    $booking = DB::table('service_bookings')->insertGetId(['date' => now()->addDay()->toDateString(), 'service_id' => $svc, 'booker_id' => $customer->id, 'service_owner_id' => $provider->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

    expect(pendingTypes(pendingFor($this, $provider)))->toContain('service.confirm')->and(pendingTypes(pendingFor($this, $customer)))->not->toContain('service.pay');

    DB::table('service_bookings')->where('id', $booking)->update(['status' => 'confirmed', 'paid' => 0]);
    expect(pendingTypes(pendingFor($this, $customer)))->toContain('service.pay')->and(pendingTypes(pendingFor($this, $provider)))->not->toContain('service.confirm');

    DB::table('service_bookings')->where('id', $booking)->update(['status' => 'paid', 'paid' => 1]);
    DB::table('service_booking_milestones')->insert(['mile_id' => 1, 'service_id' => $svc, 'booker_id' => $customer->id, 'booking_id' => $booking, 'title' => 'Phase 1', 'amount' => 50, 'status' => 'Done', 'released' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $res = pendingFor($this, $customer);
    expect(pendingTypes($res))->toContain('service.release')->not->toContain('service.pay');
    expect(collect(pendingGroup($res, 'services')['items'])->firstWhere('type', 'service.release')['link'])->toBe('dashboard.investor.bookings');
});

it('walks an investment through bid, payment and terms', function () {
    $investor = User::factory()->create(['user_type_id' => 2]);
    $this->createVerifiedKyc($investor, 'entrepreneur');
    $listing = DB::table('listings')->insertGetId(['user_id' => $this->applicantUser->id, 'name' => 'Maize Co', 'category' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    $bid = DB::table('business_bids')->insertGetId(['date' => now()->toDateString(), 'investor_id' => $investor->id, 'business_id' => $listing, 'owner_id' => $this->applicantUser->id, 'amount' => 500, 'created_at' => now(), 'updated_at' => now()]);
    expect(pendingTypes(pendingFor($this, $this->applicantUser)))->toContain('business.bid');

    DB::table('accepted_bids')->insert(['bid_id' => $bid, 'date' => now()->toDateString(), 'investor_id' => $investor->id, 'business_id' => $listing, 'owner_id' => $this->applicantUser->id, 'amount' => 500, 'status' => 'awaiting_payment', 'created_at' => now(), 'updated_at' => now()]);
    expect(pendingTypes(pendingFor($this, $this->applicantUser)))->not->toContain('business.bid');
    expect(pendingTypes(pendingFor($this, $investor)))->toContain('investor.payment');
    DB::table('accepted_bids')->update(['status' => 'confirmed']);
    expect(pendingTypes(pendingFor($this, $investor)))->not->toContain('investor.payment');
});

it('keeps working when one scenario fails', function () {
    $this->application->update(['status' => 'draft']);
    Schema::drop('service_offers');
    $res = pendingFor($this, $this->applicantUser)->assertOk();
    expect(pendingTypes($res))->toContain('applicant.draft');
});

it('caches for a few seconds and `fresh` skips the cache', function () {
    Cache::flush();
    $first = $this->actingAs($this->applicantUser, 'sanctum')->getJson('/api/v1/pending-tasks')->json('data.total');
    $this->application->update(['status' => 'draft']);
    $cached = $this->actingAs($this->applicantUser, 'sanctum')->getJson('/api/v1/pending-tasks')->json('data.total');
    $fresh = $this->actingAs($this->applicantUser, 'sanctum')->getJson('/api/v1/pending-tasks?fresh=1')->json('data.total');
    expect($cached)->toBe($first)->and($fresh)->toBe($first + 1);
});

it('never shows one person another person\'s tasks', function () {
    $this->application->update(['status' => 'draft']);
    $other = User::factory()->create(['user_type_id' => 1]);
    $this->createVerifiedKyc($other, 'entrepreneur');
    expect(pendingFor($this, $other)->json('data.total'))->toBe(0);
});
