<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Misc\SettingController;

// ==================== Admin Authentication ====================
Route::prefix('admin')->group(function () { //->name('admin.')

    // Guest routes (no admin logged in)
    Route::middleware('guest:admin')->group(function () {
        Route::get('/', [AdminController::class, 'login']);
        Route::get('/login', [AdminController::class, 'login'])->name('admin.login');

        Route::get('/forgot/{remail}', [AdminController::class, 'forgot'])->name('forgot');
        Route::post('/send_reset_email', [AdminController::class, 'sendResetEmail'])->name('send_reset_email');
        Route::post('/reset/{remail}', [AdminController::class, 'reset'])->name('reset');

        Route::post('/login', [AdminController::class, 'adminLogin'])->name('login.submit');

        Route::post('/adminLogin', [AdminController::class, 'adminLogin'])->name('adminLogin');
    });

    // Protected admin routes (admin must be logged in)
    Route::middleware('auth:admin')->group(function () {
        Route::get('/logout', [AdminController::class, 'logout'])->name('logout');

        // Dashboard
        Route::get('/index', [AdminController::class, 'index'])->name('index');
        Route::get('/dashboard', [AdminController::class, 'index'])->name('admin.dashboard');

        // Users / Disputes
        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::post('/users/{id}/delete', [AdminController::class, 'deleteUser'])->name('del_users');
        Route::get('/disputes/{dispute}/remove', [AdminController::class, 'removeDispute'])->name('remove_dispute');
        Route::get('/disputes', [AdminController::class, 'disputes'])->name('disputes');

        // Search
        Route::post('/search', [AdminController::class, 'searchInAdmin'])->name('searchInAdmin');

        // Listings / Services
        Route::get('/listings-active', [AdminController::class, 'listings_active'])->name('listings_active');
        Route::get('/services-active', [AdminController::class, 'services_active'])->name('services_active');

        // Prospects / Reports
        Route::get('/prospects', [AdminController::class, 'prospects'])->name('prospects');
        Route::get('/reports', [AdminController::class, 'reports'])->name('reports');
        Route::get('/otherReports/{report}', [AdminController::class, 'otherReports'])->name('other_reports');
        Route::get('/reportDownload/{report}', [AdminController::class, 'reportDownload'])->name('report_download');

        // Transactions / Grants / Capitals / Events
        Route::get('/transactions', [AdminController::class, 'transactions'])->name('transactions');
        Route::get('/grants', [AdminController::class, 'grants'])->name('grants');
        Route::get('/capitals', [AdminController::class, 'capitals'])->name('capitals');
        Route::get('/events', [AdminController::class, 'events'])->name('events');
        Route::get('/events/toggle/{event}', [AdminController::class, 'toggleEvent'])->name('admin.events.toggle');

        // Milestones
        Route::get('/milestones', [AdminController::class, 'milestones'])->name('milestones');

        // Bulk emails
        Route::get('/bulk-emails', [AdminController::class, 'bulkEmails'])->name('bulk_emails');
        Route::post('/send-bulk-register-mails', [AdminController::class, 'bulkRegisterEmails'])->name('send-bulk-register-mails');

        // Error logs
        Route::get('/error-logs', [AdminController::class, 'errorLogs'])->name('error_logs');

        // Settings resource
        Route::resource('settings', SettingController::class)->names([
            'index' => 'settings.index',
            'create' => 'settings.create',
            'store' => 'settings.store',
            'show' => 'settings.show',
            'edit' => 'settings.edit',
            'update' => 'settings.update',
            'destroy' => 'settings.destroy'
        ]);

        // Static pages
        Route::get('/reviews', function () { return view('admin.reviews'); })->name('reviews');
    });
});
