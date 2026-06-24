<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NewNotification implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $data;
    public int $userId;

    public function __construct(array $data, int $userId)
    {


        $this->data = $data;
        $this->userId = $userId;
        Log::info('ChatNotification called');
    }

    public function broadcastAs()
    {
        return 'NewNotification';
    }

    public function broadcastOn()
    {
        Log::info('ChatNotification broadcastOn called');
        return new Channel('notifications.'.$this->userId);
    }

//    public function broadcastWith(): array
//    {
//        // Make sure it is a plain array
//        return $this->data;
//    }
}
