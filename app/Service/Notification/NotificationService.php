<?php
namespace App\Service\Notification;
use App\Events\NewNotification;
use App\Models\Communication\Notifications;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function create($receiver_id,$customer_id,$text,$link,$type)
    {
        $now=date("Y-m-d H:i"); $date=date('d M, h:i a',strtotime($now));
        $addNoti = Notifications::create([
            'date' => $date,
            'receiver_id' => $receiver_id,
            'customer_id' => $customer_id,
            'text' => $text,
            'link' => $link,
            'type' => $type,
        ]);

        // Dispatch real-time event
        try{
            event(new NewNotification([
                'text' => $text,
                'link' => $link,
                'type' => $type,
                'date' => $date,
                'customer_id' => $customer_id,
            ], $receiver_id));
        } catch (\Throwable $e) {
            Log::error("Broadcast failed: " . $e->getMessage());
                // You could also silently ignore without logging if you prefer
        }
    }

    public function createWithBidId($receiver_id,$customer_id,$bid_id,$text,$link,$type)
    {
        $now=date("Y-m-d H:i"); $date=date('d M, h:i a',strtotime($now));
        $addNoti = Notifications::create([
            'date' => $date,
            'receiver_id' => $receiver_id,
            'customer_id' => $customer_id,
            'bid_id' => $bid_id,
            'text' => $text,
            'link' => $link,
            'type' => $type,
        ]);

        // Dispatch real-time event
        event(new NewNotification([
            'text' => $text,
            'link' => $link,
            'type' => $type,
            'date' => $date,
            'bid_id' => $bid_id,
            'customer_id' => $customer_id,
        ], $receiver_id));
    }
}
