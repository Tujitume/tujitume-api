<?php

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/

// Login & Registration
use App\Http\Controllers\Account\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SocialController;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;

Route::post('auth/verify-device', [AuthController::class, 'verifyDevice']);
Route::post('login', [AuthController::class, 'login']);
Route::post('register', [RegisterController::class, 'register']);
Route::post('register/org-team-member', [RegisterController::class, 'registerRoleUser'])
    ->middleware('auth:sanctum');
Route::post('organization-invitations/accept', [UserController::class, 'acceptOrgTeamInvitation']);
Route::post('resetPassword', [UserController::class, 'resetPassword']);
Route::get('logout', [AuthController::class, 'logout']);

// Email Verification
Route::post('sendEmailOtp', [AuthController::class, 'sendEmailVerification']);
Route::post('verifyEmailOtp', [AuthController::class, 'verifyEmailCode']);
// Route::get('emailVerify/{email}/{code}', [AuthController::class,'emailVerify']);

Route::get('emailExists/{email}', [UserController::class, 'checkEmailExists']);

// Social Login
Route::get('social_login', function () {
    return view('social_types');
})->name('social_login');
Route::get('/google', function () {
    return Socialite::driver('google')->stateless()->redirect();
})->name('login.google');
Route::get('google/callback', [SocialController::class, 'google']);

Route::get('/facebook', function () {
    return Socialite::driver('facebook')->stateless()->redirect();
})->name('login.facebook');
Route::get('facebook/callback', [SocialController::class, 'facebook']);
