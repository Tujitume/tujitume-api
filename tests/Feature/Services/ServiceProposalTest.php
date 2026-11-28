<?php

use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Services\ServiceProposal;
use App\Models\Services\Services;
use Illuminate\Support\Facades\DB;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// A service provider shares a service with a business (or a program / capital offer); its owner accepts or declines.
beforeEach(function () {
    $this->provider = User::factory()->create(['user_type_id' => 3, 'first_name' => 'Amina', 'last_name' => 'Otieno']);
    $this->createVerifiedKyc($this->provider, 'service_provider');
    $this->owner = User::factory()->create(['user_type_id' => 1]);
    $this->listing = Listing::query()->forceCreate([
        'user_id' => $this->owner->id, 'name' => 'Maji Safi Kiosks', 'category' => 'Water', 'details' => 'Clean water',
        'location' => 'Nairobi', 'investment_needed' => 100000, 'amount_collected' => 0, 'share' => 10, 'active' => 1,
        'id_no' => '12345678', 'tax_pin' => 'A001', 'identification' => 'ids/secret.pdf',
    ]);
    $this->service = Services::query()->forceCreate([
        'user_id' => $this->provider->id, 'name' => 'Bookkeeping', 'price' => 5000, 'category' => 'Finance', 'active' => 1,
    ]);
    $this->send = fn (array $extra = []) => $this->actingAs($this->provider, 'sanctum')->postJson('/api/v1/services/proposals', array_merge([
        'service_id' => $this->service->id, 'target_type' => 'listing', 'target_id' => $this->listing->id,
        'message' => 'I can keep your books every month and prepare reports for investors.', 'proposed_price' => 4500,
    ], $extra));
});

it('sends a request to the business owner, once, and tells them', function () {
    ($this->send)()->assertCreated()->assertJsonPath('data.target.name', 'Maji Safi Kiosks')->assertJsonPath('data.service.name', 'Bookkeeping');
    expect(ServiceProposal::first()->recipient_id)->toBe($this->owner->id)
        ->and(DB::table('notifications')->where('receiver_id', $this->owner->id)->value('link'))->toStartWith('dashboard.entrepreneur.serviceRequests?request=@');

    ($this->send)()->assertStatus(409); // one open request per service and business
});

it('refuses a service that is not yours, a non-provider, and a request to yourself', function () {
    $other = Services::query()->forceCreate(['user_id' => $this->owner->id, 'name' => 'Theirs', 'price' => 10, 'active' => 1]);
    ($this->send)(['service_id' => $other->id])->assertStatus(422);
    $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/services/proposals', [
        'service_id' => $this->service->id, 'target_type' => 'listing', 'target_id' => $this->listing->id, 'message' => 'Not a provider at all here',
    ])->assertForbidden();
    $this->service->update(['active' => 0]);
    ($this->send)()->assertStatus(422);
});

it('lets only the owner answer, and tells the provider', function () {
    $id = ($this->send)()->json('data.id');

    $stranger = User::factory()->create(['user_type_id' => 1]);
    $this->actingAs($stranger, 'sanctum')->postJson("/api/v1/services/proposals/{$id}/decide", ['decision' => 'accept'])->assertForbidden();
    $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/services/proposals/{$id}")->assertNotFound();

    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/services/proposals/received?page=1&per_page=10')->assertOk()
        ->assertJsonPath('data.items.0.provider.name', 'Amina Otieno')->assertJsonPath('data.stats.pending', 1);
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/services/proposals/{$id}/decide", ['decision' => 'decline', 'note' => 'Not now'])
        ->assertOk()->assertJsonPath('data.status', 'declined');
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/services/proposals/{$id}/decide", ['decision' => 'accept'])->assertStatus(409);

    expect(DB::table('notifications')->where('receiver_id', $this->provider->id)->value('text'))->toContain('declined')
        ->and($this->actingAs($this->provider, 'sanctum')->getJson('/api/v1/services/proposals/sent')->json('data.items.0.decision_note'))->toBe('Not now');
});

it('shows the request in the owner\'s Pending requests', function () {
    ($this->send)();
    $groups = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/pending-tasks')->json('groups') ?? $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/pending-tasks')->json('data.groups');
    $items = collect($groups)->firstWhere('key', 'services')['items'] ?? [];
    expect(collect($items)->pluck('type')->all())->toContain('service.request');
});

it('never shows the owner\'s identity papers to other people', function () {
    $this->actingAs($this->provider, 'sanctum')->getJson("/api/v1/listing_auth/{$this->listing->id}")->assertOk()
        ->assertJsonMissingPath('data.id_no')->assertJsonMissingPath('data.tax_pin')->assertJsonMissingPath('data.identification');
    $this->getJson("/api/v1/listing/{$this->listing->id}")->assertOk()->assertJsonMissingPath('data.id_no');
    $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/listing_auth/{$this->listing->id}")->assertOk()->assertJsonPath('data.id_no', '12345678');
});

// ISSUE-43: a program created by a team member belongs to the organization; its owner and program team see and answer it
it('sends a request about a team member\'s program to the organization, and its team answers it', function () {
    $admin = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
    DB::table('roles')->insertOrIgnore(['id' => 10001, 'name' => 'super_admin', 'access_types' => '[]']);
    \App\Models\Auth\OrganizationUserRole::create(['user_id' => $admin->id, 'organization_id' => $this->organization->id, 'role_id' => 10001]);
    $program = \App\Models\Programs\Program::factory()->create(['user_id' => $admin->id, 'organization_id' => $this->organization->id, 'status' => 'published', 'visible' => 1]);

    $id = ($this->send)(['target_type' => 'program', 'target_id' => $program->id])->assertCreated()->json('data.id');
    expect(ServiceProposal::find($id)->recipient_id)->toBe($this->orgUser->id);

    // the creator (a team member) sees it and can answer; a stranger organization cannot
    $this->actingAs($admin, 'sanctum')->getJson('/api/v1/services/proposals/received')->assertOk()->assertJsonPath('data.stats.pending', 1);
    $stranger = User::factory()->create(['user_type_id' => 4]);
    $this->actingAs($stranger, 'sanctum')->postJson("/api/v1/services/proposals/{$id}/decide", ['decision' => 'accept'])->assertForbidden();
    $this->actingAs($admin, 'sanctum')->postJson("/api/v1/services/proposals/{$id}/decide", ['decision' => 'accept'])->assertOk()->assertJsonPath('data.status', 'accepted');
});
