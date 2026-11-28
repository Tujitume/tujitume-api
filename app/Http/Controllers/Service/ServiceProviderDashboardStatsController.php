<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Service\Misc\ErrorLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * GET dashboard/service-provider/stats
 *
 * The service provider dashboard in one snapshot, like the entrepreneur's (EntrepreneurDashboardStatsController):
 * three cards (Services, Bookings, Earnings), the recent bookings table and the "work by status" ring, which covers both
 * kinds of work a provider does: service bookings and program work (Business Coach reviews, site visits).
 * Scoped to the signed-in provider. Numbers only; empty categories are zero, never null.
 */
class ServiceProviderDashboardStatsController extends Controller
{
    /** Bookings still running for the provider (accepted and not finished) */
    private const ACTIVE = ['confirmed', 'paid', 'in_progress'];

    public function index()
    {
        try {
            $userId = (int) Auth::id();
            $monthStart = Carbon::now()->startOfMonth();

            $services = DB::table('services')->where('user_id', $userId)->get(['id', 'active', 'rating', 'rating_count']);
            $bookings = DB::table('service_bookings')->where('service_owner_id', $userId)
                ->get(['id', 'service_id', 'booker_id', 'status', 'agreed_price', 'paid', 'created_at']);
            $bookingIds = $bookings->pluck('id');

            // Earnings: a booking milestone is earned once released to the provider; money paid in but not released yet is pending
            $milestones = $bookingIds->isEmpty() ? collect() : DB::table('service_booking_milestones')
                ->whereIn('booking_id', $bookingIds)->get(['booking_id', 'amount', 'released', 'released_at', 'release_status']);
            $paidBookingIds = $bookings->filter(fn ($b) => (bool) $b->paid)->pluck('id')->all();
            $released = $milestones->filter(fn ($m) => (bool) $m->released && $m->release_status !== 'failed');
            $pendingRelease = $milestones->filter(fn ($m) => ! $m->released && in_array($m->booking_id, $paidBookingIds, true));

            // Program work: Business Coach reviews and site visits ordered from this provider
            $orders = DB::table('reviewer_orders')->where('reviewer_id', $userId)
                ->get(['id', 'order_type', 'work_status', 'payment_status', 'fee_amount', 'paid_at']);
            $orderPaid = $orders->where('payment_status', 'completed');
            $orderOnTheWay = $orders->filter(fn ($o) => in_array($o->payment_status, ['pending', 'leg1_processing'], true));

            $releasedTotal = (float) $released->sum('amount');
            $programPaid = (float) $orderPaid->sum('fee_amount');
            $releasedThisMonth = (float) $released->filter(fn ($m) => $m->released_at && Carbon::parse($m->released_at)->gte($monthStart))->sum('amount')
                + (float) $orderPaid->filter(fn ($o) => $o->paid_at && Carbon::parse($o->paid_at)->gte($monthStart))->sum('fee_amount');

            return response()->json([
                'success' => true,
                'message' => 'Service provider dashboard statistics fetched successfully.',
                'data' => [
                    'currency' => config('app.currency', env('CURRENCY')),
                    'services' => [
                        'total' => $services->count(),
                        'active' => $services->filter(fn ($s) => (bool) $s->active)->count(),
                        'awaiting_reply' => $bookings->where('status', 'pending')->count(),
                        'rating' => $this->rating($services),
                    ],
                    'bookings' => [
                        'total' => $bookings->count(),
                        'this_month' => $bookings->filter(fn ($b) => Carbon::parse($b->created_at)->gte($monthStart))->count(),
                        'active' => $bookings->filter(fn ($b) => in_array($b->status, self::ACTIVE, true))->count(),
                        'completed' => $bookings->where('status', 'completed')->count(),
                    ],
                    'earnings' => [
                        'total' => $releasedTotal + $programPaid,
                        'services' => $releasedTotal,
                        'program_work' => $programPaid,
                        'this_month' => $releasedThisMonth,
                        'pending' => (float) $pendingRelease->sum('amount') + (float) $orderOnTheWay->sum('fee_amount'),
                    ],
                    'program_work' => [
                        'total' => $orders->count(),
                        'open' => $orders->filter(fn ($o) => in_array($o->work_status, ['pending', 'assigned', 'in_progress', 'modification_requested'], true))->count(),
                    ],
                    // The "work by status" ring: bookings and program work, each by its own status
                    'booking_statuses' => $this->counts($bookings, 'status'),
                    'program_work_statuses' => $this->counts($orders, 'work_status'),
                    'recent_bookings' => $this->recentBookings($userId),
                ],
            ]);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['user_id' => Auth::id()]);

            return response()->json(['success' => false, 'message' => 'Could not load your dashboard. Please try again.'], 500);
        }
    }

    /** The average rating over the provider's rated services, weighted by how many reviews each has */
    private function rating($services): ?float
    {
        $rated = $services->filter(fn ($s) => (int) $s->rating_count > 0 && $s->rating !== null);
        $count = $rated->sum(fn ($s) => (int) $s->rating_count);
        if (! $count) return null;

        return round($rated->sum(fn ($s) => (float) $s->rating * (int) $s->rating_count) / $count, 1);
    }

    private function counts($rows, string $field)
    {
        return $rows->groupBy(fn ($r) => (string) ($r->{$field} ?: 'unknown'))->map->count()->sortDesc()
            ->map(fn ($count, $status) => ['status' => $status, 'count' => $count])->values();
    }

    /** The five newest bookings with who booked, what and how much */
    private function recentBookings(int $userId)
    {
        return DB::table('service_bookings as b')
            ->join('services as s', 's.id', '=', 'b.service_id')
            ->leftJoin('users as u', 'u.id', '=', 'b.booker_id')
            ->where('b.service_owner_id', $userId)
            ->orderByDesc('b.created_at')->orderByDesc('b.id')
            ->limit(5)
            ->get(['b.id', 'b.status', 'b.paid', 'b.date', 'b.created_at', 'b.agreed_price', 's.id as service_id', 's.name as service_name', 's.price',
                'u.first_name', 'u.last_name', 'u.display_name'])
            ->map(fn ($b) => [
                'id' => (int) $b->id,
                'service_id' => (int) $b->service_id,
                'service_name' => $b->service_name,
                'customer_name' => trim((string) ($b->display_name ?: trim(($b->first_name ?? '').' '.($b->last_name ?? '')))) ?: null,
                'amount' => (float) ($b->agreed_price ?: $b->price),
                'status' => $b->status,
                'paid' => (bool) $b->paid,
                'date' => $b->date,
                'created_at' => $b->created_at,
            ]);
    }
}
