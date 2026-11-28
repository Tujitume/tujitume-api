<?php

namespace App\Providers;

use Illuminate\Mail\Events\MessageSending;
use App\Service\Notification\RecipientUrl;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
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
        // One extractor per process: it collects the notes (truncation, missing OCR) for the review being built
        $this->app->singleton(\App\Service\AiReview\DocumentExtractor::class);

        //
    }

    /**
     * Bootstrap any application services.
     */
    /** On a local "s3" disk, temporaryUrl() returns the plain public link (the local driver cannot sign links). */
    public static function localTemporaryUrls(): void
    {
        if (config('filesystems.disks.s3.driver') !== 'local') {
            return;
        }
        \Illuminate\Support\Facades\Storage::disk('s3')->buildTemporaryUrlsUsing(
            fn (string $path) => \Illuminate\Support\Facades\Storage::disk('s3')->url($path)
        );
    }

    public function boot(): void
    {
        // A machine without an AWS bucket keeps "s3" files on the local disk (config/filesystems.php). The local driver
        // cannot sign links, so its "temporary" links are the plain public link: answers with files no longer 500.
        self::localTemporaryUrls();

        // Organization audit log: who created, changed or deleted what (old and new values). Only organization
        // members' changes to organization records are kept (App\Service\Organization\OrganizationAudit).
        foreach (['created', 'updated', 'deleted'] as $auditAction) {
            \Illuminate\Support\Facades\Event::listen("eloquent.{$auditAction}: *", function (string $event, array $payload) use ($auditAction) {
                if (($payload[0] ?? null) instanceof \Illuminate\Database\Eloquent\Model) {
                    \App\Service\Organization\OrganizationAudit::record($payload[0], $auditAction);
                }
            });
        }

        // Sessions end after config('sanctum.idle_timeout') minutes without a request, not a fixed time after sign-in.
        // This applies to every token, including any older token created with a long expires_at.
        \Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(function ($token, bool $isValid) {
            if (! $isValid) {
                return false;
            }
            $idle = (int) config('sanctum.idle_timeout', 60);
            $lastSeen = $token->last_used_at ?? $token->created_at;

            return $idle <= 0 || ($lastSeen && $lastSeen->gt(now()->subMinutes($idle)));
        });

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

        // Nothing is sent to the test-data generator's accounts: no real person, and the addresses bounce
        Event::listen(MessageSending::class, function (MessageSending $event) {
            $to = $event->message->getTo();
            if ($to && collect($to)->every(fn ($address) => \App\Service\Misc\AutomatedAccounts::isEmail($address->getAddress()))) {
                return false;
            }
        });

        // Email templates write their links as {{ $app_url }}dashboard/...: the address of whoever the email is
        // going to (their organisation's workspace when they work inside one, else the main site, with a
        // trailing slash). The recipients are already on the message when the template renders, in a web
        // request, a queued job or a scheduled command alike. Partials inherit the value from their parent.
        View::composer('*', function ($view) {
            $data = $view->getData();
            if (array_key_exists('app_url', $data) || ! (($data['message'] ?? null) instanceof \Illuminate\Mail\Message)) {
                return;
            }

            $to = array_map(fn ($address) => $address->getAddress(), $data['message']->getSymfonyMessage()->getTo());
            $view->with('app_url', ($to ? RecipientUrl::baseForAll($to) : RecipientUrl::main()) . '/');
        });

        Validator::extend('recaptcha', 'App\\Validators\\ReCaptcha@validate');
    }
}
