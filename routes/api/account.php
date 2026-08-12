<?php


/*
|--------------------------------------------------------------------------
| Account & Security Routes
|--------------------------------------------------------------------------
*/

// 2FA & Account
use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\DeviceController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Account\UserSettingController;

Route::get('/me/sessions', [SessionController::class,'index']);
Route::delete('/me/sessions/{id}', [SessionController::class,'destroy']);
Route::delete('/me/sessions', [SessionController::class,'destroyAllExceptCurrent']);
Route::get('/me/devices', [DeviceController::class,'index']);
Route::patch('/me/devices/{device}', [DeviceController::class,'update']);
Route::delete('/me/devices/{device}', [DeviceController::class,'destroy']);

Route::get('logout',[AuthController::class,'logout']);
Route::get('logout-all',[AuthController::class,'logoutAll']);
Route::get('account/delete/{id}', [AccountController::class, 'destroy']);

Route::get('account', [AccountController::class, 'account_wallet'])->name('account');

// User Resource
Route::get('users', [UserController::class, 'index']);
Route::get('users/me', [UserController::class, 'me']);

Route::get('users/{id}', [UserController::class, 'fetchUser'])
    ->where('id', '[0-9]+'); // only numeric IDs allowed

Route::get('/partiesInfo/{listing_id}', [UserController::class,'partiesInfo']);
Route::get('/partiesServiceMile/{rep_mile_id}', [UserController::class,'getServiceOwner']);
Route::get('RoleBasedUsers',[UserController::class,'RoleBasedUsers']);

Route::get('/transactions', [AccountController::class,'transactions']);
Route::get('/withdraw-history', [AccountController::class,'withdraws']);

Route::get('profile/{id}', [UserController::class, 'profile']);
Route::post('profile/edit/{id}', [UserController::class, 'updateProfile']);

//Settings

Route::get('user/settings',        [UserSettingController::class, 'show']);
Route::patch('user/settings',      [UserSettingController::class, 'update']);
Route::delete('user/settings/reset', [UserSettingController::class, 'reset']);
