<?php
namespace App\Service\Notification;
use App\Events\NewNotification;
use App\Models\Communication\Notifications;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function create($receiver_id,$customer_id,$text,$link,$type)
    {
        return $this->store($receiver_id, $customer_id, null, $text, $link, $type);
    }

    public function createWithBidId($receiver_id,$customer_id,$bid_id,$text,$link,$type)
    {
        return $this->store($receiver_id, $customer_id, $bid_id, $text, $link, $type);
    }

    /**
     * Save the notification and push it to the receiver in real time.
     * The live payload carries the stored row's id, so the app can mark it read /
     * remove it straight away and never shows it twice after a refresh.
     */
    private function store($receiver_id, $customer_id, $bid_id, $text, $link, $type)
    {
        $now=date("Y-m-d H:i"); $date=date('d M, h:i a',strtotime($now));

        $attributes = [
            'date' => $date,
            'receiver_id' => $receiver_id,
            'customer_id' => $customer_id,
            'text' => $text,
            'link' => $link,
            'type' => $type,
        ];
        if ($bid_id !== null) $attributes['bid_id'] = $bid_id;

        $notification = Notifications::create($attributes);

        // Dispatch real-time event
        try{
            event(new NewNotification([
                'id' => $notification->id,
                'text' => $text,
                'link' => $link,
                'type' => $type,
                'date' => $date,
                'bid_id' => $bid_id,
                'customer_id' => $customer_id,
                'new' => 1,
                'created_at' => $notification->created_at?->toIso8601String(),
            ], $receiver_id));
        } catch (\Throwable $e) {
            Log::error("Broadcast failed: " . $e->getMessage());
        }

        return $notification;
    }
}
