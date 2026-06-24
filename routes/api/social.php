<?php
// m e s s a g e s

use App\Http\Controllers\Misc\LikeController;
use App\Http\Controllers\Misc\MessageController;
use App\Http\Controllers\Misc\WatchlistController;

Route::apiResource('watchlists',WatchlistController::class);
Route::apiResource('messages',MessageController::class);
Route::post('messages/mark-read', [MessageController::class, 'markAsRead']);

Route::get('likes', [LikeController::class, 'index']);
Route::post('like-toggle', [LikeController::class, 'toggle']);
Route::post('notifications/clear', [LikeController::class, 'clear_notification']);
Route::post('notifications/update', [LikeController::class, 'update_notification']);
//   A P I/  R O U T E S  E N D S
