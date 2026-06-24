<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class SocialController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Google Login
    |--------------------------------------------------------------------------
    */

    public function google()
    {
        try {
            $socialUser = Socialite::driver('google')->stateless()->user();
            return $this->handleSocialLogin($socialUser);
        } catch (\Exception $e) {
            Log::error('Google Social Login Failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Facebook Login
    |--------------------------------------------------------------------------
    */

    public function facebook()
    {
        try {
            $socialUser = Socialite::driver('facebook')->stateless()->user();
            return $this->handleSocialLogin($socialUser);
        } catch (\Exception $e) {

            ErrorLogService::report($e, [ 'input' => request()->except(['password','token']) ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Handle Social Login
    |--------------------------------------------------------------------------
    */

    protected function handleSocialLogin($socialUser)
    {
        try {

            if (!$socialUser->email) {
                return response()->json([
                    'message' => 'Your social account must have an email.'
                ], 422);
            }

            [$fname, $lname] = $this->parseName($socialUser->name);

            $user = User::where('email', $socialUser->email)->first();

            if (!$user) {
                $user = User::create([
                    'fname' => $fname,
                    'lname' => $lname,
                    'email' => $socialUser->email,
                    'password' => bcrypt(str()->random(32))
                ]);
            }

            // TODO: replace with sanctum token
            $token = 'a123456789';

            return redirect()->to(
                config('app.app_url') .
                '?user=' . json_encode($user) .
                '&token=' . $token
            );

        } catch (\Exception $e) {

            ErrorLogService::report($e, [ 'input' => request()->except(['password','token']) ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Name Parser
    |--------------------------------------------------------------------------
    */

    protected function parseName($name)
    {
        $parts = explode(' ', trim($name));

        $fname = $parts[0] ?? '';
        $lname = count($parts) > 1
            ? implode(' ', array_slice($parts, 1))
            : '';

        return [$fname, $lname];
    }
}
