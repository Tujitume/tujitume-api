<?php

namespace App\Http\Controllers\Grant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponseResource;
use App\Models\Grants\GrantApplication;
use App\Service\Grant\GrantMonitoringAnalyticsService;

class MEAnalyticsController extends Controller
{
    public function __construct(private GrantMonitoringAnalyticsService $analyticsService)
    {
    }

    // GET /applications/{app}/analytics/me-overview
    public function meOverview(GrantApplication $app)
    {
        $userId = auth()->id();

        $isGrantOwner = $app->grant->user_id === $userId;
        $isApplicant = $app->user_id === $userId;

        if (!$isGrantOwner && !$isApplicant) {
            return new ApiResponseResource('Unauthorized', null, 403);
        }

        $payload = $this->analyticsService->getMeOverview($app);

        return new ApiResponseResource('Monitoring analytics overview fetched successfully', $payload, 200);
    }

    // GET /applications/{app}/analytics/impact
    public function applicationImpact(GrantApplication $app)
    {
        $userId = auth()->id();

        $isGrantOwner = $app->grant->user_id === $userId;
        $isApplicant = $app->user_id === $userId;

        if (!$isGrantOwner && !$isApplicant) {
            return new ApiResponseResource('Unauthorized', null, 403);
        }

        $payload = $this->analyticsService->getApplicationImpact($app);

        return new ApiResponseResource('Application impact analytics fetched successfully', $payload, 200);
    }
}
