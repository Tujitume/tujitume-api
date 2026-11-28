<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * ISSUE-42: a step's Conversation (MPRV / mid-milestone / final approval agreement) changed: a message, an approval or a
 * rejection. Sent live to everyone who may open that milestone (routes/channels.php: step-agreement.{milestoneId}), so the
 * open drawer refreshes at once instead of polling.
 */
class StepAgreementUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $milestoneId, public string $type, public string $action, public ?int $authorId) {}

    public function broadcastAs(): string
    {
        return 'StepAgreementUpdated';
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('step-agreement.'.$this->milestoneId);
    }

    public function broadcastWith(): array
    {
        return ['milestone_id' => $this->milestoneId, 'type' => $this->type, 'action' => $this->action, 'author_id' => $this->authorId, 'at' => now()->toIso8601String()];
    }
}
