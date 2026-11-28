<?php
namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use App\Service\Misc\ErrorLogService;
use App\Service\Services\ServiceBookingFlow;
use App\Service\Services\ServiceLists;
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

    // GET /services/my_booking — the person's own bookings. With page / per_page / search / filters / sort / export it is
    // the My Bookings table (ServiceLists); without them the old full list under `results`.
    public function myBooking(Request $request)
    {
        $lists = new ServiceLists((int) Auth::id());
        if (ServiceLists::wanted($request)) {
            return $lists->myBookings($request);
        }

        $results = $lists->customerBase()->with('service')->get()
            ->map(fn ($book) => $lists->customerRow($book))->values();

        return response()->json(['results' => $results]);
    }

    // GET /services/service_booking — ?service_id=: every booking of that service (its customers page); without it, the
    // inbox of pending requests. With list parameters (page, search, filters, sort, export) it is a server-paged table.
    public function serviceBooking(Request $request)
    {
        $serviceId = $request->integer('service_id') ?: null;
        $lists = new ServiceLists((int) Auth::id());

        if (ServiceLists::wanted($request)) {
            $response = $lists->providerBookings($request, $serviceId);
        } else {
            $results = $lists->providerBase($serviceId)->with(['service', 'booker:id,first_name,last_name,email,website,image,user_type_id'])
                ->latest()->get()
                ->map(fn ($book) => $lists->providerRow($book))->values();
            $response = response()->json(['results' => $results]);
        }

        if (! $serviceId) {
            ServiceBooking::where('service_owner_id', Auth::id())->update(['new' => 0]);
        }

        return $response;
    }

    // S T O R E
    public function serviceBook(Request $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'service_id'      => 'required|integer|exists:services,id',
                // from tomorrow: the provider accepts and the customer pays before the start day, so a booking
                // for today (or earlier) was born expired and could never be accepted
                'date'            => 'required|date|after:today',
                'note'            => 'nullable|string|max:1000',
                'business_bid_id' => 'nullable|integer',
            ]);

            $booker  = Auth::user();
            $service = Services::findOrFail($validated['service_id']);

            // A rejected or cancelled booking does not stop booking the service again
            if (ServiceBooking::where('service_id', $validated['service_id'])->where('booker_id', $booker->id)->whereNotIn('status', ['rejected', 'cancelled'])->exists()) {
                return response()->json(['message' => 'You already have an active booking for this service.'], 409);
            }

            // Clean up orphan milestones
            ServiceBookingMilestone::where('service_id', $validated['service_id'])
                ->where('booker_id', $booker->id)->delete();

            $booking = ServiceBooking::create([
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
                'You have a new booking from ' . $booker->first_name . ' ' . $booker->last_name,
                // The provider answers it on Service bookings (My bookings is what they booked themselves)
                'dashboard.serviceProvider.serviceBookings', 'customer'
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
                $booking = ServiceBooking::find($id);
                // Only the service's own provider accepts, and a booking is accepted once (its milestones are made here)
                if (!$booking || (int) $booking->service_owner_id !== (int) Auth::id() || $booking->status !== 'pending') continue;

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
                // Milestones run one after the other: each is due its days after the previous one's due date
                $due = Carbon::parse($booking->date)->startOfDay();
                foreach (Smilestones::where('listing_id', $service->id)->orderBy('id')->get() as $mile) {
                    $due = $due->copy()->addDays((int) $mile->n_o_days);
                    $deadline = $due;
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
                $text = "Your booking to {$service->name} has been accepted! Pay for it to start the work.";
                $this->notification->create($booking->booker_id, $service->user_id, $text, ServiceBookingFlow::customerLinkKey($booking), 'service'); // from the provider (not the service id)

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
                        'payment_deadline' => $paymentDeadline,
                        'link'             => ServiceBookingFlow::customerLink($booking),
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
        $request->validate(['bid_ids' => 'required|array', 'reason' => 'nullable|string|max:500']);

        try {
            foreach ($request->bid_ids as $id) {
                $booking = ServiceBooking::find($id);
                // Only the service's own provider rejects, and only a booking not yet accepted
                if (!$booking || (int) $booking->service_owner_id !== (int) Auth::id() || $booking->status !== 'pending') continue;

                $investor = User::findOrFail($booking->booker_id);
                $service = Services::findOrFail($booking->service_id);
                $reason = trim((string) $request->input('reason', '')) ?: null;

                // Kept as rejected (with the reason), so it stays in the provider's All tab and the customer's list
                $booking->update(['status' => 'rejected', 'stage' => 'Rejected', 'rejection_note' => $reason]);

                // Notifications & Email
                $text = $reason
                    ? "Your booking to {$service->name} was declined: {$reason}"
                    : "Your booking to {$service->name} was declined by the provider.";
                $this->notification->create($booking->booker_id, $service->user_id, $text, $this->bookerBookingsLink($booking->booker_id), 'service');

                $this->emailService->send(
                    'Booking Rejected',
                    'services.booking_mail',
                    [
                        'business_name' => $service->name,
                        's_id'          => base64_encode(base64_encode($service->id)),
                        'reason'        => $reason ?? 'Not given',
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
            ServiceBooking::where('service_id', $id)->where('booker_id', Auth::id())->delete();
            ServiceBookingMilestone::where('service_id', $id)->where('booker_id', Auth::id())->delete();

            return response()->json(['message' => 'Booking reset successfully.'], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['service_id' => $id]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Old email links (GET CancelServiceBooking/{id}/{action} and CancelBookingConfirm/{id}/{action}). They used to
     * cancel and delete a booking for anyone holding the link, paid or not. A link never changes anything now: it opens
     * the booking's Milestones & payments panel, where the signed-in customer can cancel an unpaid booking
     * (POST services/bookings/{booking}/cancel).
     */
    public function CancelServiceBooking($booking_id, $action = null)
    {
        $booking = ServiceBooking::find((int) base64_decode((string) $booking_id, true));

        return redirect()->away($booking
            ? (ServiceBookingFlow::customerLink($booking) ?? \App\Service\Notification\RecipientUrl::main())
            : \App\Service\Notification\RecipientUrl::main());
    }

    public function getBookers(int $serviceId)
    {
        $bookers = ServiceBooking::where('service_id', $serviceId)
            ->where('status', 'confirmed')
            ->with('booker')
            ->get()
            ->filter(fn($b) => $b->booker)
            ->map(function ($b) {
                $b->booker->name = $b->booker->first_name . ' ' . $b->booker->last_name;
                return $b->booker;
            })->values();

        return response()->json(['data' => $bookers]);
    }


    private function bookerBookingsLink($bookerId): string
    {
        return \App\Service\Notification\EmailLink::bookingsFor($bookerId);
    }
}
