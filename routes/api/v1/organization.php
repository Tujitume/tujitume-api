<?php

use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Controllers\Organizations\ExternalReviewerController;

use Illuminate\Support\Facades\Route;



Route::group(['prefix' => 'organizations'], function () {
    Route::get('{organization}/reviewers', [OrganizationController::class, 'reviewers']);
    Route::post('/', [OrganizationController::class, 'createOrganization']);
    Route::patch('{organization}', [OrganizationController::class, 'createOrganization']);
    Route::get('external-reviewers', [ExternalReviewerController::class, 'index']);
});