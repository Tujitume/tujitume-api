<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponseResource;
use App\Models\Programs\ProgramApplication;
use App\Service\Program\ProgramMonitoringAnalyticsService;

class MEAnalyticsController extends Controller
{
    public function __construct(private ProgramMonitoringAnalyticsService $analyticsService)
    {
    }

    // GET /applications/{app}/analytics/me-overview
    public function meOverview(ProgramApplication $app)
    {
        $userId = auth()->id();

        $isProgramOwner = $app->program->user_id === $userId;
        $isApplicant = $app->user_id === $userId;

        if (!$isProgramOwner && !$isApplicant) {
            return new ApiResponseResource('Unauthorized', null, 403);
        }

        $payload = $this->analyticsService->getMeOverview($app);

        return new ApiResponseResource('Monitoring analytics overview fetched successfully', $payload, 200);
    }

    // GET /applications/{app}/analytics/impact
    public function applicationImpact(ProgramApplication $app)
    {
        $userId = auth()->id();

        $isProgramOwner = $app->program->user_id === $userId;
        $isApplicant = $app->user_id === $userId;

        if (!$isProgramOwner && !$isApplicant) {
            return new ApiResponseResource('Unauthorized', null, 403);
        }

        $payload = $this->analyticsService->getApplicationImpact($app);

        return new ApiResponseResource('Application impact analytics fetched successfully', $payload, 200);
    }
}
