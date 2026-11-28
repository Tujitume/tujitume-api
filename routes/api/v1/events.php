<?php


use App\Http\Controllers\Misc\EventController;

// Company roles apply to an organization's events: a viewer only reads them, an editor never deletes them
Route::middleware('org.role')->group(function () {
    Route::resource('events', EventController::class);
    Route::post('event-update', [EventController::class, 'update']);
    Route::post('event-deactivate', [EventController::class, 'deactivate']);
    Route::post('event-activate', [EventController::class, 'activate']);
});
