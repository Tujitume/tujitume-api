<?php

namespace App\Http\Middleware\Api;

use App\Service\Organization\OrganizationPermission;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Route;
use Symfony\Component\HttpFoundation\Response;

class ProgramMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        //$uri = Route::current()?->uri();
        $method = $request->method();;
        $route_name = $lastSegment = $this->getLastRouteSegment(Route::current()?->uri());
        $isOrgnizationUser = Auth::user()?->user_type_id === 4;

        $user = Auth::user();
        if (! $user) {
            return $next($request);
        }


        $user->load('organizationRole.role');
        $role = $user->organizationRole?->role?->name;

        // Organization admins must be verified to use program management. Business applicants
        // may use program functionality, including applying, before KYC is complete.
        if ($isOrgnizationUser && $role === 'super-admin'
            && $user->currentKycVerification()->value('status') !== 'verified') {
            return response()->json([
                'message' => 'KYC verification is required to access program functionality.',
                'status' => 403,
            ], 403);
        }


        // What each company role may do (viewer: read only, editor: no deleting, internal reviewer: review work only,
        // deactivated members: nothing): OrganizationPermission, shared with capital organizations and events
        if ($denied = OrganizationPermission::check($request, 'programs')) {
            return $denied;
        }

        return $next($request);
    }

    function getLastRouteSegment(?string $uri): ?string
    {
        if (!$uri) return null;
        $segments = array_filter(
            explode('/', $uri),
            fn($seg) => !str_starts_with($seg, '{')
        );
        return end($segments) ?: null;
    }

}
