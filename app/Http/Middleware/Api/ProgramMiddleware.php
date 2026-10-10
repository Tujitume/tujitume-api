<?php

namespace App\Http\Middleware\Api;;

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
        $isEntrepreneur = Auth::user()?->user_type_id === 1;

        $user = Auth::user();
        if (! $user) {
            return $next($request);
        }


        $user->load('organizationRole.role');
        $role = $user->organizationRole?->role?->name;

        // KYC verification required for POST requests
        $user->load('kycVerification');

        if ( ( ($isOrgnizationUser && $role ==='super-admin') || $isEntrepreneur) && $user->kycVerification?->status !== 'verified') {
            return response()->json([
                'message' => 'KYC verification is required to access program functionality.',
                'status' => 403,
            ], 403);
        }

    
        $editorForbidden = ['delete-program', 'create-program', 'update-profile','delete/role-user','delete-user'];
        $viewerForbidden = [ 'accept', 'reject', 'update-program', 'visibility','store-watchlist','delete/role-user','delete-user'];

        if($user->user_type_id == 4) {
            if($role == 'editor')
            {
                // Editors create and edit records but never delete them
                if(in_array($route_name, $editorForbidden) || $method == 'DELETE'){
                    return response()->json(['success' => false, 'code' => 'forbidden', 'message' => "Your role doesn't allow this action.", 'error' => "Your role doesn't allow this action."], 403);
                }
            }
            if($role == 'viewer')
            {
                // Viewers read only: every method that changes data is refused
                if(! in_array($method, ['GET', 'HEAD', 'OPTIONS'])){
                    return response()->json(['success' => false, 'code' => 'forbidden', 'message' => "Your role doesn't allow this action.", 'error' => "Your role doesn't allow this action."], 403);
                }
                else {
                    if(in_array($route_name, $viewerForbidden)){
                        return response()->json(['success' => false, 'code' => 'forbidden', 'message' => "Your role doesn't allow this action.", 'error' => "Your role doesn't allow this action."], 403);
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
