<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Models\Misc\SavedPaymentMethod;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Stripe\StripeClient;

class PaymentMethodController extends Controller
{
    protected StripeClient $stripe;

    public function __construct(StripeClient $stripe)
    {
        $this->stripe = $stripe;
        parent::__construct();
    }

    /**
     * List all saved cards for the authenticated user.
     */
    public function index(): JsonResponse
    {
        try {
            $cards = SavedPaymentMethod::where('user_id', Auth::id())
                ->latest()
                ->get()
                ->map(fn($card) => [
                    'id'                       => $card->id,
                    'stripe_payment_method_id' => $card->stripe_payment_method_id,
                    'brand'                    => $card->brand,
                    'last_four'                => $card->last_four,
                    'exp_month'                => $card->exp_month,
                    'exp_year'                 => $card->exp_year,
                    'label'                    => $card->label,
                ]);

            return response()->json(['cards' => $cards], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['user_id' => Auth::id()]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /**
     * Save a new card.
     *
     * The frontend collects card details via Stripe.js and sends back
     * a payment_method_id (pm_xxxx). We attach it to the Stripe Customer
     * and store the token + display data in our DB.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'payment_method_id' => 'required|string|starts_with:pm_',
            ]);

            $user = Auth::user();

            // Enforce per-user card limit
            $cardCount = SavedPaymentMethod::where('user_id', $user->id)->count();
            if ($cardCount >= 5) {
                return response()->json([
                    'message' => 'You can save up to 5 cards. Please remove one before adding a new one.'
                ], 422);
            }

            // Prevent duplicate saves
            $alreadySaved = SavedPaymentMethod::where('user_id', $user->id)
                ->where('stripe_payment_method_id', $validated['payment_method_id'])
                ->exists();

            if ($alreadySaved) {
                return response()->json(['message' => 'This card is already saved.'], 422);
            }

            // Ensure user has a Stripe Customer — create one if not
            if (!$user->stripe_customer_id) {
                $customer = $this->stripe->customers->create([
                    'email'    => $user->email,
                    'name'     => trim($user->fname . ' ' . $user->lname),
                    'metadata' => ['user_id' => $user->id],
                ]);
                $user->update(['stripe_customer_id' => $customer->id]);
            }

            // Attach the payment method to the Stripe Customer
            $this->stripe->paymentMethods->attach($validated['payment_method_id'], [
                'customer' => $user->stripe_customer_id,
            ]);

            // Retrieve card details from Stripe
            $pm   = $this->stripe->paymentMethods->retrieve($validated['payment_method_id']);
            $card = $pm->card;

            $saved = SavedPaymentMethod::create([
                'user_id'                  => $user->id,
                'stripe_payment_method_id' => $pm->id,
                'brand'                    => $card->brand,
                'last_four'                => $card->last4,
                'exp_month'                => $card->exp_month,
                'exp_year'                 => $card->exp_year,
            ]);

            return response()->json([
                'message' => 'Card saved successfully.',
                'card'    => [
                    'id'                       => $saved->id,
                    'stripe_payment_method_id' => $saved->stripe_payment_method_id,
                    'brand'                    => $saved->brand,
                    'last_four'                => $saved->last_four,
                    'exp_month'                => $saved->exp_month,
                    'exp_year'                 => $saved->exp_year,
                    'label'                    => $saved->label,
                ],
            ], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /**
     * Remove a saved card.
     * Detaches from Stripe Customer and deletes from DB.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $card = SavedPaymentMethod::where('id', $id)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            // Detach from Stripe so it can't be charged again
            $this->stripe->paymentMethods->detach($card->stripe_payment_method_id);

            $card->delete();

            return response()->json(['message' => 'Card removed successfully.'], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['id' => $id]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }
}
