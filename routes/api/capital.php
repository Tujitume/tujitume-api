<?php


use App\Http\Controllers\Capital\CapitalController;
use App\Http\Controllers\CheckoutStripeController;
use App\Http\Controllers\Grant\GrantServiceController;
use App\Http\Controllers\Misc\AnalyticsController;
use App\Http\Controllers\Misc\MatchController;

Route::prefix('/capital')->middleware(['capital'])->group(function(){
    //Capital
    Route::post('create-capital-offer', [CapitalController::class, 'store']);
    Route::post('investment-application',[CapitalController::class,'store_application']);
    Route::get('capital-offers', [CapitalController::class, 'index']);
    Route::get('pitches/{capital_id}', [CapitalController::class, 'pitches']);
    Route::get('pitch/{id}', [CapitalController::class, 'pitch_details']);
    Route::get('get_capital/{id}', [CapitalController::class, 'get_capital']);
    Route::get('my_pitches', [CapitalController::class, 'mypitches']);
    Route::post('update-capital', [CapitalController::class, 'update']);
    Route::get('visibility/{capital_id}', [CapitalController::class, 'visibility']);
    Route::get('delete-capital/{id}', [CapitalController::class, 'destroy']);
    Route::get('accept/{pitch_id}', [CapitalController::class, 'accept']);
    Route::get('reject/{pitch_id}', [CapitalController::class, 'reject']);
    Route::get('fund-release-request/{pitch_id}', [CapitalController::class, 'fund_request']);
    Route::post('match-score/{capital_id}', [MatchController::class, 'score_capital']);
    Route::post('capital-milestone', [CheckoutStripeController::class, 'capitalDisbursement']);
    Route::get('terms-agreements/{pitch_id}', [CapitalController::class, 'terms_agreements']);
    Route::post('terms-agreements', [CapitalController::class, 'terms_agreements_store']);
    Route::post('terms-agreements/update', [CapitalController::class, 'terms_agreements_update']);
    Route::get('analytics', [AnalyticsController::class, 'index_capital']);


    Route::get('grant-writing-services', [GrantServiceController::class, 'grantWritingServices']);
    Route::get('pitch-coaching-services', [GrantServiceController::class, 'pitchCoachingServices']);
    Route::get('store-watchlist/{pitch_id}', [CapitalController::class, 'store_watchlist']);
    Route::get('get-watchlist', [CapitalController::class, 'get_watchlist']);
    Route::post('update-profile', [CapitalController::class, 'update_profile']);
    Route::post('delete-user',[CapitalController::class,'delete_user']);
    Route::post('delete/role-user',[CapitalController::class,'delete_roleUser']);
    Route::post('update-user',[CapitalController::class,'update_user']);

});
