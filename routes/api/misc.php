<?php

/*
|--------------------------------------------------------------------------
| Miscellaneous & Utility Routes
|--------------------------------------------------------------------------
*/

// Party Info
use App\Http\Controllers\Business\AssetBidsController;
use App\Http\Controllers\Business\BidsController;
use App\Http\Controllers\Business\BusinessController;
use App\Http\Controllers\Business\MilestoneController;
use App\Http\Controllers\Business\ResolutionController;
use App\Http\Controllers\Business\SubscriptionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Misc\DownloadController;
use App\Http\Controllers\Misc\KenyaLoansController;
use App\Http\Controllers\Misc\LookupController;
use App\Http\Controllers\Misc\MessageController;
use App\Http\Controllers\Misc\MetadataController;
use App\Http\Controllers\Misc\ScheduleMeetingController;
use App\Http\Controllers\Misc\SearchController;
use App\Http\Controllers\Service\BookingController;
use App\Http\Controllers\Service\ServiceController;
use App\Http\Controllers\Service\ServiceMilestoneController;


// Subscription Routes | Stripe
Route::get(
    '/stripeSubscribe/{amount}/{plan}/{days}/{range}/{inv}', [SubscriptionController::class, 'subscriptionInitiate']
);
Route::get('/stripeSubscribeSuccess', [SubscriptionController::class, 'stripeSuccess']);

// Downloads
Route::get('download_business/{id}', [DownloadController::class, 'download_business'])->name('download_business');
Route::get('download_statement/{id}', [DownloadController::class, 'download_statement'])->name('download_statement');

Route::get('download_milestoneDoc/{id}/{mile_id}', [DownloadController::class, 'download_milestone_doc'])->name('download_milestoneDoc');
Route::get('download_bids_doc/{id}', [DownloadController::class, 'download_bids_doc'])->name('download_bids_doc');

Route::get('download_milestoneDocS/{id}/{mile_id}', [DownloadController::class, 'downloadServiceMilestoneDoc'])->name('download_milestoneDocS');


// Bids & Project Managers
Route::post('bidCommitsEQP', [AssetBidsController::class, 'storeAssetBid'])->name('bidCommitsEQP');

Route::post('bookingAccepted', [BookingController::class, 'accept']);
Route::post('bookingRejected', [BookingController::class, 'reject']);

Route::get('FindProjectManagers/{bid_id}', [BusinessController::class, 'findProjectManagers']);
Route::get('business/{business_id}/FindProjectManagers/', [BusinessController::class, 'findProjectManagersforBusiness']);


Route::get('releaseEquipment/{b_owner_id}/{manager_id}/{bid_id}', [AssetBidsController::class, 'releaseEquipment']);

Route::post('raise-dispute', [ResolutionController::class, 'raiseDispute']);

Route::get('isSubscribed/{id}', [BusinessController::class, 'listingDetailsInfo']);
Route::post('bidsAccepted', [BidsController::class, 'accept']);

Route::get('bidInfo/{id}', [BidsController::class, 'bid_info']);
Route::get('withdraw_investment/{id}', [BidsController::class, 'withdraw_investment']);

// Meetings
Route::get('meetings', [ScheduleMeetingController::class, 'meetings']);
Route::post('create-meeting', [ScheduleMeetingController::class, 'create_meeting']);
Route::post('cancel-meeting', [ScheduleMeetingController::class, 'cancel_meeting']);
Route::get('clients-list', [ScheduleMeetingController::class, 'clients_list']);
Route::get('schedules', [ScheduleMeetingController::class, 'schedules']);
Route::get('client-schedules/{client_id}', [ScheduleMeetingController::class, 'client_schedules']);
Route::post('create-schedule', [ScheduleMeetingController::class, 'create_schedule']);
Route::post('delete-schedule/{id}', [ScheduleMeetingController::class, 'delete_schedule']);

// Service Booking
Route::post('serviceBook', [BookingController::class, 'serviceBook'])->name('serviceBook');
Route::get('rebook_service/{id}', [BookingController::class, 'rebookService'])->name('rebook_service');
Route::post('serviceMsg', [MessageController::class, 'serviceMsg'])->name('serviceMsg');
Route::post('serviceReply', [MessageController::class, 'serviceReply'])->name('serviceReply');

// Notifications
Route::get('/notifications', [DashboardController::class, 'notifications']);
Route::get('/notifSetRead', [DashboardController::class, 'notificationSetRead']);

// Disputes
Route::get('checkDispute/{id}/{type}', [ResolutionController::class ,'checkDispute']);

// Duplicate Routes with Auth
Route::get('listing_auth/{id}', [BusinessController::class,'listing'])->name('listing_auth');
Route::get('searchResultsAuth/{ids}', [BusinessController::class,'listingResultsByIds']);
Route::get('serviceResultsAuth/{ids}', [ServiceController::class, 'serviceResultsByIds']);

Route::get('getMilestonesS_Auth/{id}', [ServiceMilestoneController::class, 'getMilestones'])->name('getMilestonesS');
Route::get('getMilestonesAuth/{id}', [MilestoneController::class ,'getMilestones'])->name('getMilestones');

Route::get('latBusinessAuth', [BusinessController::class,'featuredListings']);
Route::get('latServicesAuth', [ServiceController::class, 'featuredServices']);
Route::get('categoryResultsAuth/{catName}', [LookupController::class, 'listingsByCategory']);

// Loans
Route::prefix('/loans')->group(function(){
    Route::get('kenya-business-loans', [KenyaLoansController::class, 'pageBanks']);
    Route::get('kenya-business-loans/saccos', [KenyaLoansController::class, 'pageSaccosCurated']);
    Route::get('kenya-business-loans/live', [KenyaLoansController::class, 'pageSaccosLive']);
});

// metadata for frontend
Route::get('metadata/{type}', [MetadataController::class, 'show']);

