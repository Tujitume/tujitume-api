<?php

use App\Http\Controllers\CheckoutStripeController;
use App\Http\Controllers\Mpesa\MpesaCallbackController;
use App\Http\Controllers\Mpesa\MpesaPollingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Routes organized into modules for better maintainability
*/

Route::prefix('v1')->group(function () {
    // THIRD PARTY CALLBACKS (Public)
    // =============================================================
    Route::post('/lipr-callback', [MpesaPollingController::class, 'callback']);
    Route::post('/stripe-callback', [CheckoutStripeController::class, 'callback']);

    // programs
    Route::post('/lipr-callback-program-escrow', [MpesaCallbackController::class, 'callbackProgramEscrow']);

    Route::post('/lipr-callback-program-direct', [MpesaCallbackController::class, 'callbackProgramDirectDisburse']);
    Route::post('/lipr-callback-program-supplier', [MpesaCallbackController::class, 'callbackForProgramSupplier']);

    // PUBLIC ROUTES (No Auth Required)
    // =============================================================
    require __DIR__.'/api/v1/public.php';

    // AUTHENTICATION ROUTES
    // =============================================================
    require __DIR__.'/api/v1/auth.php';

    // PROTECTED ROUTES (Auth Required)
    // =============================================================
    Route::middleware(['auth:sanctum', 'extend-token', 'throttle:api'])->group(function () {

        // Account & Security
        require __DIR__.'/api/v1/account.php';

        // KYC / KYB (sensitive account verification)
        require __DIR__.'/api/v1/kyc.php';

        // Organizations
        require __DIR__.'/api/v1/organization.php';

        // Business & Listings
        require __DIR__.'/api/v1/business.php';

        require __DIR__.'/api/v1/milestones.php';

        require __DIR__.'/api/v1/dealroom.php';

        // Services
        require __DIR__.'/api/v1/services.php';

        // Programs
        require __DIR__.'/api/v1/programs.php';

        // Capital
        require __DIR__.'/api/v1/capital.php';

        // Payments & Wallet
        require __DIR__.'/api/v1/payments.php';

        // Social & Communication
        require __DIR__.'/api/v1/social.php';

        // AI Routes
        require __DIR__.'/api/v1/ai.php';

        // Events
        require __DIR__.'/api/v1/events.php';

        // Misc/Utilities
        require __DIR__.'/api/v1/misc.php';
    });
});

// TERMS & PRIVACY (Public)
// =============================================================
Route::get('terms', function () {
    return view('policy.terms');
})->name('terms');
Route::get('policy', function () {
    return view('policy.privacy_policy');
})->name('policy');

// UTILITY ROUTES
// =============================================================
Route::get('/clear', function () {
    \Artisan::call('config:cache');
    \Artisan::call('view:clear');
    \Artisan::call('route:clear');
    \Artisan::call('cache:clear');
    dd('Cache is cleared');
});

// CATCH-ALL ROUTE  (Must be last)
// =============================================================
// Route::get('{/anypath}', [\App\Http\Controllers\PagesController::class, 'home'])->where('path', '.*');
