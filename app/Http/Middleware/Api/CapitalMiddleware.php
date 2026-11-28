<?php

namespace App\Http\Middleware\Api;

use App\Service\Capital\CapitalAccess;
use App\Service\Organization\OrganizationPermission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capital routes (/capital/...).
 *
 *  - Capital providers (user type 5, owner or team member): the company-role rules of OrganizationPermission (viewer:
 *    read only, editor: no deleting, team and settings for the owner and super admins). They cannot apply to offers.
 *  - Entrepreneurs (type 1): discover offers, apply, score a draft application, their own applications, terms and
 *    fund requests only.
 *  - Everyone else signed in (service providers, investors, program organizations): discover and read offers only.
 *
 * Each controller method then checks the offer or application belongs to the caller (CapitalAccess).
 */
class CapitalMiddleware
{
    /** Anyone signed in */
    private const DISCOVER = ['capital-offers', 'get_capital/{id}', 'program-writing-services', 'pitch-coaching-services/{listing_id?}'];

    /** Entrepreneurs and capital providers (each sees their own side; the controller checks which) */
    private const SHARED = ['my_pitches', 'pitch/{id}', 'terms-agreements/{pitch_id}', 'terms-agreements', 'terms-agreements/update'];

    /** Entrepreneurs only */
    private const APPLICANT = ['investment-application', 'my-applications', 'match-score/{capital_id}', 'fund-release-request/{pitch_id}'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $path = preg_replace('#^(api/)?(v1/)?capital/#', '', (string) Route::current()?->uri());

        if (CapitalAccess::isCapital($user)) {
            if (in_array($path, self::APPLICANT, true)) {
                return OrganizationPermission::denied('Capital provider accounts cannot apply for capital.');
            }
            if ($denied = OrganizationPermission::check($request, 'capital')) {
                return $denied;
            }

            return $next($request);
        }

        // pitches/latest is the entrepreneur's own list; pitches/{id} of an offer is the provider's
        if ($path === 'pitches/{capital_id}' && $request->route('capital_id') === 'latest') {
            $path = 'my_pitches';
        }

        $allowed = in_array($path, self::DISCOVER, true)
            || ((int) $user?->user_type_id === 1 && (in_array($path, self::SHARED, true) || in_array($path, self::APPLICANT, true)));

        if (! $allowed) {
            return OrganizationPermission::denied(in_array($path, self::APPLICANT, true) || in_array($path, self::SHARED, true)
                ? 'Only business owners can apply for capital.'
                : 'Only capital providers can do this.');
        }

        return $next($request);
    }
}
