<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Models\DemoRequest;
use App\Models\ProgramRegistrationInvite;
use App\Service\DemoRequests\DemoAccountHandoff;
use App\Service\DemoRequests\DemoRequestService;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Public side of the landing-page request form, the one-time sign-up link, and the trial account's
 * "Get full access" button. The admin side is Admin\DemoRequestAdminController.
 */
class DemoRequestController extends Controller
{
    public function __construct(private DemoRequestService $requests)
    {
        parent::__construct();
    }

    /** POST contact/request-demo (public, throttled) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'org' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:191'],
            'request_type' => ['required', 'string', Rule::in([...DemoRequest::REQUEST_TYPES, ...array_keys(DemoRequest::LEGACY_REQUEST_TYPES)])],
            'program_industry_id' => ['nullable', 'integer', 'exists:program_industries,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'timezone' => ['nullable', 'string', 'max:64', Rule::in(timezone_identifiers_list())],
        ]);
        $data['request_type'] = DemoRequest::normalizeRequestType($data['request_type']);
        $data['email'] = strtolower(trim($data['email']));

        if ($duplicate = $this->requests->openDuplicate($data['email'], $data['request_type'])) {
            return response()->json([
                'message' => $this->requests->duplicateMessage($duplicate),
                'code' => 'request_already_open',
                'status' => $duplicate->status,
            ], 409);
        }

        try {
            $this->requests->submit($data);

            return response()->json([
                'message' => $data['request_type'] === DemoRequest::TYPE_DEMO
                    ? 'Thank you. Your demo request was sent. We have emailed you a confirmation and will email you again once it is reviewed.'
                    : 'Thank you. Your request was sent. We have emailed you a confirmation and will reply soon.',
            ], 201);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /** GET program-invites/{token} (public): who the sign-up link is for, so the page can fill in and lock the email */
    public function invite(string $token): JsonResponse
    {
        $invite = ProgramRegistrationInvite::findUsable($token);
        if (! $invite) {
            return response()->json([
                'message' => 'This invitation link is invalid, has expired or has already been used.',
                'code' => 'invite_invalid',
            ], 404);
        }

        $r = $invite->demoRequest;
        [$first, $last] = array_pad(preg_split('/\s+/', trim((string) ($invite->name ?? $r?->name)), 2) ?: [], 2, '');
        // What they already gave in their demo account (its owner set a phone and display name on the way in)
        $demoUser = $r?->trial_user_id && $r->hasTrial() ? \App\Models\Auth\User::find($r->trial_user_id, ['id', 'first_name', 'last_name', 'phone', 'password']) : null;

        return response()->json(['data' => [
            'email' => $invite->email,
            'first_name' => $demoUser?->first_name ?: $first,
            'last_name' => $demoUser?->last_name ?: $last,
            'phone' => $demoUser?->phone ?: null,
            // true: the full account keeps the demo's password, so the page does not ask for one
            'password_set' => (bool) ($demoUser?->getAttributes()['password'] ?? null),
            'org_name' => $r?->org,
            'program_industry_id' => $r?->program_industry_id,
            'expires_at' => $invite->expires_at->toIso8601String(),
        ]]);
    }

    /** GET trial/status (signed in): is this a 7-day demo account, and until when */
    public function trialStatus(): JsonResponse
    {
        $r = $this->requests->forTrialUser(Auth::user());

        return response()->json(['data' => $r ? [
            'is_trial' => true,
            'expires_at' => $r->trial_expires_at?->toIso8601String(),
            'full_access_requested_at' => $r->full_access_requested_at?->toIso8601String(),
            'email' => $r->email,
            // Signed in from Admin › Demo Requests › Open demo account, not by the requester
            'admin_view' => self::isAdminView(),
        ] : ['is_trial' => false]]);
    }

    /** POST auth/admin-view (public, throttled): trades the one-time code from the admin page for a sign-in */
    public function adminView(Request $request, DemoAccountHandoff $handoff): JsonResponse
    {
        $token = $handoff->redeem($request->input('code'));
        if (! $token) {
            return response()->json(['message' => 'This link has expired or was already used. Open the demo account again from the admin page.'], 403);
        }

        return response()->json(['auth' => true, 'token' => $token]);
    }

    private static function isAdminView(): bool
    {
        $token = Auth::user()?->currentAccessToken();

        return is_object($token) && str_starts_with((string) ($token->name ?? ''), DemoAccountHandoff::TOKEN_PREFIX);
    }

    /** POST trial/request-full-access (signed in, demo account): the one-time sign-up link, opened straight away */
    public function requestFullAccess(): JsonResponse
    {
        $r = $this->requests->forTrialUser(Auth::user());
        if (! $r) {
            return response()->json(['message' => 'This is not a demo account.'], 403);
        }
        if (self::isAdminView()) {
            return response()->json(['message' => 'You are viewing this demo as an admin. Use "Give full access" on the admin page instead.'], 403);
        }

        try {
            $url = $this->requests->fullAccessFromDemo($r);

            return response()->json([
                'message' => 'Opening the sign-up for your full account.',
                'data' => [
                    'invite_url' => $url,
                    'full_access_requested_at' => $r->fresh()->full_access_requested_at?->toIso8601String(),
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['demo_request_id' => $r->id]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }
}
