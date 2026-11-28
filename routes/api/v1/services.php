<?php
//Dashboard -- Service ROUTES

use App\Http\Controllers\Business\BusinessController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Misc\MessageController;
use App\Http\Controllers\Service\BookingController;
use App\Http\Controllers\Service\ServiceBookingFlowController;
use App\Http\Controllers\Service\ServiceController;
use App\Http\Controllers\Service\ServiceMilestoneController;
use App\Http\Controllers\Service\ServiceOfferController;

Route::prefix('/services')->middleware('services')->group(function() {

    Route::post('create-service', [ServiceController::class, 'storeService']);
// --- MILESTONE
    Route::get('add_s_milestones', [ServiceMilestoneController::class, 'getMilestoneBusinessInfo']);
    Route::get('bBQhdsfE_WWe4Q-_f7ieh7Hdhf4F_-{id}', [ServiceMilestoneController::class, 'milestones']);
    Route::get('findMilestones/{s_id}/{booker_id}', [ServiceMilestoneController::class, 'findMilestones']);
    // The provider's milestones running now (in progress or waiting for approval), across every customer
    Route::get('milestones/running', [ServiceMilestoneController::class, 'running']);

    Route::post('save_s_milestone', [ServiceMilestoneController::class, 'saveMilestone'])->name('save_s_milestone');
    Route::get('delete_s_milestone/{id}', [ServiceMilestoneController::class, 'deleteMilestone'])->name('delete_s_milestone');
    Route::post('mile_s_status', [ServiceMilestoneController::class, 'setMilestoneStatus'])->name('mile_s_status');
// --- MILESTONE
    Route::get('service_booking', [BookingController::class, 'serviceBooking'])->name('service_booking');
    Route::get('my_booking', [BookingController::class, 'myBooking'])->name('my_booking');
    Route::get('getBookers/{s_id}', [BookingController::class, 'getBookers'])->name('getBookers');
    // A booking's milestones and payments: the customer approves each milestone (releasing its payment) or asks for changes
    // How long the person looked at a service (Explore Services recommendations)
    Route::post('{service}/viewed', [ServiceController::class, 'recordView'])->whereNumber('service');
    Route::get('bookings/{booking}/flow', [ServiceBookingFlowController::class, 'show'])->whereNumber('booking');
    Route::post('bookings/{booking}/milestones/{milestone}/approve', [ServiceBookingFlowController::class, 'approve'])->whereNumber(['booking', 'milestone']);
    Route::post('bookings/{booking}/cancel', [ServiceBookingFlowController::class, 'cancel'])->whereNumber('booking');
    Route::post('bookings/{booking}/milestones/{milestone}/request-changes', [ServiceBookingFlowController::class, 'requestChanges'])->whereNumber(['booking', 'milestone']);
    // DECISION-9: an optional paid project manager audit of a Done milestone, before the customer approves it
    Route::post('bookings/{booking}/milestones/{milestone}/audit', [ServiceBookingFlowController::class, 'requestAudit'])->whereNumber(['booking', 'milestone']);
    Route::post('bookings/{booking}/milestones/{milestone}/audit/pay', [ServiceBookingFlowController::class, 'payAudit'])->whereNumber(['booking', 'milestone']);
    Route::post('bookings/{booking}/milestones/{milestone}/audit/cancel', [ServiceBookingFlowController::class, 'cancelAudit'])->whereNumber(['booking', 'milestone']);
    Route::get('service_messages/{from}', [MessageController::class, 'serviceMessages'])->name('service-messages');
    Route::get('service_messages_count/{from}', [MessageController::class, 'serviceMessagesCount']);


    Route::get('/dashhome/{query}', [DashboardController::class, 'home']);
    Route::post('up_service', [ServiceController::class, 'updateService'])->name('up_service');
    Route::get('delete_service/{id}', [ServiceController::class, 'deleteService'])->name('delete_service');

});

Route::get('ratingService/{id}/{rating}/{text}', [ServiceController::class, 'serviceRating'])->name('ratingService');

Route::prefix('/services')->group(function(){

    Route::post('deactivate', [ServiceController::class, 'deactivate']);
    Route::post('activate', [ServiceController::class, 'activate']);

    // Offers
    // Service requests: a provider shares one of their services with a business, a program or a capital offer
    Route::post('proposals', [\App\Http\Controllers\Service\ServiceProposalController::class, 'store']);
    Route::get('proposals/received', [\App\Http\Controllers\Service\ServiceProposalController::class, 'received']);
    Route::get('proposals/sent', [\App\Http\Controllers\Service\ServiceProposalController::class, 'sent']);
    Route::get('proposals/{proposal}', [\App\Http\Controllers\Service\ServiceProposalController::class, 'show'])->whereNumber('proposal');
    Route::post('proposals/{proposal}/decide', [\App\Http\Controllers\Service\ServiceProposalController::class, 'decide'])->whereNumber('proposal');
    Route::post('proposals/{proposal}/withdraw', [\App\Http\Controllers\Service\ServiceProposalController::class, 'withdraw'])->whereNumber('proposal');

    Route::post('{service}/offer',                [ServiceOfferController::class, 'store']);
    Route::post('offers/{offer}/accept',          [ServiceOfferController::class, 'accept']);
    Route::post('offers/{offer}/reject',          [ServiceOfferController::class, 'reject']);
    //Route::post('offers/{offer}/counter',       [ServiceOfferController::class, 'counter']); Route::post('offers/{offer}/accept-counter',  [ServiceOfferController::class, 'acceptCounter']);
    Route::get('{service}/offers',                [ServiceOfferController::class, 'index']);
    Route::get('my-offers',                       [ServiceOfferController::class, 'myOffers']);

    // Delivery
    Route::post('bookings/{booking}/deliver',         [ServiceOfferController::class, 'deliver']);
    Route::post('bookings/{booking}/accept-delivery', [ServiceOfferController::class, 'acceptDelivery']);
    Route::post('bookings/{booking}/reject-delivery', [ServiceOfferController::class, 'rejectDelivery']);
});
