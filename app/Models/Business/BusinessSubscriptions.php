<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BusinessSubscriptions extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected static function booted(): void
    {
        // Every payment path (wallet, M-Pesa, Stripe) creates a row here. The first paid one of an account that
        // signed up through a one-time invite opens its workspace: trial deleted, "workspace ready" email.
        // After commit, and never able to fail the payment itself.
        static::created(function (BusinessSubscriptions $subscription): void {
            DB::afterCommit(function () use ($subscription): void {
                try {
                    app(\App\Service\DemoRequests\DemoRequestService::class)->onSubscriptionCreated($subscription);
                } catch (\Throwable $e) {
                    \App\Service\Misc\ErrorLogService::report($e, ['business_subscription_id' => $subscription->id]);
                }
            });
        });
    }
}
