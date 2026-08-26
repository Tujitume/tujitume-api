<?php

namespace App\Http\Middleware;

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

        $user = Auth::user()->load('organizationRole.role');
        $role = $user->organizationRole->role?->name;
        $editorForbidden = ['delete-program', 'create-program', 'update-profile','delete/role-user','delete-user'];
        $viewerForbidden = [ 'accept', 'reject', 'update-program', 'visibility','store-watchlist','delete/role-user','delete-user'];

        if($user->user_type_id == 4) {
            if($role == 'editor')
            {
                if(in_array($route_name, $editorForbidden)){
                    return response()->json(['message' => 'Unauthorized','line' => __LINE__, 'status' => 401], 400);
                }
            }
            if($role == 'viewer')
            {
                if($method == 'POST'){
                    return response()->json(['message' => 'Unauthorized','line' => __LINE__, 'status' => 401], 400);
                }
                else {
                    if(in_array($route_name, $viewerForbidden)){
                        return response()->json(['message' => 'Unauthorized','line' => __LINE__, 'status' => 401], 400);
                    }
                }
            }

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
