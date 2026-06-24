<?php


use App\Http\Controllers\Misc\EventController;

Route::resource('events', EventController::class);
Route::post('event-update', [EventController::class, 'update']);
Route::post('event-deactivate', [EventController::class, 'deactivate']);
Route::post('event-activate', [EventController::class, 'activate']);
