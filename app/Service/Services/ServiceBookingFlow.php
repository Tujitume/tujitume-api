<?php

namespace App\Service\Services;

use App\Models\Auth\User;
use App\Models\Misc\Setting;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CheckoutAmountCalculator;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Notification\EmailService;
use App\Service\Misc\TransactionRecord;
use App\Service\Notification\EmailLink;
use App\Service\Notification\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Paying for a booked service and releasing it milestone by milestone.
 *
 *   1. The customer books (pending) and the provider accepts: the booking is "confirmed" and gets its milestones.
 *   2. The customer pays the whole service fee (the agreed price, else the service price, plus the checkout fees) into
 *      Tujitume's wallet: the booking is "paid" and its first milestone is In Progress.
 *   3. The provider marks a milestone Done. The customer either approves it, which releases that milestone's amount
 *      from Tujitume's wallet to the provider and starts the next one, or asks for changes (back to In Progress).
 *   4. When the last milestone is released the booking is "completed".
 *
 * Every step checks who is asking: only the customer pays, approves or asks for changes, only the provider moves a
 * milestone. Money moves only from signed-in actions (never from an email link).
 */
class ServiceBookingFlow
{
    /** Thrown with a message the person can read and the HTTP status to answer with. */
    public static function refuse(string $message, int $status = 422): never
    {
        throw new RuntimeException($message, $status);
    }

    /**
     * When an accepted booking must be paid by: the start of its service date (the provider cannot accept it after
     * that either). Null once paid or ended.
     */
    public static function payBy(ServiceBooking $booking): ?string
    {
        if ($booking->paid || ! $booking->date || in_array($booking->status, ['rejected', 'cancelled'], true)) return null;
        try {
            return \Illuminate\Support\Carbon::parse($booking->date)->startOfDay()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /** What the customer pays for the service, before checkout fees (the agreed price of an offer, else the price). */
    public static function amountDue(ServiceBooking $booking): float
    {
        return (float) ($booking->agreed_price ?? $booking->service?->price ?? 0);
    }

    /** What M-Pesa is asked for: the amount due plus the platform fee, grossed up for the gateway (as checkout shows). */
    public static function charge(ServiceBooking $booking, string $channel = 'mpesa'): int
    {
        return (int) round((new CheckoutAmountCalculator())->mpesa(self::amountDue($booking), $channel));
    }

    /** @return Collection<int, ServiceBookingMilestone> in the order they are delivered */
    public static function milestones(ServiceBooking $booking): Collection
    {
        return ServiceBookingMilestone::where('booking_id', $booking->id)->orderBy('id')->get();
    }

    /** The booking, its milestones and what the viewer can do next (customer or provider), for the milestones panel. */
    public static function view(ServiceBooking $booking, User $viewer): array
    {
        $booking->loadMissing('service.owner');
        $service = $booking->service;
        $isCustomer = (int) $booking->booker_id === (int) $viewer->id;
        $isProvider = (int) $booking->service_owner_id === (int) $viewer->id;
        $milestones = self::milestones($booking);
        $paid = (bool) $booking->paid;
        $current = $milestones->first(fn ($m) => ! $m->released);
        $auditors = User::whereIn('id', $milestones->pluck('auditor_id')->filter()->unique())->get(['id', 'first_name', 'last_name'])->keyBy('id');

        $rows = $milestones->map(fn (ServiceBookingMilestone $m) => [
            'id' => $m->id,
            'title' => $m->title,
            'amount' => (float) $m->amount,
            'days' => $m->n_o_days ? (int) $m->n_o_days : null,
            'deadline' => $m->deadline,
            'status' => $m->status,
            'released' => (bool) $m->released,
            'released_at' => $m->released_at,
            'release_status' => $m->release_status,
            'change_note' => $m->change_note,
            // a stored path: give a link that opens (it was the raw path, so "View document" went nowhere)
            'document' => \App\Service\Capital\CapitalFiles::url($m->document),
            // what the provider delivered when they marked it done
            'delivery' => $m->delivered_at || $m->delivery_note || $m->delivery_files ? [
                'note' => $m->delivery_note,
                'files' => collect($m->delivery_files ?? [])->map(fn ($f) => ['name' => $f['name'] ?? basename($f['path'] ?? ''), 'url' => \App\Service\Capital\CapitalFiles::url($f['path'] ?? null)])->values()->all(),
                'delivered_at' => $m->delivered_at?->toIso8601String(),
            ] : null,
            // The customer approves the milestone being delivered once the provider has marked it Done
            'can_approve' => $isCustomer && $paid && ! $m->released && $m->status === 'Done' && $current?->id === $m->id
                && ! in_array($m->audit_status, self::AUDIT_OPEN, true),
            // DECISION-9: the customer may ask a project manager to audit a Done milestone before approving it (paid)
            'audit' => $m->audit_status ? [
                'status' => $m->audit_status,
                'auditor' => ($a = $auditors->get($m->auditor_id)) ? ['id' => $a->id, 'name' => trim($a->first_name.' '.$a->last_name)] : null,
                'fee' => $m->audit_fee !== null ? (float) $m->audit_fee : null,
                'fee_status' => $m->audit_fee_status,
                'recommendation' => $m->audit_recommendation,
                'notes' => $m->audit_notes,
                'completed_at' => $m->audit_completed_at,
            ] : null,
            'can_request_audit' => $isCustomer && $paid && ! $m->released && $m->status === 'Done' && $current?->id === $m->id
                && ! in_array($m->audit_status, [...self::AUDIT_OPEN, 'reported'], true),
        ])->values();

        $state = match (true) {
            $booking->status === 'rejected' => 'rejected',
            $booking->status === 'cancelled' => 'cancelled',
            $booking->status === 'pending' => 'awaiting_provider',
            ! $paid => 'awaiting_payment',
            $milestones->isNotEmpty() && $milestones->every(fn ($m) => $m->released) => 'completed',
            default => 'in_progress',
        };

        return [
            'booking' => [
                'id' => $booking->id,
                'status' => $booking->status,
                'stage' => $booking->stage,
                'date' => $booking->date,
                'note' => $booking->note,
                'paid' => $paid,
                'paid_at' => $booking->paid_at,
                'rejection_note' => $booking->rejection_note,
                'deadline_at' => self::payBy($booking),
                'method' => $booking->method,
            ],
            'service' => [
                'id' => $service?->id,
                'name' => $service?->name,
                'image' => $service?->image,
                'category' => $service?->category,
                'provider_name' => $service?->owner ? trim($service->owner->first_name.' '.$service->owner->last_name) : null,
            ],
            'role' => $isCustomer ? 'customer' : 'provider',
            'state' => $state,
            'amount_due' => self::amountDue($booking),
            'released_total' => (float) $milestones->where('released', true)->sum('amount'),
            // Checkout pays against the booking's first milestone (the payment endpoints are keyed by it)
            'pay_milestone_id' => $milestones->first()?->id,
            'can_pay' => $isCustomer && $state === 'awaiting_payment' && $milestones->isNotEmpty(),
            'can_cancel' => $isCustomer && ! $paid && ! in_array($booking->status, ['rejected', 'cancelled'], true),
            'milestones' => $rows,
        ];
    }

    /** The booking the signed-in person may see: theirs as customer or as provider. */
    public static function forViewer(int $bookingId, User $viewer): ServiceBooking
    {
        $booking = ServiceBooking::with('service.owner')->find($bookingId);
        if (! $booking || ! $booking->service) self::refuse('Booking not found.', 404);
        if ((int) $booking->booker_id !== (int) $viewer->id && (int) $booking->service_owner_id !== (int) $viewer->id) {
            self::refuse('Booking not found.', 404);
        }

        return $booking;
    }

    /** May this customer pay this booking now? (the checkout and the payment confirmation both ask) */
    public static function assertPayable(ServiceBooking $booking, User $customer): void
    {
        if ((int) $booking->booker_id !== (int) $customer->id) self::refuse('Only the person who booked can pay for this service.', 403);
        if ((int) $booking->service_owner_id === (int) $customer->id) self::refuse('You cannot pay for your own service.', 403);
        if ($booking->paid) self::refuse('This booking is already paid.', 409);
        if ($booking->status !== 'confirmed') self::refuse('The provider has not accepted this booking yet.');
        if (self::milestones($booking)->isEmpty()) self::refuse('This booking has no milestones to pay for.');
    }

    /**
     * Records the service fee as paid (once): the booking is paid and its first milestone starts. A payment reference
     * pays one booking only.
     */
    public function markPaid(ServiceBooking $booking, User $customer, string $method, string $reference, float $amount): bool
    {
        $done = DB::transaction(function () use ($booking, $customer, $method, $reference, $amount) {
            $booking = ServiceBooking::whereKey($booking->id)->lockForUpdate()->first();
            if ($booking->paid) {
                if ($booking->payment_reference === $reference) return false; // the same payment polled again
                self::refuse('This booking is already paid.', 409);
            }
            if (ServiceBooking::where('payment_reference', $reference)->where('id', '!=', $booking->id)->exists()) {
                self::refuse('This payment has already been used for another booking.', 409);
            }

            $booking->update([
                'paid' => 1,
                'method' => $method,
                'status' => 'paid',
                'stage' => 'Payment Made',
                'paid_at' => now(),
                'payment_reference' => $reference,
            ]);

            $first = self::milestones($booking)->first();
            $first?->update(['status' => 'In Progress', 'active' => 1]);

            (new TransactionRecord())->create($customer->id, 'service_fee', $method, $amount, $reference, $booking->service_owner_id);

            return true;
        });

        if ($done) {
            $service = $booking->service;
            $this->notify($booking->service_owner_id, $customer->id, "Service {$service->name} is paid: you can start the first milestone.",
                'dashboard.serviceProvider.serviceBookings');
        }

        return $done;
    }

    /**
     * The customer approves the milestone the provider marked Done: its amount goes from Tujitume's wallet to the
     * provider, and the next milestone starts. The last one completes the booking.
     */
    public function approve(ServiceBooking $booking, ServiceBookingMilestone $milestone, User $customer): void
    {
        $this->assertCustomerStep($booking, $milestone, $customer);

        $owner = User::find($booking->service_owner_id);
        if (! $owner?->lipr_wallet) {
            self::refuse('The provider has not set up their wallet to receive payments yet. Ask them to finish their payment setup, then approve again.');
        }
        $platformWallet = Setting::where('key', 'platform_lipr_wallet')->value('value');
        if (! $platformWallet) self::refuse('Payments are not available right now. Please try again later.', 503);

        // Claim the milestone first, so two clicks can never release it twice
        $claimed = ServiceBookingMilestone::whereKey($milestone->id)->where(fn ($q) => $q->whereNull('released')->orWhere('released', 0))
            ->update(['released' => 1, 'release_status' => 'processing', 'released_at' => now()]);
        if (! $claimed) self::refuse('This milestone has already been released.', 409);

        try {
            $transfer = app(LiprW2W::class)->send((int) round((float) $milestone->amount), $owner->lipr_wallet, $platformWallet, null, [
                'result_url' => \App\Service\LiprMpesa\LiprCallback::url('/lipr-callback/service-milestone'),
                'listing_id' => $milestone->id,
                'narration' => 'Service milestone payment',
            ]);
        } catch (Throwable $e) {
            $transfer = ['success' => false, 'message' => $e->getMessage()];
        }

        if (! ($transfer['success'] ?? false)) {
            ServiceBookingMilestone::whereKey($milestone->id)->update(['released' => 0, 'release_status' => 'failed', 'released_at' => null]);
            Log::warning('Service milestone release failed', ['milestone' => $milestone->id, 'error' => $transfer['message'] ?? $transfer['errors'] ?? null]);
            self::refuse('The payment could not be sent to the provider. Nothing was paid; please try again.', 502);
        }

        DB::transaction(function () use ($booking, $milestone, $customer, $transfer) {
            $milestone->refresh()->update(['release_reference' => $transfer['reference'] ?? null, 'active' => 0, 'change_note' => null]);
            (new TransactionRecord())->create($customer->id, 'service_milestone', 'lipr', (float) $milestone->amount, $transfer['reference'] ?? null, $booking->service_owner_id);

            $next = self::milestones($booking)->first(fn ($m) => ! $m->released);
            if ($next) {
                $next->update(['status' => 'In Progress', 'active' => 1]);
                $booking->update(['status' => 'in_progress', 'stage' => "{$milestone->title} released"]);
            } else {
                $booking->update(['status' => 'completed', 'stage' => 'Service delivered']);
            }
        });

        $service = $booking->service;
        $finished = $booking->refresh()->status === 'completed';
        $this->notify($booking->service_owner_id, $customer->id,
            "{$customer->first_name} approved {$milestone->title} of {$service->name}: its payment is on its way to you.",
            'dashboard.serviceProvider.serviceBookings');

        // ISSUE-46: the provider is emailed for every approved milestone (they used to hear only at the very end)
        $provider = User::find($booking->service_owner_id);
        if ($provider?->email) {
            $nextTitle = $finished ? null : self::milestones($booking)->first(fn ($m) => ! $m->released)?->title;
            (new EmailService())->send("Milestone approved: KES ".number_format((float) $milestone->amount, 2)." on its way", 'services.milestone_paid', [
                'customer_name' => trim($customer->first_name.' '.$customer->last_name), 'milestone' => $milestone->title,
                'business_name' => $service->name, 'amount' => (float) $milestone->amount, 'next' => $nextTitle,
                'link' => \App\Service\Notification\EmailLink::url('dashboard.serviceProvider.serviceBookings', $provider),
            ], $provider->email);
        }

        if ($finished) {
            $owner = User::find($booking->service_owner_id);
            $link = self::customerLink($booking);
            $email = new EmailService();
            foreach ([[$customer, 1, $link], [$owner, 2, null]] as [$person, $to, $personLink]) {
                if (! $person?->email) continue;
                $email->send('Service Done', 'milestoneS.service_done_mail', [
                    's_id' => base64_encode(base64_encode($service->id)), 'service' => $service->name, 'amount' => self::amountDue($booking),
                    'to' => $to, 'user_name' => $person->first_name, 'link' => $personLink,
                ], $person->email);
            }
        }
    }

    /**
     * The customer cancels a booking they have not paid for yet (pending, or accepted and unpaid): the booking and its
     * milestones are removed and the provider is told. A paid booking holds the customer's money and is not cancelled
     * here: they talk to the provider or support.
     */
    public function cancel(ServiceBooking $booking, User $customer): void
    {
        if ((int) $booking->booker_id !== (int) $customer->id) self::refuse('Only the person who booked can cancel this booking.', 403);
        if ($booking->paid) self::refuse('This booking is paid, so it cannot be cancelled here. Message the provider or contact support.', 409);
        if (in_array($booking->status, ['rejected', 'cancelled'], true)) self::refuse('This booking has already ended.', 409);

        $service = $booking->service;
        $owner = User::find($booking->service_owner_id);
        // Kept as cancelled (nothing was paid), so both sides still see it in their lists; its milestones go
        DB::transaction(function () use ($booking) {
            ServiceBookingMilestone::where('booking_id', $booking->id)->delete();
            $booking->update(['status' => 'cancelled', 'stage' => 'Cancelled']);
        });

        $name = trim($customer->first_name.' '.$customer->last_name);
        $this->notify($booking->service_owner_id, $customer->id, "A booking of {$service?->name} was cancelled by {$name}.", 'dashboard.serviceProvider.serviceBookings');
        if ($owner?->email) {
            (new EmailService())->send('Booking Cancelled', 'services.booking_cancelled', ['investor' => $name, 'business_name' => $service?->name], $owner->email);
        }
    }

    /** The customer is not satisfied with a milestone marked Done: it goes back to In Progress with their note. */
    public function requestChanges(ServiceBooking $booking, ServiceBookingMilestone $milestone, User $customer, string $note): void
    {
        $this->assertCustomerStep($booking, $milestone, $customer);
        // A finished audit was about this delivery; the next delivery can be audited again
        $milestone->update(['status' => 'In Progress', 'active' => 1, 'change_note' => $note, 'audit_status' => null]);

        $service = $booking->service;
        $this->notify($booking->service_owner_id, $customer->id,
            "{$customer->first_name} asked for changes to {$milestone->title} of {$service->name}: {$note}",
            'dashboard.serviceProvider.serviceBookings');

        $owner = User::find($booking->service_owner_id);
        if ($owner?->email) {
            (new EmailService())->send('Milestone Review', 'milestoneS.milestone_review',
                ['service' => $service->name, 'milestone' => $milestone->title, 'note' => $note], $owner->email);
        }
    }

    /**
     * May the provider move this milestone to $status? Only their own booking, only once it is paid, in order (a
     * milestone starts after the previous one is released) and never a released one.
     */
    public static function assertProviderStep(ServiceBookingMilestone $milestone, User $provider, string $status): ServiceBooking
    {
        $booking = $milestone->booking;
        if (! $booking || (int) $booking->service_owner_id !== (int) $provider->id) self::refuse('Milestone not found.', 404);
        if (! in_array($status, ['In Progress', 'Done'], true)) self::refuse('A milestone can only be started or marked as done.');
        if (! $booking->paid) self::refuse('The customer has not paid for this service yet. Start the work once it is paid.');
        if ($milestone->released) self::refuse('This milestone is already released.', 409);

        $current = self::milestones($booking)->first(fn ($m) => ! $m->released);
        if ($current?->id !== $milestone->id) self::refuse('Finish the milestones in order: the previous one is not released yet.');
        if ($status === 'Done' && $milestone->status !== 'In Progress') self::refuse('Start the milestone before marking it as done.');
        if ($status === 'In Progress' && $milestone->status === 'Done') self::refuse('This milestone is waiting for the customer\'s approval.');

        return $booking;
    }

    /** Where the customer follows this booking's milestones: their bookings page with the milestones panel open. */
    public static function customerLink(ServiceBooking $booking): ?string
    {
        return EmailLink::url(self::customerLinkKey($booking), (int) $booking->booker_id);
    }

    public static function customerLinkKey(ServiceBooking $booking): string
    {
        return EmailLink::bookingsFor($booking->booker_id).'?milestones=@'.$booking->id;
    }

    /** DECISION-9: an audit the customer asked for that is not reported yet: approve / request changes wait for it */
    public const AUDIT_OPEN = ['pending', 'quoted', 'accepted'];

    public static function assertCustomerStep(ServiceBooking $booking, ServiceBookingMilestone $milestone, User $customer, bool $allowAudit = false): void
    {
        if (! $allowAudit && in_array($milestone->audit_status, self::AUDIT_OPEN, true)) {
            self::refuse('A project manager is auditing this milestone. Wait for their report, or cancel the audit, then decide.', 409);
        }
        if ((int) $booking->booker_id !== (int) $customer->id) self::refuse('Only the person who booked can approve this work.', 403);
        if ((int) $milestone->booking_id !== (int) $booking->id) self::refuse('Milestone not found.', 404);
        if (! $booking->paid) self::refuse('Pay for the service first.');
        if ($milestone->released) self::refuse('This milestone has already been released.', 409);
        if ($milestone->status !== 'Done') self::refuse('The provider has not marked this milestone as done yet.');
        $current = self::milestones($booking)->first(fn ($m) => ! $m->released);
        if ($current?->id !== $milestone->id) self::refuse('Approve the milestones in order.');
    }

    private function notify(int $to, ?int $from, string $text, string $link): void
    {
        try {
            (new NotificationService())->create($to, $from, $text, $link, 'service');
        } catch (Throwable $e) {
            Log::warning('Service booking notification failed', ['error' => $e->getMessage()]);
        }
    }
}
