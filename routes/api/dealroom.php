<?php

use App\Http\Controllers\Business\DealroomController;

/*
|--------------------------------------------------------------------------
| Deal Room Routes
|--------------------------------------------------------------------------
*/

Route::prefix('/deal-room')->group(function (){
    Route::get('businesses', [DealroomController::class, 'businesses']);
    Route::get('business/{businessId}/stats', [DealroomController::class, 'business_stats']);
    Route::get('business/{businessId}/participants', [DealroomController::class, 'participants']);
    Route::get('milestone/{milestoneId}/communications', [DealroomController::class, 'communications']);
    Route::post('milestone/communications', [DealroomController::class, 'communications_store']);
});
