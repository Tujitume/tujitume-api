<?php
namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Services\ServiceBook;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use App\Service\Misc\ErrorLogService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index()
    {
        return response()->json(['message' => 'Booking endpoint']);
    }

    public function myBooking()
    {
        $bookings = ServiceBook::where('booker_id', Auth::id())->with('service')->get();

        $results = $bookings->filter(fn($b) => $b->service)->map(function ($book) {
            $service = $book->service;
            $book->location    = $service->location;
            $book->service     = $service->name;
            $book->category    = $service->category;
            $book->service_fee = $service->price;
            $book->deadline    = str_replace('before', '', now()->diffForHumans(
                Carbon::parse($book->date)->startOfDay(),
                ['parts' => 2, 'short' => false, 'absolute' => true]
            ));
            return $book;
        })->values();

        return response()->json(['results' => $results]);
    }

    public function serviceBooking()
    {
        $bookings = ServiceBook::where('service_owner_id', Auth::id())
            ->where('status', 'Pending')
            ->with(['service', 'booker'])
            ->latest()->get();

        $results = $bookings->filter(fn($b) => $b->service && $b->booker)->map(function ($book) {
            $service  = $book->service;
            $customer = $book->booker;

            $book->location       = $service->location;
            $book->service        = $service->name;
            $book->category       = $service->category;
            $book->customer_name  = $customer->fname . ' ' . $customer->lname;
            $book->website        = $customer->website;
            $book->email          = $customer->email;
            $book->deadline       = str_replace('before', '', now()->diffForHumans(
                Carbon::parse($book->date)->startOfDay(),
                ['parts' => 2, 'short' => false, 'absolute' => true]
            ));
            return $book;
        })->values();

        ServiceBook::where('service_owner_id', Auth::id())->update(['new' => 0]);

        return response()->json(['results' => $results]);
    }

    // S T O R E
    public function serviceBook(Request $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'service_id'      => 'required|integer|exists:services,id',
                'date'            => 'required|date',
                'note'            => 'nullable|string|max:1000',
                'business_bid_id' => 'nullable|integer',
            ]);

            $booker  = Auth::user();
            $service = Services::findOrFail($validated['service_id']);

            if (ServiceBook::where('service_id', $validated['service_id'])->where('booker_id', $booker->id)->exists()) {
                return response()->json(['message' => 'You already have an active booking for this service.'], 409);
            }

            // Clean up orphan milestones
            ServiceBookingMilestone::where('service_id', $validated['service_id'])
                ->where('booker_id', $booker->id)->delete();

            $booking = ServiceBook::create([
                ...$validated,
                'date'             => Carbon::parse($validated['date'])->format('Y-m-d'),
                'booker_id'        => $booker->id,
                'service_owner_id' => $service->user_id,
                'status'           => 'pending',
            ]);

            DB::commit();

            $acceptDeadline = str_replace('before', '', now()->diffForHumans(
                Carbon::parse($booking->date)->startOfDay(),
                ['parts' => 2, 'short' => false, 'absolute' => true]
            ));

            $this->notification->create(
                $service->user_id, $booker->id,
                'You have a new booking from ' . $booker->fname . ' ' . $booker->lname,
                'dashboard.serviceProvider.myBookings::' . $booking->id, 'customer'
            );

            $info = ['business_name' => $service->name, 'accept_deadline' => $acceptDeadline];
            $this->emailService->send('Booking Under Review', 'services.under_review', $info, $booker->email);
            $this->emailService->send('New Booking', 'services.new_booking', $info, $service->owner->email);

            return response()->json(['message' => 'Booking successful! Check your dashboard for status.'], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    // Accept - Reject POST
    public function accept(Request $request)
    {
        $request->validate(['bid_ids' => 'required|array']);

        try {
            foreach ($request->bid_ids as $id) {
                $booking = ServiceBook::find($id);
                if (!$booking) continue;

                $investor = User::findOrFail($booking->booker_id);
                $service = $booking->service;

                // Payment deadline check
                $bookingDateStart = Carbon::parse($booking->date)->startOfDay();
                if (now()->greaterThan($bookingDateStart)) {
                    return response()->json(['message' => 'Cannot accept booking: Booking expired!'], 400);
                }
                $paymentDeadline = str_replace('before', '', now()->diffForHumans($bookingDateStart, ['parts'=>2,'short'=>false,'absolute'=>true]));

                // Replicate milestones
                $active = 1;
                foreach (Smilestones::where('listing_id', $service->id)->get() as $mile) {
                    $deadline = Carbon::parse($booking->date)->addDays($mile->n_o_days);
                    ServiceBookingMilestone::create([
                        'mile_id'    => $mile->id,
                        'service_id' => $service->id,
                        'booker_id'  => $booking->booker_id,
                        'booking_id' => $booking->id,
                        'title'      => $mile->title,
                        'amount'     => $mile->amount,
                        'document'   => $mile->document,
                        'active'     => $active,
                        'status'     => 'To Do',
                        'created_at' => $mile->created_at,
                        'n_o_days'   => $mile->n_o_days,
                        'deadline'   => $deadline
                    ]);
                    $active = 0;
                }

                $booking->update(['status' => 'confirmed']);

                // Notifications & Email
                $text = "Your booking to {$service->name} has been accepted!";
                $this->notification->create($booking->booker_id, $service->id, $text, 'dashboard.entrepreneur.mybookings::' . $booking->id, 'service');

                $this->emailService->send(
                    'Booking Accepted', 'services.booking_mail',
                    [
                        'business_name'    => $service->name,
                        'id'               => $booking->id,
                        'date'             => $booking->date,
                        's_id'             => base64_encode(base64_encode($service->id)),
                        'booking_id'       => base64_encode($booking->id),
                        'amount'           => $service->price,
                        'reason'           => 0,
                        'payment_deadline' => $paymentDeadline
                    ], $investor->email
                );
            }

            return response()->json(['message' => 'Success'], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password','token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function reject(Request $request)
    {
        $request->validate(['bid_ids' => 'required|array']);

        try {
            foreach ($request->bid_ids as $id) {
                $booking = ServiceBook::find($id);
                if (!$booking) continue;

                $investor = User::findOrFail($booking->booker_id);
                $service = Services::findOrFail($booking->service_id);
                $reason = 'Unknown Reason';

                // Delete booking
                $booking->delete();

                // Notifications & Email
                $text = "Your booking to {$service->name} has been rejected due to {$reason}";
                $this->notification->create($booking->booker_id, $service->id, $text, 'dashboard.entrepreneur.mybookings::' . $booking->id, 'service');

                $this->emailService->send(
                    'Booking Rejected',
                    'services.booking_mail',
                    [
                        'business_name' => $service->name,
                        's_id'          => base64_encode(base64_encode($service->id)),
                        'reason'        => $reason,
                        'id'            => $booking->id,
                        'date'          => $booking->date
                    ], $investor->email
                );
            }

            return response()->json(['status' => 200, 'message' => 'Rejected, Success!']);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password','token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function rebookService(int $id)
    {
        try {
            ServiceBook::where('service_id', $id)->where('booker_id', Auth::id())->delete();
            ServiceBookingMilestone::where('service_id', $id)->where('booker_id', Auth::id())->delete();

            return response()->json(['message' => 'Booking reset successfully.'], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['service_id' => $id]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function CancelServiceBooking($booking_id, $action)
    {
        $booking_id = base64_decode($booking_id);

        try {
            $booking = ServiceBook::findOrFail($booking_id);

            $booker = $booking->customer; $owner = $booking->service->owner;

            $serviceName = $booking->service->name;
            $bookerName = $booker->fname.' '.$booker->lname;
            $s_id = base64_encode(base64_encode($booking->service->id));

            if ($action === 'confirm') {
                $this->emailService->send(
                    'Booking Cancel Confirmation',
                    'services.booking_cancel_confirm',
                    ['business_name'=>$serviceName,'booking_id'=>base64_encode($booking_id),'s_id'=>$s_id],
                    $booker->email
                );

                $this->notification->createWithBidId(
                    $booking->booker_id,
                    $owner->id,
                    $booking_id,
                    'Your booking to Service '.$serviceName.' will be cancelled.',
                    'booking_cancel_confirm',
                    'service'
                );

            } else {
                $this->emailService->send(
                    'Booking Cancelled',
                    'services.booking_cancelled',
                    ['investor'=>$bookerName,'business_name'=>$serviceName],
                    $owner->email
                );

                $this->notification->create(
                    $owner->id, $booker->id,
                    'A booking to Service '.$serviceName.' was cancelled by '.$bookerName,
                    'dashboard.serviceProvider.myBookings::' . $booking->id, 'service'
                );

                $this->notification->create(
                    $booker->id, $owner->id,
                    'Your booking to Service '.$serviceName.' was cancelled.',
                    'dashboard.entrepreneur.mybookings::' . $booking->id, 'service'
                );

                $booking->delete();

                if ($action === 'ok_response') {
                    return response()->json(['status'=>200,'message'=>'Booking Cancelled!'],200);
                }
            }

            return redirect()->to(config('app.app_url').'dashboard')->send();

        } catch (\Exception $e) {
            return redirect()->to(config('app.app_url').'dashboard')->send();
        }
    }

    public function getBookers(int $serviceId)
    {
        $bookers = ServiceBook::where('service_id', $serviceId)
            ->where('status', 'confirmed')
            ->with('booker')
            ->get()
            ->filter(fn($b) => $b->booker)
            ->map(function ($b) {
                $b->booker->name = $b->booker->fname . ' ' . $b->booker->lname;
                return $b->booker;
            })->values();

        return response()->json(['data' => $bookers]);
    }

}
