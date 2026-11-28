<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The /business routes are shared: the account, subscription, notifications and investor-side bid routes are used by every
 * user type. The ones that create or run a business (listings, its milestones, the owner's bid lists, asking an investor to
 * verify an asset) are only for business owners (user type 1); anyone else gets 403.
 */
class BusinessMiddleware
{
    /** Route URIs (after "api/v1/business/") that only a business owner may call. */
    private const OWNER_ONLY = [
        'bulk-import',
        'create-listing',
        'up_listing',
        'delete_listing/{id}',
        'deactivate',
        'business_bids',
        'confirmed_bids',
        'askInvestorToVerify/{bid_id}',
        'add_milestones',
        'activate_milestone/{id}',
        'save_milestone',
        'delete_milestone/{id}',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (! $user) {
            return $next($request);
        }

        $uri = (string) $request->route()?->uri();
        $path = preg_replace('#^api/v1/business/#', '', $uri);

        if (in_array($path, self::OWNER_ONLY, true) && (int) $user->user_type_id !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Only a business owner can do this.',
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}
