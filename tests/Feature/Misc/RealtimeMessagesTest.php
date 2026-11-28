<?php

use App\Events\ChatNotification;
use App\Events\ChatSignal;
use App\Models\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

// Messages are live on a private channel per person: new message, the other person typing, the other person read them.
beforeEach(function () {
    $this->amina = User::factory()->create(['user_type_id' => 1, 'first_name' => 'Amina', 'last_name' => 'Otieno']);
    $this->brian = User::factory()->create(['user_type_id' => 2]);
});

it('lets only the owner of a chat channel listen', function () {
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher.key' => 'k', 'broadcasting.connections.pusher.secret' => 's', 'broadcasting.connections.pusher.app_id' => '1']);
    \Illuminate\Support\Facades\Broadcast::forgetDrivers();
    require base_path('routes/channels.php'); // the rules belong to the broadcaster in use
    $auth = fn (User $u, string $channel) => $this->actingAs($u, 'sanctum')->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);

    $auth($this->amina, 'private-chat.'.$this->amina->id)->assertOk();
    $auth($this->brian, 'private-chat.'.$this->amina->id)->assertForbidden();
    $auth($this->brian, 'private-notifications.'.$this->amina->id)->assertForbidden();
});

it('tells the other person, live, that you are typing', function () {
    Event::fake([ChatSignal::class]);
    $this->actingAs($this->amina, 'sanctum')->postJson('/api/v1/messages/typing', ['to_id' => $this->brian->id])->assertOk();

    Event::assertDispatched(ChatSignal::class, fn ($e) => $e->kind === 'Typing' && $e->toId === $this->brian->id
        && $e->data['from_id'] === $this->amina->id && $e->data['name'] === 'Amina Otieno'
        && $e->broadcastOn()->name === 'private-chat.'.$this->brian->id);
});

it('tells the writer, live, when their messages were read', function () {
    Event::fake([ChatSignal::class]);
    DB::table('messages')->insert(['from_id' => $this->brian->id, 'to_id' => $this->amina->id, 'msg' => 'Hello', 'is_new' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->amina, 'sanctum')->postJson('/api/v1/messages/mark-read', ['partner_id' => $this->brian->id])->assertOk();
    Event::assertDispatched(ChatSignal::class, fn ($e) => $e->kind === 'Read' && $e->toId === $this->brian->id && $e->data['by_id'] === $this->amina->id);

    // nothing new to read: no signal
    Event::fake([ChatSignal::class]);
    $this->actingAs($this->amina, 'sanctum')->postJson('/api/v1/messages/mark-read', ['partner_id' => $this->brian->id])->assertOk();
    Event::assertNotDispatched(ChatSignal::class);
});

it('sends a new message to the other person\'s private channel with who wrote it', function () {
    Event::fake([ChatNotification::class]);
    $this->actingAs($this->amina, 'sanctum')->postJson('/api/v1/messages', ['to_id' => $this->brian->id, 'msg' => 'Hello Brian, can we talk?']);

    Event::assertDispatched(ChatNotification::class, fn ($e) => $e->broadcastOn()->name === 'private-chat.'.$this->brian->id
        && ($e->payload['data']['from_id'] ?? null) === $this->amina->id);
});
