<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ChatNotification implements ShouldBroadcast
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
        Log::info('🟡 ChatNotification constructed');

    }

    public function broadcastAs()
    {
        return 'NewMessage';
    }

    public function broadcastOn()
    {
        Log::info('🟡 ChatNotification broadcastOn');
        return new Channel(
            'msg-notification.' . $this->payload['user_id']
        );
    }

//    public function broadcastWith()
//    {
//        Log::info('🟡 ChatNotification broadcastWith');
//        return [
//            'payload'   => $this->payload,
//            'signature' => $this->signature,
//        ];
//    }

}
