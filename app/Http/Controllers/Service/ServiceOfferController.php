<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ServiceOfferController extends Controller
{
    // â”€â”€â”€ Customer makes offer â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // POST /services/{service}/offer
    public function store(Request $request, Services $service)
    {
        try {
            $validated = $request->validate([
                'offered_price' => 'required|numeric|min:1',
                'note'          => 'nullable|string|max:500',
            ]);

            $booker = Auth::user();

            if ($validated['offered_price'] >= $service->price) {
                return response()->json([
                    'message' => 'Offer must be less than the original price of $' . $service->price
                ], 422);
            }

            $existingOffer = ServiceOffer::where('service_id', $service->id)
                ->where('booker_id', $booker->id)
                ->whereIn('status', ['pending', 'countered'])
                ->exists();

            if ($existingOffer) {
                return response()->json([
                    'message' => 'You already have a pending offer for this service.'
                ], 409);
            }

            $discountPercent = round((($service->price - $validated['offered_price']) / $service->price) * 100, 2);

            $offer = ServiceOffer::create([
                'service_id'       => $service->id,
                'booker_id'        => $booker->id,
                'original_price'   => $service->price,
                'offered_price'    => $validated['offered_price'],
                'discount_percent' => $discountPercent,
                'note'             => $validated['note'] ?? null,
                'status'           => 'pending',
            ]);

            // Notify owner
            $this->notification->create(
                $service->user_id,
                $booker->id,
                "{$booker->fname} made an offer of \${$validated['offered_price']} on {$service->name} (original \${$service->price})",
                'dashboard.serviceProvider.myBookings',
                'service'
            );

            $this->emailService->send(
                'New Offer Received',
                'services.offer_received',
                [
                    'business_name'  => $service->name,
                    'customer_name'  => $booker->fname . ' ' . $booker->lname,
                    'offered_price'  => $validated['offered_price'],
                    'original_price' => $service->price,
                    'discount'       => $discountPercent,
                    'note'           => $validated['note'] ?? null,
                ],
                $service->owner->email
            );

            return response()->json([
                'message' => 'Offer submitted successfully.',
                'data'    => $offer,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // â”€â”€â”€ Owner accepts offer â†’ creates flat booking (no milestones) â”€â”€
    // POST /services/offers/{offer}/accept
    public function accept(ServiceOffer $offer)
    {
        if ($offer->service->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!in_array($offer->status, ['pending', 'countered'])) {
            return response()->json(['error' => 'Offer is no longer active'], 422);
        }

        DB::beginTransaction();
        try {
            $service     = $offer->service;
            $booker      = $offer->booker;
            $agreedPrice = $offer->counter_price ?? $offer->offered_price;

            // Create flat booking â€” no milestones
            $booking = ServiceBooking::create([
                'service_id'       => $service->id,
                'booker_id'        => $offer->booker_id,
                'service_owner_id' => $service->user_id,
                'date'             => now()->format('Y-m-d'),
                'status'           => 'confirmed',
                'is_offer_booking' => true,
                'offer_id'         => $offer->id,
                'agreed_price'     => $agreedPrice,
                'delivery_status'  => 'pending',
            ]);

            $offer->update([
                'status'     => 'accepted',
                'booking_id' => $booking->id,
            ]);

            DB::commit();

            // Notify customer
            $this->notification->create(
                $offer->booker_id,
                Auth::id(),
                "Your offer of \${$agreedPrice} on {$service->name} was accepted! Please proceed to payment.",
                'dashboard.entrepreneur.mybookings::' . $booking->id,
                'service'
            );

            $this->emailService->send(
                'Offer Accepted - Please Proceed to Payment',
                'services.offer_accepted',
                [
                    'business_name' => $service->name,
                    'agreed_price'  => $agreedPrice,
                    'booking_id'    => $booking->id,
                ],
                $booker->email
            );

            return response()->json([
                'message' => 'Offer accepted. Customer notified to pay.',
                'data'    => [
                    'booking_id'  => $booking->id,
                    'agreed_price' => $agreedPrice,
                ],
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => []]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // â”€â”€â”€ Owner rejects offer â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // POST /services/offers/{offer}/reject
    public function reject(Request $request, ServiceOffer $offer)
    {
        if ($offer->service->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!in_array($offer->status, ['pending', 'countered'])) {
            return response()->json(['error' => 'Offer is no longer active'], 422);
        }

        try {
            $validated = $request->validate([
                'note' => 'nullable|string|max:500',
            ]);

            $offer->update(['status' => 'rejected']);

            $this->notification->create(
                $offer->booker_id,
                Auth::id(),
                "Your offer on {$offer->service->name} was rejected.",
                'dashboard.entrepreneur.mybookings',
                'service'
            );

            return response()->json(['message' => 'Offer rejected.'], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // â”€â”€â”€ Owner counters offer â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // POST /services/offers/{offer}/counter
    public function counter(Request $request, ServiceOffer $offer)
    {
        if ($offer->service->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($offer->status !== 'pending') {
            return response()->json(['error' => 'Can only counter a pending offer'], 422);
        }

        try {
            $validated = $request->validate([
                'counter_price' => 'required|numeric|min:1',
                'counter_note'  => 'nullable|string|max:500',
            ]);

            if ($validated['counter_price'] >= $offer->original_price) {
                return response()->json([
                    'message' => 'Counter price must be less than original price'
                ], 422);
            }

            $offer->update([
                'status'        => 'countered',
                'counter_price' => $validated['counter_price'],
                'counter_note'  => $validated['counter_note'] ?? null,
            ]);

            $this->notification->create(
                $offer->booker_id,
                Auth::id(),
                "Counter offer of \${$validated['counter_price']} received for {$offer->service->name}",
                'dashboard.entrepreneur.mybookings',
                'service'
            );

            $this->emailService->send(
                'Counter Offer Received',
                'services.offer_countered',
                [
                    'business_name'  => $offer->service->name,
                    'original_price' => $offer->original_price,
                    'your_offer'     => $offer->offered_price,
                    'counter_price'  => $validated['counter_price'],
                    'note'           => $validated['counter_note'] ?? null,
                ],
                $offer->booker->email
            );

            return response()->json([
                'message' => 'Counter offer sent.',
                'data'    => $offer->fresh(),
            ], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // â”€â”€â”€ Customer accepts counter offer â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // POST /services/offers/{offer}/accept-counter
    public function acceptCounter(Request $request, ServiceOffer $offer)
    {
        if ($offer->booker_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($offer->status !== 'countered') {
            return response()->json(['error' => 'No counter offer to accept'], 422);
        }

        // Set counter price as agreed price then accept
        $offer->update([
            'offered_price'    => $offer->counter_price,
            'discount_percent' => round((($offer->original_price - $offer->counter_price) / $offer->original_price) * 100, 2),
            'status'           => 'pending',
        ]);

        return $this->accept($offer);
    }

    // â”€â”€â”€ Owner marks as delivered â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // POST /services/bookings/{booking}/deliver
    public function deliver(Request $request, ServiceBooking $booking)
    {
        if ($booking->service_owner_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!$booking->is_offer_booking) {
            return response()->json(['error' => 'Not an offer booking'], 422);
        }

        if ($booking->status !== 'in_progress') {
            return response()->json(['error' => 'Service must be in progress to mark as delivered'], 422);
        }

        try {
            $validated = $request->validate([
                'delivery_note' => 'nullable|string|max:1000',
            ]);

            $booking->update([
                'delivery_status' => 'delivered',
                'delivery_note'   => $validated['delivery_note'] ?? null,
            ]);

            $this->notification->create(
                $booking->booker_id,
                Auth::id(),
                "Your service {$booking->service->name} has been delivered. Please review and accept.",
                'dashboard.entrepreneur.mybookings::' . $booking->id,
                'service'
            );

            return response()->json(['message' => 'Service marked as delivered.'], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // â”€â”€â”€ Customer accepts delivery â†’ owner gets paid â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // POST /services/bookings/{booking}/accept-delivery
    public function acceptDelivery(ServiceBooking $booking)
    {
        if ($booking->booker_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($booking->delivery_status !== 'delivered') {
            return response()->json(['error' => 'No delivery to accept yet'], 422);
        }

        DB::beginTransaction();
        try {
            $booking->update([
                'delivery_status' => 'accepted',
                'status'          => 'completed',
            ]);

            // TODO: Release agreed_price from escrow to owner
            // $this->balance->updateBalance($booking->service_owner_id, $booking->agreed_price, 'service_offer');

            DB::commit();

            $this->notification->create(
                $booking->service_owner_id,
                Auth::id(),
                "Delivery accepted for {$booking->service->name}. Payment released.",
                'dashboard.serviceProvider.myBookings::' . $booking->id,
                'service'
            );

            return response()->json(['message' => 'Delivery accepted. Service completed.'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => []]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // â”€â”€â”€ Customer rejects delivery â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // POST /services/bookings/{booking}/reject-delivery
    public function rejectDelivery(Request $request, ServiceBooking $booking)
    {
        if ($booking->booker_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($booking->delivery_status !== 'delivered') {
            return response()->json(['error' => 'No delivery to reject yet'], 422);
        }

        try {
            $validated = $request->validate([
                'rejection_note' => 'required|string|max:1000',
            ]);

            $booking->update([
                'delivery_status' => 'rejected',
                'rejection_note'  => $validated['rejection_note'],
            ]);

            $this->notification->create(
                $booking->service_owner_id,
                Auth::id(),
                "Delivery rejected for {$booking->service->name}. Reason: {$validated['rejection_note']}",
                'dashboard.serviceProvider.myBookings::' . $booking->id,
                'service'
            );

            return response()->json(['message' => 'Delivery rejected. Owner notified to revise.'], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // â”€â”€â”€ Get offers for a service (owner view) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // GET /services/{service}/offers
    public function index(Services $service)
    {
        if ($service->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json([
            'data' => ServiceOffer::where('service_id', $service->id)
                ->with('booker')->latest()->get()
        ], 200);
    }

    // â”€â”€â”€ Get my offers (customer view) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // GET /services/my-offers
    public function myOffers()
    {
        return response()->json([
            'data' => ServiceOffer::where('booker_id', Auth::id())
                ->with('service')->latest()->get()
        ], 200);
    }
}
