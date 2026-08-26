<?php
// A I  R O U T E S
use App\Http\Controllers\Misc\AiController;

Route::prefix('/ai')->group(function(){
    //Program
    Route::get('investor/listings', [AiController::class, 'index']);
    Route::get('investor/services',[AiController::class,'index_s']);
    Route::get('business/{business_id}/services',[AiController::class,'index_ls']);
    Route::get('capital/market_analysis',[AiController::class,'capital_market_analysis']);
});
// A I  R O U T E S  E N D S
