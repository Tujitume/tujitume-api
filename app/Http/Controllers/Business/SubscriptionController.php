<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Business\BusinessSubscriptions;
use App\Models\Business\Conversation;
use App\Models\Business\Listing;
use App\Models\Business\Review;
use App\Service\Misc\ErrorLogService;
use App\Service\Subscription\SubscriptionHolder;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Session;
use Stripe\StripeClient;

class SubscriptionController extends Controller
{
    public $notification;
    public function __construct(StripeClient $client)
    {
        parent::__construct();
        $this->Client = $client;
    }

    // S T R I P E   S U B S C R I P T I O N
    public function subscriptionInitiate($amount, $plan, $days, $range, $inv_id)
    {
        $days       = base64_decode($days);
        $range      = base64_decode($range);
        $plan       = base64_decode($plan);
        $base_price = (float) base64_decode($amount);

        // Trial plan redirect
        $trialPlans = [
            'silver-trial'   => 'https://buy.stripe.com/test_aEU4jm4kNg0PgF2000',
            'gold-trial'     => 'https://buy.stripe.com/test_4gw3fi18B6qffAY3cd',
            'platinum-trial' => 'https://buy.stripe.com/test_00g9DGeZr15V88w5km',
        ];

        if (isset($trialPlans[$plan])) {
            return redirect($trialPlans[$plan]);
        }

        // Stripe price ID map
        $priceMap = [
            'silver_30'    => 'price_1O6uaiJkjwNxIm6zzQ5b2t46',
            'gold_30'      => 'price_1M7aX9JkjwNxIm6z5ut8ixWC',
            'platinum_30'  => 'price_1O7bheJkjwNxIm6zutl9T3HR',
            'silver_365'   => 'price_1O7bXyJkjwNxIm6zpTcQdjYg',
            'gold_365'     => 'price_1O7bdzJkjwNxIm6zwGCyyLpg',
            'platinum_365' => 'price_1O7bhfJkjwNxIm6zMLsZZTGP',
        ];

        $priceKey = "{$plan}_{$days}";

        if (!isset($priceMap[$priceKey])) {
            return response()->json(['error' => 'Invalid plan or billing cycle.'], 422);
        }

        try {
            $session = $this->Client->checkout->sessions->create([
                'success_url'          => \App\Service\Notification\RecipientUrl::url('stripeSubscribeSuccess?session_id={CHECKOUT_SESSION_ID}', (int) $inv_id ?: null),
                'cancel_url'           => \App\Service\Notification\RecipientUrl::url('canceled', (int) $inv_id ?: null),
                'mode'                 => 'subscription',
                'line_items'           => [[
                    'price'    => $priceMap[$priceKey],
                    'quantity' => 1,
                ]],
                'client_reference_id'  => "{$plan}_{$range}_{$inv_id}",
            ]);

            return redirect($session->url);

        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'plan'   => $plan, 'days'   => $days, 'inv_id' => $inv_id,
            ]);
            return response()->json(['message' => 'Failed to initiate subscription. Please try again later.'], 500);
        }
    }


    public function stripeSuccess(Request $request)
    {
        try {
            $checkout = $this->Client->checkout->sessions->retrieve(
                $request->query('session_id'), []
            );

            $stripeSubId = $checkout->subscription;
            $userEmail   = $checkout->customer_details->email;

            // Trial flow — amount_total is 0
            if ($checkout->amount_total == 0) {
                $investor = User::where('email', $userEmail)->firstOrFail();
                $investorId = $investor->id;

                $sub = $this->Client->subscriptions->retrieve($stripeSubId, []);
                $originalAmount = $sub->items->data[0]->plan->amount / 100;

                $plan = match(true) {
                    $originalAmount == 69.99 => 'platinum-trial',
                    $originalAmount == 29.99 => 'gold-trial',
                    $originalAmount == 9.99  => 'silver-trial',
                    default => throw new Exception('Unknown trial amount: ' . $originalAmount),
                };

                $transferAmount = 0;
                $range          = null;
            }

            // Paid flow
            else {
                $originalAmount = $checkout->amount_total / 100;
                $transferAmount = $originalAmount;
                [$plan, $range, $investorId] = explode('_', $checkout->client_reference_id);
            }

            $investorId = $investorId ?: Auth::id();

            $isTrial         = str_contains($plan, '-trial');
            $trial           = $isTrial ? 1 : 0;
            $expireDate      = now()->addDays($isTrial ? 7 : 30)->format('Y-m-d');
            $tokenRemaining  = match(true) {
                in_array($plan, ['silver', 'silver-trial', 'gold-trial']) => 10,
                $plan === 'gold' => 30,
                default          => null,
            };

            BusinessSubscriptions::create([
                'plan'            => $plan,
                'investor_id'     => $investorId,
                'amount'          => $transferAmount,
                'start_date'      => now()->format('Y-m-d'),
                'expire_date'     => $expireDate,
                'token_remaining' => $tokenRemaining,
                'chosen_range'    => $range,
                'trial'           => $trial,
                'stripe_sub_id'   => $stripeSubId,
            ]);

            $yearlyAmounts = [95.99, 287.99, 671.99];
            $message = match(true) {
                $isTrial                              => 'Your trial expires in 7 days.',
                in_array($originalAmount, $yearlyAmounts) => 'Your ' . ucwords($plan) . ' plan expires in 365 days.',
                default                               => 'Your ' . ucwords($plan) . ' plan expires in 30 days.',
            };

            return redirect(\App\Service\Notification\RecipientUrl::url('', $investorId));

        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }



    public function getSubscription()
    {
        // People inside an organisation share its subscription (kept on the owner); only admins manage it
        $user = Auth::user();
        $holderId = SubscriptionHolder::idFor($user);
        $shared = [
            'organisation' => SubscriptionHolder::isOrganisation($user),
            'can_manage'   => SubscriptionHolder::canManage($user),
        ];

        $subscribed = BusinessSubscriptions::where('investor_id', $holderId)
            ->where('active', 1)->latest()->first();

        if (!$subscribed) {
            return response()->json(['subscription' => false, 'message' => 'Subscription not found.'] + $shared, 404);
        }

        // Paid by M-Pesa (no Stripe subscription): its dates are ours. It never renews by itself
        if (!$subscribed->stripe_sub_id) {
            return response()->json(['subscription' => true, 'data' => $this->liprSubscriptionView($subscribed) + $shared], 200);
        }
        try {
            $stripeSub = $this->Client->subscriptions->retrieve($subscribed->stripe_sub_id, []);

            $now              = Carbon::now();
            $periodEnd        = Carbon::createFromTimestamp($stripeSub->current_period_end);
            $trialStart       = $stripeSub->trial_start ? Carbon::createFromTimestamp($stripeSub->trial_start) : null;
            $trialEnd         = $stripeSub->trial_end   ? Carbon::createFromTimestamp($stripeSub->trial_end)   : null;
            $canceledAt       = $stripeSub->canceled_at ? Carbon::createFromTimestamp($stripeSub->canceled_at) : null;

            $isTrial    = $trialStart && $trialEnd && $now->between($trialStart, $trialEnd);
            $isActive   = $stripeSub->status === 'active';
            $isExpired  = $now->greaterThan($periodEnd);
            $autoRenew  = $isActive && !$stripeSub->cancel_at_period_end;
            $fullyExpired = !$isActive && $isExpired;

            // Sync DB state
            if ($fullyExpired) {
                $subscribed->update(['active' => 0]);
            }

            if (!$isTrial && $subscribed->trial) {
                $subscribed->update([
                    'trial' => 0,
                    'plan'  => explode('-', $subscribed->plan)[0],
                ]);
            }

            $subscribed->refresh();

            $daysLeft   = $now->diffInDays($periodEnd, false);
            $monthsLeft = $now->diffInMonths($periodEnd);
            if ($daysLeft <= 0 && $monthsLeft == 1) $daysLeft = 30;

            $priceAmount = $stripeSub->items->data[0]?->price->unit_amount / 100;
            $priceAmount = $isTrial ? 0 : $priceAmount;

            return response()->json([
                'subscription' => true,
                'data' => [
                    'subscribed'       => 1,
                    'sub_id'           => $subscribed->id,
                    'stripe_sub_id'    => $subscribed->stripe_sub_id,
                    'plan'             => $subscribed->plan,
                    'amount'           => $priceAmount,
                    'token_left'       => $subscribed->token_remaining,
                    'status'           => $stripeSub->status,
                    'is_active'        => $isActive,
                    'is_expired'       => $isExpired,
                    'fully_expired'    => $fullyExpired,
                    'auto_renew'       => $autoRenew,
                    'trial'            => $isTrial,
                    'trial_start'      => $trialStart?->toDateTimeString(),
                    'trial_end'        => $trialEnd?->toDateTimeString(),
                    'cancel_scheduled' => $stripeSub->cancel_at_period_end,
                    'canceled_at'      => $canceledAt?->toDateTimeString(),
                    'expire'           => $daysLeft,
                    'end_date'         => $periodEnd->toDateString(),
                    'expire_at'        => $periodEnd->toDateTimeString(),
                ] + $shared,
            ], 200);

        } catch (Exception $e) {
            // Stripe could not be reached or does not know it: say so, but never delete the person's subscription
            ErrorLogService::report($e, ['stripe_sub_id' => $subscribed->stripe_sub_id]);
            return response()->json(['subscription' => false, 'message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function renewSubscription($stripe_sub_id)
    {
        // ISSUE-51: only the subscription the person manages, as for cancel (it used to renew any Stripe id it was given)
        $subs = $this->managedStripeSubscription($stripe_sub_id);
        if (! $subs) {
            return response()->json(['message' => 'Subscription not found.'], 404);
        }
        try {
            $this->Client->subscriptions->update(
                $subs->stripe_sub_id,
                ['cancel_at_period_end' => false]
            );
            return response()->json(['subscription' => true, 'message' => 'Subscription Renewed.'], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /** The subscription as the page reads it, for one paid by M-Pesa (fixed dates, no auto renewal). */
    private function liprSubscriptionView(BusinessSubscriptions $subscribed): array
    {
        $now = Carbon::now();
        $end = $subscribed->expire_date ? Carbon::parse($subscribed->expire_date) : $now;
        $start = $subscribed->start_date ? Carbon::parse($subscribed->start_date) : null;
        $expired = $now->greaterThan($end);
        if ($expired) $subscribed->update(['active' => 0]);

        return [
            'subscribed'       => 1,
            'sub_id'           => $subscribed->id,
            'stripe_sub_id'    => null,
            'plan'             => $subscribed->plan,
            'amount'           => $subscribed->trial ? 0 : (float) $subscribed->amount,
            // Plans are priced in US dollars (config/subscriptions.php); M-Pesa charged the shilling equivalent
            'currency'         => 'USD',
            'token_left'       => $subscribed->token_remaining,
            'status'           => $expired ? 'expired' : ($subscribed->trial ? 'trialing' : 'active'),
            'is_active'        => ! $expired,
            'is_expired'       => $expired,
            'fully_expired'    => $expired,
            'auto_renew'       => false,
            'trial'            => (bool) $subscribed->trial && ! $expired,
            'trial_start'      => $subscribed->trial ? $start?->toDateTimeString() : null,
            'trial_end'        => $subscribed->trial ? $end->toDateTimeString() : null,
            'cancel_scheduled' => false,
            'canceled_at'      => null,
            'expire'           => max(0, (int) $now->diffInDays($end, false)),
            'end_date'         => $end->toDateString(),
            'expire_at'        => $end->toDateTimeString(),
            'method'           => 'lipr',
        ];
    }

    /** The Stripe subscription the signed-in person manages (their own, or their organisation's as an admin), or null. */
    private function managedStripeSubscription($stripeSubId): ?BusinessSubscriptions
    {
        $user = Auth::user();
        if (! $stripeSubId || ! SubscriptionHolder::canManage($user)) {
            return null;
        }

        return BusinessSubscriptions::where('stripe_sub_id', $stripeSubId)->where('investor_id', SubscriptionHolder::idFor($user))->first();
    }

    public function cancelSubscription($id)
    {
        $subs = $this->managedStripeSubscription($id);
        if (! $subs) {
            return response()->json(['message' => 'Subscription not found.'], 404);
        }
        try{
            //$cancel = $this->Client->subscriptions->cancel($subs->stripe_sub_id,[]); //Fully Cancel
            $cancel = $this->Client->subscriptions->update(
                $subs->stripe_sub_id,
                ['cancel_at_period_end' => true]
            );
            return response()->json(['message'=>'Subscription canceled!'], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }

    }
    //SUBSCRIBE  E N D S

}
