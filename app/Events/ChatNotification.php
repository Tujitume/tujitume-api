<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ChatNotification implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $payload;
    //public string $signature;
    public array $data;
    public int $userId;

    public function __construct(array $data, int $userId)
    {
        $this->data = $data;
        $this->userId = $userId;

        $this->payload = [
            'user_id' => $this->userId,
            'data'    => $this->data,
            'ts'      => now()->timestamp,
        ];

//        $this->signature = hash_hmac(
//            'sha256',
//            json_encode($this->payload),
//            config('services.pusher.app_id')
//        );

    }

    public function broadcastAs()
    {
        return 'NewMessage';
    }

    public function broadcastOn()
    {
        // Private: only the person the message is for can listen (routes/channels.php)
        return new \Illuminate\Broadcasting\PrivateChannel('chat.'.$this->payload['user_id']);
    }

    public function broadcastWith(): array
    {
        return ['payload' => $this->payload];
    }

}
