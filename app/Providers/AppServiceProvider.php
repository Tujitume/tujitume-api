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
        $this->app->singleton(StripeClient::class, function () {
            $secret = config('services.stripe.secret_key');

            if (is_string($secret) && trim($secret) !== '') {
                return new StripeClient($secret);
            }

            if ($this->app->runningUnitTests()) {
                return new StripeClient('sk_test_placeholder');
            }

            throw new \RuntimeException('Stripe secret key is not configured.');
        });

        Validator::extend('recaptcha', 'App\\Validators\\ReCaptcha@validate');
    }
}
