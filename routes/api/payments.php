<?php

/*
|--------------------------------------------------------------------------
| Payment & Wallet Routes
|--------------------------------------------------------------------------
*/

// Stripe Connect
use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\WalletController;
use App\Http\Controllers\Account\WithdrawController;
use App\Http\Controllers\CheckoutMpesaController;
use App\Http\Controllers\CheckoutStripeController;
use App\Http\Controllers\Grant\GrantDisbursementController;
use App\Http\Controllers\Misc\PaymentMethodController;
use App\Http\Controllers\Mpesa\MpesaPollingController;

// Onboarding Routes | Stripe
Route::get('/connect/{id}', [WalletController::class, 'stripeOnboardingInitiate'])->name('connect.stripe');
Route::get('/saveStripe/{token}', [WalletController::class, 'onboardingSuccess'])->name('return.stripe');

// LIPR Onboarding & Subscription
Route::post('/lipr-onboard', [WalletController::class,'lipr_onboarding']);
Route::post('/lipr-subscription-initiate', [AccountController::class,'lipr_subscription_initiate']);
Route::post('/lipr-subscription', [AccountController::class,'lipr_subscription']);

// Unlock & Payments
Route::post('/stripe.post.coversation', [CheckoutStripeController::class, 'UnlockListing'])->name('stripe.post.coversation');
Route::post('bidCommits', [CheckoutStripeController::class, 'bidCommitPayment'])->name('bidCommits');
Route::post('bidCommitsAwaiting', [CheckoutStripeController::class, 'bidAwaitingPayment']);
Route::post('milestoneService', [CheckoutStripeController::class, 'PayServiceFee'])->name('milestoneService.post');
Route::post('milestone/rmepFundsCommit', [CheckoutStripeController::class, 'rmepFundsCommit'])->name('rmepFundsCommit');

// LIPR Initiate payment
Route::post('/lipr/initiate-payment', [CheckoutMpesaController::class,'initiate_payment']);

Route::post('milestones/{milestone}/release-funds', [GrantDisbursementController::class, 'releaseFunds']);

// Polling
Route::post('/lipr-status-bids', [MpesaPollingController::class,'status_bids']);
Route::post('/lipr-status-bidsAwaiting', [MpesaPollingController::class,'status_bidsAwaiting']);
Route::post('/lipr-status-service', [MpesaPollingController::class,'status_service']);
Route::post('/lipr-status-smallFee', [MpesaPollingController::class,'status_smallFee']);

Route::post('/lipr-status-grant', [MpesaPollingController::class,'grantDisbursementStatus']);

Route::post('/lipr-status-grant-bulk', [CheckoutMpesaController::class,'grant_milestone_bulk']);
Route::post('/lipr-status-capital', [MpesaPollingController::class,'capitalDisbursementStatus']);


// Wallet Balances & Deposits
Route::get('/wallet-balances', [WalletController::class,'wallet_balances']);
Route::post('/stripe/deposit', [WalletController::class,'stripe_deposit']);
Route::post('/lipr/deposit', [WalletController::class,'lipr_deposit']);
Route::post('/lipr/deposit-status', [WalletController::class,'lipr_deposit_status']);

// Withdrawals
Route::post('/stripe/withdraw', [WithdrawController::class,'stripe_withdraw']);
Route::post('/lipr/initiate-withdraw', [WithdrawController::class,'mobile_initiate_withdraw']);
Route::post('/lipr/withdraw-status', [WithdrawController::class,'mobile_withdraw_status']);
Route::post('/lipr/paybill-initiate-withdraw', [WithdrawController::class,'paybill_initiate_withdraw']);
Route::post('/lipr/paybill-withdraw-status', [WithdrawController::class,'paybill_withdraw_status']);
Route::post('/lipr/till-initiate-withdraw', [WithdrawController::class,'till_initiate_withdraw']);
Route::post('/lipr/till-withdraw-status', [WithdrawController::class,'till_withdraw_status']);

Route::prefix('payment-methods')->group(function () {
    Route::get('/',        [PaymentMethodController::class, 'index']);
    Route::post('/',       [PaymentMethodController::class, 'store']);
    Route::delete('/{id}', [PaymentMethodController::class, 'destroy']);
});
