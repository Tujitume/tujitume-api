<?php


use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Business\AssetBidsController;
use App\Http\Controllers\Business\BidsController;
use App\Http\Controllers\Business\BusinessController;
use App\Http\Controllers\Business\MilestoneController;
use App\Http\Controllers\Business\SubscriptionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Misc\DownloadController;

Route::prefix('/business')->group(function(){

    Route::post('update-profile', [UserController::class, 'updateProfile']);
    Route::post('bulk-import', [BusinessController::class, 'bulk_import']);

    //bBQhdsfE_WWe4Q-_f7ieh7Hdhf1E_
    Route::get('/investor-info', [DashboardController::class, 'home']);

    Route::post('create-listing', [BusinessController::class, 'storeListing']);

    //bBQhdsfE_WWe4Q-_f7ieh7Hdhf3E_
    Route::get('listings', [BusinessController::class, 'listings']);
    Route::post('up_listing', [BusinessController::class, 'updateListing']);
    Route::get('delete_listing/{id}', [BusinessController::class, 'deleteListing']);

    //bBQhdsfE_WWe4Q-_f7ieh7Hdhf7E_-{id}
    Route::get('{id}/milestone-info', [DashboardController::class, 'dashboardMilestonesInfo']);


    Route::get('business_bids', [BidsController::class, 'businessBids']);
    Route::get('confirmed_bids', [BidsController::class, 'confirmedBids']);

    Route::get('remove_bids/{id}', [BidsController::class, 'withdrawPendingInvestment']);
    Route::get('remove_active_bids/{id}', [BidsController::class, 'remove_active_bids']);

    Route::get('askInvestorToVerify/{bid_id}', [AssetBidsController::class, 'askInvestorToVerify']);
    Route::get('requestOwnerToVerify/{bid_id}', [AssetBidsController::class, 'requestOwnerToVerify']);
    Route::get('markAsVerified/{bid_id}', [AssetBidsController::class, 'markAsVerified']);
    Route::get('assetEquip/download/{id}/{type}', [DownloadController::class, 'assetDownload'])->name('assetEquip/download');

    //Account Data & Subscription
    Route::get('account', [AccountController::class, 'account_wallet'])->name('account');
    Route::get('getCurrSubscription', [SubscriptionController::class, 'getSubscription']);
    Route::get('cancelSubscription/{id}', [SubscriptionController::class, 'cancelSubscription']);
    Route::get('renewSubscription/{stripe_sub_id}', [SubscriptionController::class, 'renewSubscription']);
    Route::get('fetchUser/{id}', [AuthController::class, 'fetchUser']);
    Route::get('bidInfo/{id}', [BidsController::class, 'bidInfo']);
    Route::get('withdraw_investment/{id}', [BidsController::class, 'withdraw_investment']);
    Route::post('deactivate', [BusinessController::class, 'deactivate']);

    //MILESTONES
    Route::get('add_milestones', [BusinessController::class, 'useMilestoneBusinessInfo']);
    Route::get('activate_milestone/{id}', [BusinessController::class, 'activateListing']);
    Route::post('save_milestone', [MilestoneController::class, 'saveMilestone']);
    Route::get('delete_milestone/{id}', [MilestoneController::class, 'deleteMilestone']);


    Route::get('/notifications', [DashboardController::class, 'notifications']); //->middleware('throttle:120,1');
    Route::get('/notifSetRead', [DashboardController::class, 'notificationSetRead']);
});

// Review & Rating
Route::get('ratingListing/{id}/{rating}/{text}', [BusinessController::class, 'ratingListing'])->name('ratingListing');
Route::get('unlockBySubs/{id}/{sub_id}/{plan}', [BusinessController::class, 'unlockBusiness'])->name('unlockBySubs');

