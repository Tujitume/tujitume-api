<?php

use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Capital\CapitalProfile;
use App\Models\Communication\Messages;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// A capital organization's editors and admins share the owner's inbox: store writes as the owner (sent_by_id keeps the
// real writer), and index/thread/mark-read read the same "acting id", so the member sees what they and their
// colleagues sent and the owner sees everything with the real author named.
describe('Shared organization inbox', function () {
    beforeEach(function () {
        $this->capitalOwner = User::factory()->create(['user_type_id' => 5, 'first_name' => 'Olivia', 'last_name' => 'Owner']);
        CapitalProfile::create(['user_id' => $this->capitalOwner->id]);
        $this->member = User::factory()->create(['user_type_id' => 5, 'first_name' => 'Eddie', 'last_name' => 'Editor', 'display_name' => null]);
        $role = Role::firstOrCreate(['name' => 'editor'], ['access_types' => []]);
        CapitalProfile::create(['user_id' => $this->member->id, 'role_id' => $role->id, 'capital_owner_id' => $this->capitalOwner->id]);
        $this->outsider = User::factory()->create(['user_type_id' => 1]);
    });

    $conversation = fn ($response, int $partnerId) => collect($response->json('messages'))->firstWhere('id', $partnerId);

    it('lets a member see what they sent, under the organization, with their name on it', function () use ($conversation) {
        $this->actingAs($this->member, 'sanctum')->postJson('/api/v1/messages', ['msg' => 'Hello from the team', 'to_id' => $this->applicantUser->id])->assertOk();
        $stored = Messages::where('msg', 'Hello from the team')->first();
        expect($stored->from_id)->toBe($this->capitalOwner->id)->and($stored->sent_by_id)->toBe($this->member->id);

        $chat = $conversation($this->actingAs($this->member, 'sanctum')->getJson('/api/v1/messages')->assertOk(), $this->applicantUser->id);
        expect($chat)->not->toBeNull()
            ->and($chat['messages'][0]['sender'])->toBe('me')
            ->and($chat['messages'][0]['author']['is_me'])->toBeTrue()
            ->and($chat['messages'][0]['author']['name'])->toBe('Eddie Editor');

        $this->actingAs($this->member, 'sanctum')->getJson("/api/v1/messages/thread/{$this->applicantUser->id}")
            ->assertOk()->assertJsonPath('messages.0.msg', 'Hello from the team');
    });

    it('shows the owner everything in the shared inbox with the real author named', function () use ($conversation) {
        $this->actingAs($this->member, 'sanctum')->postJson('/api/v1/messages', ['msg' => 'Team message', 'to_id' => $this->applicantUser->id])->assertOk();
        $this->actingAs($this->capitalOwner, 'sanctum')->postJson('/api/v1/messages', ['msg' => 'Owner message', 'to_id' => $this->applicantUser->id])->assertOk();
        $this->actingAs($this->applicantUser, 'sanctum')->postJson('/api/v1/messages', ['msg' => 'Reply to the member', 'to_id' => $this->member->id])->assertOk();

        $messages = collect($conversation($this->actingAs($this->capitalOwner, 'sanctum')->getJson('/api/v1/messages')->assertOk(), $this->applicantUser->id)['messages']);
        expect($messages->pluck('msg')->all())->toContain('Team message', 'Owner message', 'Reply to the member')
            ->and($messages->firstWhere('msg', 'Team message')['author'])->toMatchArray(['name' => 'Eddie Editor', 'is_me' => false])
            ->and($messages->firstWhere('msg', 'Team message')['sender'])->toBe('me')
            ->and($messages->firstWhere('msg', 'Reply to the member')['sender'])->toBe('');

        // the member reads the same conversation, the owner's lines included, and its unread reply
        $memberChat = $conversation($this->actingAs($this->member, 'sanctum')->getJson('/api/v1/messages'), $this->applicantUser->id);
        expect(collect($memberChat['messages'])->pluck('msg')->all())->toContain('Owner message', 'Reply to the member')
            ->and($memberChat['unread_count'])->toBe(1);
        $this->actingAs($this->member, 'sanctum')->postJson('/api/v1/messages/mark-read', ['partner_id' => $this->applicantUser->id])->assertOk();
        expect($conversation($this->actingAs($this->capitalOwner, 'sanctum')->getJson('/api/v1/messages'), $this->applicantUser->id)['unread_count'])->toBe(0);
    });

    it('refuses with a clear 422 when a member opens a chat with their own organization owner', function () {
        $this->actingAs($this->member, 'sanctum')->getJson("/api/v1/messages/thread/{$this->capitalOwner->id}")
            ->assertStatus(422)->assertJsonPath('message', 'You share this inbox with your organization owner, so you cannot open a chat with them here.');
        $this->actingAs($this->member, 'sanctum')->postJson('/api/v1/messages', ['msg' => 'Hi boss', 'to_id' => $this->capitalOwner->id])
            ->assertStatus(422);
        expect(Messages::where('msg', 'Hi boss')->exists())->toBeFalse();
    });

    it('keeps the organization conversations away from outsiders', function () use ($conversation) {
        $this->actingAs($this->member, 'sanctum')->postJson('/api/v1/messages', ['msg' => 'Private to applicant', 'to_id' => $this->applicantUser->id])->assertOk();

        $this->actingAs($this->outsider, 'sanctum')->getJson('/api/v1/messages')->assertOk()->assertJsonPath('messages', []);
        $this->actingAs($this->outsider, 'sanctum')->getJson("/api/v1/messages/thread/{$this->applicantUser->id}")->assertOk()->assertJsonPath('messages', []);
        $this->actingAs($this->outsider, 'sanctum')->getJson("/api/v1/messages/thread/{$this->capitalOwner->id}")->assertOk()->assertJsonPath('messages', []);
    });

    it('lets the applicant see the organization as one conversation, with the member named', function () use ($conversation) {
        $this->actingAs($this->member, 'sanctum')->postJson('/api/v1/messages', ['msg' => 'We liked your pitch', 'to_id' => $this->applicantUser->id])->assertOk();
        $chat = $conversation($this->actingAs($this->applicantUser, 'sanctum')->getJson('/api/v1/messages')->assertOk(), $this->capitalOwner->id);
        expect($chat['messages'][0]['author'])->toMatchArray(['name' => 'Eddie Editor', 'is_me' => false]);
    });
});
