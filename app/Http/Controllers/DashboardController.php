<?php

namespace App\Http\Controllers;

use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Listing;
use App\Models\Communication\Notifications;
use App\Models\Milestones\Milestones;
use App\Models\Services\Services;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{

    public function home($query)
    {
        $user = Auth::user();

        try{
            if($query == 'hasInvestment'){
                $hasBids  = BusinessBids::where('investor_id', $user->id)->exists();

                $hasAccepted = AcceptedBids::where('investor_id', $user->id)->exists();

                return response()->json(['status' => $hasBids || $hasAccepted]);
            }
            elseif($query == 'myInvest'){
                return $this->myInvestments();
            }

            return response()->json([
                'investor'   => $user->user_type_id === 1,
                'user_email' => $user->email,
                'user_name'  => $user->first_name . ' ' . $user->last_name,
                'services'   => Services::where('user_id', $user->id)->get(),
            ]);
        }
        catch(\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    // Home sub-methods
    public function myInvestments()
    {
        $userId = Auth::id();
        $active = [];
        $pending = [];

        $acceptedBids = AcceptedBids::with('milestone')
            ->where('investor_id', $userId)->latest()->get();

        foreach ($acceptedBids as $share) {
            $listing = Listing::select('id', 'user_id', 'name', 'category', 'investment_needed', 'share')
                ->find($share->business_id);
            if (!$listing) continue;

            $listing->myShare        = (float) $share->representation;
            $listing->amount         = $share->amount;
            $listing->status         = $share->status;
            $listing->type           = $share->type;
            $listing->bid_id         = $share->id;
            $listing->activeMilestone = $listing->active_milestone()?->title ?? '';
            $listing->milestone_id   = $share->ms_id;
            $active[] = $listing;
        }

        $pendingBids = BusinessBids::where('investor_id', $userId)->latest()->get();
        foreach ($pendingBids as $share) {
            $listing = Listing::find($share->business_id);
            if (!$listing) continue;

            $listing->myShare  = (float) $share->representation;
            $listing->amount   = $share->amount;
            $listing->status   = 'Pending';
            $listing->type     = $share->type;
            $listing->bid_id   = $share->id;
            $pending[] = $listing;
        }

        $milestones = $acceptedBids->pluck('milestone')->filter()->unique('id')->values();

        return response()->json([
            'pending'    => $pending,
            'active'     => $active,
            'milestones' => $milestones,
        ]);
    }
    public function notifications(Request $request)
    {
        $query = Notifications::where('receiver_id', Auth::id())->where('visible', 1);

        // Paged for the bell (?page=1&per_page=10): newest first, the app asks for more as the list is scrolled.
        // Without per_page the full list is returned, as before.
        $paged = $request->filled('per_page');
        $page = null;
        if ($paged) {
            $perPage = min(max((int) $request->query('per_page'), 1), 50);
            $page = (clone $query)->latest()->orderByDesc('id')->paginate($perPage);
            $notifications = collect($page->items());
        } else {
            $notifications = $query->latest()->get();
        }

        $result = $notifications->filter(function ($notice) {
            $notifier = match($notice->type) {
                'investor', 'customer' => User::find($notice->customer_id),
                'business'             => Listing::find($notice->customer_id),
                'program'                 => 'Program',
                default                 => 'Tujitume',
            };

            if (!$notifier) return false;

            $name = match($notice->type) {
                'investor', 'customer' => ($notifier->first_name ?? '') . ' ' . ($notifier->last_name ?? ''),
                'program'                 => 'Program',
                default                 => 'Tujitume',
            };

            $notice->notifier_name = $name;
            $notice->text = str_replace('_name', $name, $notice->text);
            return true;
        })->values();

        if (!$page) {
            return response()->json(['data' => $result]);
        }

        return response()->json([
            'data' => $result,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'total'        => $page->total(),
                'has_more'     => $page->hasMorePages(),
                // Unread across ALL pages, so the bell badge stays right while only 10 are loaded
                'unread_count' => Notifications::where('receiver_id', Auth::id())->where('visible', 1)->where('new', 1)->count(),
            ],
        ]);
    }

    // Mark a single notification as read (only the receiver's own)
    public function notificationMarkRead(Request $request)
    {
        $validated = $request->validate(['id' => 'required|integer']);

        $updated = Notifications::where('receiver_id', Auth::id())
            ->where('id', $validated['id'])
            ->update(['new' => 0]);

        if (!$updated) {
            // Already read, or not this user's notification — nothing to change
            $exists = Notifications::where('receiver_id', Auth::id())->where('id', $validated['id'])->exists();
            if (!$exists) return response()->json(['message' => 'Notification not found'], 404);
        }

        return response()->json(['message' => 'success'], 200);
    }

    public function notificationSetRead()
    {
        Notifications::where('receiver_id', Auth::id())->update(['new' => 0]);
        return response()->json(['message' => 'success'], 200);
    }

    public function dashboardMilestonesInfo($id)
    {
        $userId   = Auth::id();
        $business = Listing::where('user_id', $userId)->get();

        if ($id === 'all') {
            $milestones    = Listing::where('user_id', $userId)->exists()
                ? Milestones::where('user_id', $userId)->get()
                : collect();
            $business_name = 'Select Business';
        } else {
            $listing       = Listing::findOrFail($id);
            $milestones    = $listing->milestones;
            $business_name = $listing->name;
        }

        // Attach business name to each milestone
        $nameMap = $business->pluck('name', 'id');
        $milestones->each(fn($m) => $m->business_name = $nameMap[$m->listing_id] ?? '');

        return response()->json([
            'milestones'    => $milestones,
            'business'      => $business,
            'business_name' => $business_name,
        ]);
    }

}
