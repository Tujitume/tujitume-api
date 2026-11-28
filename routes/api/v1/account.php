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

// Everything waiting for the signed-in person (the Pending drawer and its badge)
// Keep-alive: the app calls this while the person is active on a page that makes no other requests,
// so the idle timeout only ends sessions of people who are really away. Using the token is what keeps it alive.
Route::get('session/ping', fn () => response()->json(['success' => true, 'idle_timeout_minutes' => (int) config('sanctum.idle_timeout', 60)]));

Route::get('/pending-tasks', [\App\Http\Controllers\Account\PendingTasksController::class, 'index']);

Route::get('/me/sessions', [SessionController::class, 'index']);
Route::delete('/me/sessions/{id}', [SessionController::class, 'destroy']);
Route::delete('/me/sessions', [SessionController::class, 'destroyAllExceptCurrent']);
Route::get('/me/devices', [DeviceController::class, 'index']);
Route::patch('/me/devices/{device}', [DeviceController::class, 'update']);
Route::delete('/me/devices/{device}', [DeviceController::class, 'destroy']);

Route::post('logout-all', [AuthController::class, 'logoutAll']); // Settings > Security: end every sign-in of this account
Route::get('logout-all', [AuthController::class, 'logoutAll']); // older clients

Route::get('account', [AccountController::class, 'account_wallet'])->name('account');

// User Resource
Route::get('users/types', function () {
    $types = DB::table('user_types')->get();

    return response()->json($types);
});

Route::get('users', [UserController::class, 'index']);
Route::get('users/me', [UserController::class, 'me']);
// Individual investors: what they look for (Business matches scores)
Route::patch('users/me/investor-preferences', [\App\Http\Controllers\Investor\InvestorPreferencesController::class, 'update']);
Route::get('users/{id}/contact', [UserController::class, 'contact'])->whereNumber('id');
Route::delete('users/{id}', [UserController::class, 'destroy']);
Route::delete('organizations/team-members/{teamMember}', [UserController::class, 'destroyOrgTeamMember']);
Route::patch('organizations/team-members/{teamMember}/status', [UserController::class, 'updateOrgTeamMemberStatus']);

Route::get('/partiesInfo/{listing_id}', [UserController::class, 'partiesInfo']);
Route::get('/partiesServiceMile/{rep_mile_id}', [UserController::class, 'getServiceOwner']);
Route::get('organizations/team-members', [UserController::class, 'organizationTeamMembers']);
// Who changed what in the organization, with old and new values (owner and super admins only)
Route::get('organizations/audit-logs', [\App\Http\Controllers\Organizations\OrganizationAuditLogController::class, 'index']);

Route::get('/transactions', [AccountController::class, 'transactions']);
Route::get('/withdraw-history', [AccountController::class, 'withdraws']);

Route::get('profile/{id}', [UserController::class, 'profile']);
Route::patch('profile/update/{id}', [UserController::class, 'updateProfile']);

// Settings

Route::get('user/settings', [UserSettingController::class, 'show']);
Route::get('user/settings/logo', [UserSettingController::class, 'logo']);
Route::patch('user/settings', [UserSettingController::class, 'update']);
Route::delete('user/settings/reset', [UserSettingController::class, 'reset']);
