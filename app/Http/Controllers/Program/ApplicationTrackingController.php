<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Programs\ProgramApplication;
use App\Service\Program\ApplicationTrackingLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationTrackingController extends Controller
{
    /** Validate a tracking link and say what the applicant should do next. Reveals nothing beyond the link's own email. */
    public function show(Request $request, string $uid): JsonResponse
    {
        $application = $this->resolve($request, $uid);
        if (!$application) {
            return response()->json(['message' => 'This tracking link is invalid or has expired.'], 404);
        }

        $email = strtolower((string) $application->email);

        return response()->json(['data' => [
            'uid' => $application->uid,
            'program_title' => $application->program?->program_title,
            'email' => $this->mask($email),
            // The real address, so the register form can pre-fill it (the person may change it). Safe: this signed link was
            // emailed only to that address and is checked by resolve() before anything is returned.
            'contact_email' => $email,
            'account_exists' => User::whereRaw('LOWER(email) = ?', [$email])->exists(),
            'linked' => $application->user_id !== null,
        ]]);
    }

    /** Link the application to the signed-in account. Safe to repeat: reopening the link never duplicates anything. */
    public function claim(Request $request, string $uid): JsonResponse
    {
        $application = $this->resolve($request, $uid);
        if (!$application) {
            return response()->json(['message' => 'This tracking link is invalid or has expired.'], 404);
        }

        $user = $request->user();

        if ($application->user_id === null) {
            $application->forceFill(['user_id' => $user->id])->save();
            $application->adoptApplicantName($user);
        } elseif ((int) $application->user_id !== (int) $user->id) {
            return response()->json(['message' => 'This application is already linked to another account.'], 409);
        }

        return response()->json([
            'message' => 'Application linked to your account.',
            'data' => ['uid' => $application->uid, 'linked' => true],
        ]);
    }

    private function resolve(Request $request, string $uid): ?ProgramApplication
    {
        if (!ApplicationTrackingLink::valid($uid, $request->input('e'), $request->input('t'))) {
            return null;
        }

        return ProgramApplication::with('program:id,program_title')->where('uid', $uid)->first();
    }

    private function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return $name === '' ? $email : substr($name, 0, 1) . str_repeat('*', max(2, strlen($name) - 1)) . '@' . $domain;
    }
}
