<?php

/*
|--------------------------------------------------------------------------
| Public Routes (No Auth Required)
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Account\UserController;
use App\Http\Controllers\Account\WalletController;
use App\Http\Controllers\Auth\SocialController;
use App\Http\Controllers\Business\AssetBidsController;
use App\Http\Controllers\Business\BidsController;
use App\Http\Controllers\Business\BusinessController;
use App\Http\Controllers\Business\MilestoneController;
use App\Http\Controllers\Capital\CapitalController;
use App\Http\Controllers\Grant\GrantController;
use App\Http\Controllers\Grant\GrantDisbursementController;
use App\Http\Controllers\Misc\AiController;
use App\Http\Controllers\Misc\EventController;
use App\Http\Controllers\Misc\LookupController;
use App\Http\Controllers\Misc\MiscController;
use App\Http\Controllers\Misc\SearchController;
use App\Http\Controllers\Misc\SupportController;
use App\Http\Controllers\Service\BookingController;
use App\Http\Controllers\Service\ServiceController;
use App\Http\Controllers\Service\ServiceMilestoneController;

// grant supplier confirm
Route::get('grant/disbursements/{disbursement}/supplier-confirm', [GrantDisbursementController::class, 'supplierConfirm']);

Route::get('/locations/{query}', [SearchController::class,'locations']);
Route::get('/get-conversion-rate/{base}', [WalletController::class,'conversion_rate']);
Route::get('all-events', [EventController::class, 'browse']);
Route::get('/home/grants', [GrantController::class, 'index']);

Route::get('grant/reject-invitation/{email}', [GrantController::class, 'reject_invitation'])
    ->name('reject.invitation')
    ->middleware('signed');
Route::get('/home/capital-offers', [CapitalController::class, 'index']);

// L A N D I N G   &   S E A R C H
Route::middleware('throttle:public')->group(function () {

    Route::get('listing/{id}', [BusinessController::class,'listing'])->name('listing');

    Route::get('latBusiness', [BusinessController::class, 'featuredListings']);
    Route::get('latServices', [ServiceController::class, 'featuredServices']);

    Route::get('searchResults/{ids}', [BusinessController::class, 'listingResultsByIds']);
    Route::get('ServiceResults/{ids}', [ServiceController::class, 'serviceResultsByIds']);
    Route::get('categoryResults/{catName}', [LookupController::class, 'listingsByCategory']);

    Route::get('categoryCount', [LookupController::class, 'listingCountByCategory']);
    Route::get('categories', [MiscController::class, 'categories']);
    Route::get('service-categories', [MiscController::class, 'serviceCategories']);

    // Search methods should be GET
    Route::post('search', [SearchController::class, 'search']);
    Route::post('searchService', [SearchController::class, 'searchService']);

    Route::get('priceFilter/{min}/{max}/{ids}', [LookupController::class, 'filterListingsByTurnover']);
    Route::get('priceFilterS/{min}/{max}/{ids}', [LookupController::class, 'filterServicesByPrice']);
    Route::get('priceFilter_amount/{min}/{max}/{ids}', [LookupController::class, 'filterListingsByAmount']);


    Route::get('getMilestones/{id}', [MilestoneController::class, 'getMilestones'])->name('getMilestones');
    Route::get('getMilestonesS/{id}', [ServiceMilestoneController::class, 'getMilestones'])->name('getMilestonesS');



    // A I   P U B L I C
    Route::prefix('/ai')->group(function(){
        Route::get('listing/{listing_id}/suggestions', [AiController::class,'listing_suggestions']);
        Route::get('service/{service_id}/suggestions', [AiController::class,'service_suggestions']);
        Route::get('grant/{grant_id}/suggestions', [AiController::class,'grant_suggestions']);
        Route::get('capital/{capital_id}/suggestions', [AiController::class,'capital_suggestions']);
    });
});

Route::get('JitumeSubscribeEmail/{email}', [SupportController::class, 'newsletterSubscribe']);
Route::post('submitReport', [SupportController::class, 'reportListing']);
Route::post('contact/request-demo', [SupportController::class, 'requestDemo']);

Route::get('CancelAssetBid/{id}/{action}',[AssetBidsController::class,'CancelAssetBid']);
Route::get('CancelEquipmentRelease/{id}/{action}',[AssetBidsController::class,'CancelAssetRelease']);
Route::get('CancelServiceBooking/{id}/{action}',[BookingController::class,'CancelServiceBooking']);

Route::get('grant/get_grant/{id}', [GrantController::class, 'get_grant']);
Route::get('capital/get_capital/{id}', [CapitalController::class, 'get_capital']);

Route::get('agreeToMileS/{s_id}/{booker_id}', [ServiceMilestoneController::class, 'agreeToProgressWithServiceMilestone']);
Route::get('agreeToProgressWithMilestone/{bidId}', [BidsController::class, 'agreeToProgressWithMilestone']);
Route::get('agreeToNextmile/{bidId}', [BidsController::class, 'agreeToNextmile'])->name('agreeToNextmile');
Route::get('reviewMilestoneS/{s_id}/{booker_id}', [ServiceMilestoneController::class, 'reviewMilestone']);

Route::get('/error-logs', [MiscController::class, 'errorLogs']);
