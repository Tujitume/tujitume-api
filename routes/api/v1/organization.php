<?php

use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Controllers\Organizations\ExternalReviewerController;

use Illuminate\Support\Facades\Route;



Route::group(['prefix' => 'organizations'], function () {
    Route::get('{organization}/reviewers', [OrganizationController::class, 'reviewers']);
    Route::post('/', [OrganizationController::class, 'createOrganization']);
    Route::patch('{organization}', [OrganizationController::class, 'updateOrganization']);
    Route::get('external-reviewers', [ExternalReviewerController::class, 'index']);

    // Tujitume service providers the organization has added as third-party reviewers/auditors
    Route::get('providers', [\App\Http\Controllers\Organizations\OrganizationProviderController::class, 'index']);
    Route::post('providers', [\App\Http\Controllers\Organizations\OrganizationProviderController::class, 'store']);
    Route::delete('providers/{provider}', [\App\Http\Controllers\Organizations\OrganizationProviderController::class, 'destroy']);
});
