<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A live signal in a conversation, sent straight away (not queued) to the other person's private chat channel:
 *   Typing  {from_id, name}  the other person is writing
 *   Read    {by_id}          the other person read your messages
 */
class ChatSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $kind, public int $toId, public array $data) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.'.$this->toId);
    }

    public function broadcastAs(): string
    {
        return $this->kind;
    }

    public function broadcastWith(): array
    {
        return $this->data;
    }
}
