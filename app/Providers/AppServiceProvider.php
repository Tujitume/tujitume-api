<?php

namespace App\Providers;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mime\Address;
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

        // An email sent on behalf of an organisation shows that organisation as the sender
        // name; the address stays the shared noreply one. Applies to every Mail::send that
        // passes a custom $brand, whichever code path sent it.
        Event::listen(MessageSending::class, function (MessageSending $event) {
            $brand = $event->data['brand'] ?? null;
            if (! is_array($brand) || empty($brand['custom']) || empty($brand['name'])) {
                return;
            }

            $event->message->from(new Address((string) config('mail.from.address'), (string) $brand['name']));
        });

        Validator::extend('recaptcha', 'App\\Validators\\ReCaptcha@validate');
    }
}
