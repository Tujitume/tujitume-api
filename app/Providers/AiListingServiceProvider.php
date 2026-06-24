<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AiListingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind('App\Service\AiScore\ListingScore');
        $this->app->bind('App\Service\AiScore\ServiceScore');
        $this->app->bind('App\Service\AiScore\ListingToServiceScore');
        $this->app->bind('App\Service\AI\CapitalMarketAnalysis');
        $this->app->bind('App\Service\AI\InvestorPersonalizedListing');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
