<?php

use App\Http\Controllers\Kyc\KycController;
use Illuminate\Support\Facades\Route;

Route::prefix('kyc')->group(function () {
    Route::get('/', [KycController::class, 'show'])->name('kyc.show');
    Route::get('status', [KycController::class, 'status'])->name('kyc.status');
    Route::post('/', [KycController::class, 'start'])->name('kyc.start');
    Route::patch('/', [KycController::class, 'update'])->name('kyc.update');
    Route::post('submit', [KycController::class, 'submit'])->name('kyc.submit');
    Route::post('documents', [KycController::class, 'upload'])->name('kyc.documents.upload');
    Route::delete('documents/{document}', [KycController::class, 'destroyDocument'])->name('kyc.documents.destroy');
    Route::post('submit', [KycController::class, 'submit'])->name('kyc.submit');
});
