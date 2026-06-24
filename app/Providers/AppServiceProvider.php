<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Validator;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->singleton(StripeClient::class, function(){
        return new StripeClient(config('services.stripe.secret_key'));
        });

        Validator::extend('recaptcha', 'App\\Validators\\ReCaptcha@validate');
    }
}
