<?php

namespace App\Http\Middleware\Api;

use App\Service\Organization\OrganizationPermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Company routes outside /programs and /capital (events, organization providers): a viewer reads only, an editor never
 * deletes, a deactivated member does nothing (OrganizationPermission). People outside a company team are not affected.
 */
class OrganizationRoleMiddleware
{
    public function handle(Request $request, Closure $next, ?string $prefix = null): Response
    {
        if ($denied = OrganizationPermission::check($request, $prefix)) {
            return $denied;
        }

        return $next($request);
    }
}
