<?php

use App\Http\Controllers\Account\WalletController;
use App\Http\Controllers\Business\SubscriptionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    echo 'Redirecting to beta...';
    echo "<script>window.location.href='https://beta.tujitume.com/'</script>";

});

// Subscription Routes | Stripe
Route::get(
    '/stripeSubscribe/{amount}/{plan}/{days}/{range}/{inv}', [SubscriptionController::class, 'subscriptionInitiate']
);
Route::get('/stripeSubscribeSuccess', [SubscriptionController::class, 'stripeSuccess']);

// Onboarding Routes | Stripe
Route::get('/connect/{id}', [WalletController::class, 'stripeOnboardingInitiate'])->name('connect.stripe');
Route::get('/saveStripe/{token}', [WalletController::class, 'onboardingSuccess'])->name('return.stripe');

# A D M I N
Route::get('admin/login', function () {return view('admin.login');})->name('loginA');

require __DIR__ . '/api/admin.php';

# Unused
Route::post('/login-from-token', function (Request $request) {
    $token = $request->bearerToken();
    if (!$token) {
        return response()->json(['message' => 'Token missing'], 401);
    }
    $accessToken = PersonalAccessToken::findToken($token);
    if (!$accessToken) {
        return response()->json(['message' => 'Invalid token'], 401);
    }
    $user = $accessToken->tokenable; // The user associated with the token
    Auth::guard('web')->login($user); // log in via web guard
    $request->session()->regenerate();
    return response()->json(['message' => 'Logged in via token']);
});

# PayStack  ROUTES for Test
/*
    Route::get('/mpesaStk', [CheckoutMpesaController::class,'stk']);
    Route::get('/AccountBalance', [CheckoutMpesaController::class,'AccountBalance']);
    Route::get('/b2cSplit', [CheckoutMpesaController::class,'b2c_Split']);

    Route::get('/initialize', [PayStackController::class, 'initialize']);
    Route::get('/create-sub-account', [PayStackController::class, 'create_sub_account']);
    Route::get('/verify', [PayStackController::class, 'verify']);
    Route::get('/transfer', [PayStackController::class, 'transfer_funds']);
    Route::get('paypal-payment',[PayPalController::class,"payment"])->name('paypal.payment');
    Route::get('paypal-success',[PayPalController::class,"success"])->name('paypal.success');
    Route::get('paypal-cancel',[PayPalController::class,'cancel'])->name('paypal.cancel');
 */

