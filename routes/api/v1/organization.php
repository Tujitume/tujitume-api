<?php

use App\Http\Controllers\Organizations\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::get('organizations/{organization}/reviewers', [OrganizationController::class, 'reviewers']);
