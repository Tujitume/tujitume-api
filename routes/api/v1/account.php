<?php

/*
|--------------------------------------------------------------------------
| Account & Security Routes
|--------------------------------------------------------------------------
*/

// 2FA & Account
use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\UserController;
use App\Http\Controllers\Account\UserSettingController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\DeviceController;
use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\DB;

Route::get('/me/sessions', [SessionController::class, 'index']);
Route::delete('/me/sessions/{id}', [SessionController::class, 'destroy']);
Route::delete('/me/sessions', [SessionController::class, 'destroyAllExceptCurrent']);
Route::get('/me/devices', [DeviceController::class, 'index']);
Route::patch('/me/devices/{device}', [DeviceController::class, 'update']);
Route::delete('/me/devices/{device}', [DeviceController::class, 'destroy']);

Route::get('logout-all', [AuthController::class, 'logoutAll']);

Route::get('account', [AccountController::class, 'account_wallet'])->name('account');

// User Resource
Route::get('users/types', function () {
    $types = DB::table('user_types')->get();

    return response()->json($types);
});

Route::get('users', [UserController::class, 'index']);
Route::get('users/me', [UserController::class, 'me']);
Route::delete('users/{id}', [UserController::class, 'destroy']);
Route::delete('organizations/team-members/{teamMember}', [UserController::class, 'destroyOrgTeamMember']);
Route::patch('organizations/team-members/{teamMember}/status', [UserController::class, 'updateOrgTeamMemberStatus']);

Route::get('/partiesInfo/{listing_id}', [UserController::class, 'partiesInfo']);
Route::get('/partiesServiceMile/{rep_mile_id}', [UserController::class, 'getServiceOwner']);
Route::get('organizations/team-members', [UserController::class, 'organizationTeamMembers']);

Route::get('/transactions', [AccountController::class, 'transactions']);
Route::get('/withdraw-history', [AccountController::class, 'withdraws']);

Route::get('profile/{id}', [UserController::class, 'profile']);
Route::post('profile/edit/{id}', [UserController::class, 'updateProfile']);

// Settings

Route::get('user/settings', [UserSettingController::class, 'show']);
Route::get('user/settings/logo', [UserSettingController::class, 'logo']);
Route::patch('user/settings', [UserSettingController::class, 'update']);
Route::delete('user/settings/reset', [UserSettingController::class, 'reset']);
