<?php

namespace App\Console\Commands;

use App\Models\Services\ServiceBook;
use App\Service\Notification\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ServiceCronActions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'run:service-cron-actions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This will automate the service actions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        //$emailService = new EmailService();
        $notification = new NotificationService();

        ServiceBook::whereIn('status', ['pending', 'confirmed'])
            ->chunk(100, function ($pendingBookings) use ($notification) {
                foreach ($pendingBookings as $booking) {
                    $service = $booking->service;
                    $user = $booking->customer;
                    $bookedFor = Carbon::parse($booking->date)->startOfDay();

                    if ($booking->status == 'pending' && now()->greaterThan($bookedFor)) {
                        // Cancel the booking
                        $notification->create(
                            $service->user_id,null, "Your booking for {$service->name} has been cancelled due to no confirmation within booking date.", "mybookings", "booking",
                        );

                        $booking->delete();

                    } elseif ($booking->status === 'confirmed' && now()->greaterThan($bookedFor)) {
                        if($booking->paid){
                            continue;
                        }

                        $notification->create(
                            $user->id,null, "Your booking for {$service->name} has been cancelled due to non-payment within booking date.", "mybookings", "booking",
                        );

                        $booking->delete();
                    }
                }
            });

        // Setting paid ones in progress
        $paidBookings = ServiceBook::where('status', 'paid')->orWhere('paid', 1)->get();

        foreach ($paidBookings as $booking) {
            $service = $booking->service;
            $bookedFor = Carbon::parse($booking->date)->startOfDay();

            if (now()->greaterThan($bookedFor) && $booking->status != 'in_progress') {
                $booking->status = 'in_progress';
                $booking->save();

                $milestoneInstance = $booking->milestoneInstances->first();
                if($milestoneInstance){
                    $milestoneInstance->update(['status' => 'In Progress']);
                }

            }
        }

    }
}
