<?php

namespace App\Http\Controllers;

use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Conversation;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalMilestone;
use App\Models\Capital\StartupPitches;
use App\Models\Finance\LiprPayment;
use App\Models\Programs\Disbursement;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Models\Milestones\Milestones;
use App\Models\Misc\Setting;
use App\Models\ReviewerOrder;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Service\Services\ServiceBookingFlow;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\LiprMpesa\ProgramDisbursementService;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Session;

class CheckoutMpesaController extends Controller
{
    protected $public;
    protected $secret;
    protected $balance;
    protected $convert;
    protected $tujitume_lipr;
    protected LiprW2W $liprW2W;
    protected $disbursementService;

    public function __construct(ProgramDisbursementService $disbursementService)
    {

        parent::__construct();

        $this->balance = new BalanceService();
        $this->liprW2W = new LiprW2W();
        $this->disbursementService = $disbursementService;
        $this->tujitume_lipr = Setting::where('key', 'platform_lipr_wallet')->first()?->value ?? null;
    }

    public function auth()
    {
        try {
            $liprAuth = new LiprAuthService();
            $token = $liprAuth->authorize(); return $token;
        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);
            if (in_array($e->getCode(), [404, 422])) {
                return response()->json(['message' => $e->getMessage()], $e->getCode());
            }

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function initiate_payment(Request $request, CurrencyConverter $converter)
    {
        try {
            $this->validateInitiatePaymentRequest($request);

            if (!Auth::check()) {
                return response()->json(['message' => 'Unauthorized!'], 401);
            }

            $token = $this->auth();
            $platform_wallet = Setting::where('key', 'platform_lipr_wallet')->value('value');
            $callbackUrl = \App\Service\LiprMpesa\LiprCallback::url('/lipr-callback');

            if (!$platform_wallet) {
                return response()->json(['message' => 'Tujitume platform lipr account not found.'], 404);
            }

            $payment = $this->resolveInitiatePaymentDetails($request, $callbackUrl);
            $amountKes = $this->calculateInitiatePaymentAmount(
                $request->purpose,
                $payment['amount'],
                $payment['amountKes']
            );
            if (!$amountKes || $amountKes <= 0) {
                return response()->json(['message' => 'The amount to pay could not be worked out.'], 422);
            }

            if ($request->purpose === 'reviewer_payment') {
                $payment['order']->update(['fee_kes' => $amountKes]);
            }

            //$amountKes = 100;

            $fields = $this->buildInitiatePaymentPayload($request, $payment['callbackUrl'], $amountKes, $platform_wallet);
            $result = $this->sendInitiatePaymentRequest($fields, $token);

            // ISSUE-45: LIPR refused (or could not be reached) → a 4xx with its reason, so the checkout never waits for a
            // payment that never started
            if ($refusal = self::liprRefusal($result)) {
                return response()->json(['message' => 'The M-Pesa payment could not start: '.$refusal.'.', 'liprResponse' => $result], 422);
            }

            $this->rememberPaymentIntent($result, $request->purpose, $amountKes, $converter);

            return response()->json([
                'liprResponse' => $result,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        }
        catch (\Exception $e) {
            // A refusal the person can read (not theirs, not found, an audit is open...) is not an error to log
            if (in_array($e->getCode(), [403, 404, 422])) {
                return response()->json(['message' => $e->getMessage()], $e->getCode());
            }

            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }

    }

    /**
     * The payment row is written by LIPR's callback, which only knows the reference: record here who started it, for
     * what and how much, so the status polls can check the payment is the caller's own (the callback keeps these).
     */
    private function rememberPaymentIntent($result, string $purpose, $amountKes, CurrencyConverter $converter): void
    {
        $reference = null;
        $stack = [is_array($result) ? $result : (array) $result];
        while ($stack && !$reference) {
            $node = array_shift($stack);
            foreach (['reference_id', 'referenceId', 'reference', 'transaction_id', 'transactionId'] as $key) {
                if (!empty($node[$key]) && is_scalar($node[$key])) { $reference = (string) $node[$key]; break; }
            }
            foreach ($node as $value) if (is_array($value)) $stack[] = $value;
        }
        if (!$reference) return;

        try {
            $kesToUsd = $converter->KesToUsd();
            $payment = LiprPayment::firstOrNew(['reference_id' => $reference]);
            $payment->user_id = $payment->user_id ?: Auth::id();
            $payment->purpose = $payment->purpose ?: $purpose;
            if (!$payment->exists) {
                $payment->status = 'pending';
                // LIPR's transaction id arrives with the callback; until then a placeholder unique to this payment
                // (transaction_id is unique: an empty one let only one payment at a time be pending app-wide, so every
                // other payment started meanwhile was never recorded and its poll was refused)
                $payment->transaction_id = 'PENDING-'.$reference;
                $payment->amount = $amountKes;
                $payment->amount_usd = $kesToUsd ? round($amountKes * $kesToUsd, 2) : null;
            }
            $payment->save();
        } catch (\Throwable $e) {
            Log::warning('Could not record the payment intent: '.$e->getMessage());
        }
    }


    // SPECIAL BULK METHOD
    public function program_milestone_bulk(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized!'], 401);
        }

        $user = Auth::user();

        try {
            $request->validate([
                'pitch_ids' => 'required',
                'referenceId' => 'required',
            ]);

            $referenceId = $request->referenceId;
            $pitch_ids = array_filter(array_map('intval', $request->pitch_ids));
            if (empty($pitch_ids)) {
                throw new \Exception('Invalid pitch IDs', 400);
            }

            $paymentExists = LiprPayment::where('reference_id', $referenceId)->first();
            $paidAmount = $paymentExists->amount_usd;

            if (!$paymentExists) {
                return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
            }

            if (strtolower($paymentExists->status) === "failed") {
                return response()->json([
                    'status' => 'failed',
                    'updated_at' => now(),
                    'message' => 'Customer rejected payment or did not pay',
                ], 200);
            }

            $this->processBulkMilestonePayment($referenceId, $pitch_ids, $user);
            $this->recordBulkMilestoneTransaction($user->id, $paymentExists->amount, $referenceId);

            return response()->json([
                'status' => $paymentExists->status,
                'updated_at' => now(),
            ], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            if (in_array($e->getCode(), [404, 422])) {
                return response()->json(['message' => $e->getMessage()], $e->getCode());
            }

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }


    //------------------------------------------------------------------------------
    // P R I V A T E    H E L P E R S
    //------------------------------------------------------------------------------

    private function validateInitiatePaymentRequest(Request $request): void
    {
        if ($request->purpose === 'program_milestone_bulk') {
            $request->validate([
                'amount' => 'required|numeric',
                'amountKes' => 'numeric',
                'acc_number' => 'required',
                'purpose' => 'required|string',
                'purpose_text' => 'required|string',
                'listing_id' => 'required',
            ]);

            return;
        }

        $request->validate([
            'amount' => 'required|numeric',
            'acc_number' => 'required',
            'purpose' => 'required|string',
            'amountKes' => 'numeric|required_if:purpose,bids',
            'purpose_text' => 'required|string',
            'listing_id' => 'required|numeric',
        ]);
    }

    private function resolveInitiatePaymentDetails(Request $request, string $callbackUrl): array
    {
        $amount = null;
        $amountKes = null;
        $order = null;

        if ($request->purpose === 'bids') {
            $amountKes = round($request->amountKes, 0);
        } elseif ($request->purpose === 'small_fee') {
            $listing = Listing::select('id', 'investors_fee')->where('id', $request->listing_id)
                ->with('owner')
                ->firstOrFail();
            // The unlock fee is a fixed KSh amount (Listing::investors_fee): charged as it is, never converted from USD
            $amount = $listing->investors_fee;
            $amountKes = self::kesWithFees($amount, $request->amount);
        } elseif ($request->purpose === 'awaiting_payment') {
            $bid = AcceptedBids::select('id', 'business_id', 'amount', 'investor_id')->where('id', $request->listing_id)
                ->firstOrFail();
            if ((int) $bid->investor_id !== (int) Auth::id()) {
                throw new \Exception('This investment is not yours.', 403);
            }
            // A business's money (goal, milestones, bids) is in KSh, as the business form and checkout show it
            $amount = round($bid->amount * 0.75, 2);
            $amountKes = self::kesWithFees($amount, $request->amount);
        } elseif ($request->purpose === 'program_milestone') {
            $milestone = ProgramMilestone::where('id', $request->listing_id)
                ->with('application')
                ->firstOrFail();
            $amount = $milestone->amount;
            $callbackUrl = \App\Service\LiprMpesa\LiprCallback::url('/lipr-callback/disbursement-direct'); // immeadiate

            if ($milestone->application->program_owner_id !== auth()->id()) {
                throw new \Exception('Unauthorized', 403);
            }

            if ($milestone->fund_release_status !== 'approved') {
                throw new \Exception('Milestone is not ready for fund release.', 422);
            }

            if (!$milestone->canReleaseInSequence()) {
                throw new \Exception('Earlier milestones must be completed and approved before paying this milestone.', 422);
            }
        } elseif ($request->purpose === 'program_disbursement_escrow') {
            $milestone = ProgramMilestone::where('id', $request->listing_id)
                ->with('application')
                ->firstOrFail();
            $amount = $milestone->application->total_amount_requested ?? $milestone->application->awarded_amount;
            // Milestones already released (for example imported historic payments) are not paid into escrow again: only the remainder is.
            $releasedAmount = ProgramMilestone::where('app_id', $milestone->app_id)->where('is_template', false)->where('fund_release_status', 'released')->sum('amount');
            if ($releasedAmount > 0) {
                $amount = ProgramMilestone::where('app_id', $milestone->app_id)->where('is_template', false)->where('fund_release_status', '!=', 'released')->sum('amount');
            }
            $callbackUrl = \App\Service\LiprMpesa\LiprCallback::url('/lipr-callback/disbursement-escrow');

            if ($milestone->application->program_owner_id !== auth()->id()) {
                throw new \Exception('Unauthorized', 403);
            }
        } elseif ($request->purpose === 'program_milestone_bulk') {
            $pitchIds = $request->listing_id;
            $amount = ProgramMilestone::whereIn('app_id', $pitchIds)->where('fund_release_status', '!=', 'released')
                ->orderBy('id')->get()->groupBy('app_id')
                ->map(fn($group) => $group->first()->amount)
                ->sum();
        } elseif ($request->purpose === 'capital_milestone') {
            // The capital provider pays one tranche: the milestone amount plus the platform and M-Pesa fee (ISSUE-22);
            // the entrepreneur receives the full amount (MpesaPollingController@capitalDisbursementStatus)
            $milestone = CapitalMilestone::where('id', $request->listing_id)->firstOrFail();
            if ($refusal = \App\Service\Capital\CapitalRelease::refusal(Auth::user(), $milestone)) {
                throw new \Exception($refusal[0], $refusal[1] === 409 ? 422 : $refusal[1]);
            }
            $base = (float) $milestone->amount;
            $amount = round($base + \App\Service\Capital\CapitalRelease::fee($base, 'mpesa'), 2);
        } elseif ($request->purpose === 's_mile') {
            // Paying a booked service: listing_id is one of the booking's milestones. Only the person who booked pays,
            // once the provider has accepted and while it is unpaid; the amount comes from the booking (agreed price,
            // else the service price) plus the checkout fees, never from the request
            $milestone = ServiceBookingMilestone::with('booking.service')->where('id', $request->listing_id)->first();
            if (!$milestone || !$milestone->booking) {
                throw new \Exception('Booking not found.', 404);
            }
            try {
                ServiceBookingFlow::assertPayable($milestone->booking, Auth::user());
            } catch (\RuntimeException $e) {
                throw new \Exception($e->getMessage(), 422);
            }
            $amountKes = ServiceBookingFlow::charge($milestone->booking, in_array($request->payment_method, ['local_card', 'intl_card'], true) ? $request->payment_method : 'mpesa');
        } elseif ($request->purpose === 'reviewer_payment') {
            $order = ReviewerOrder::where('id', $request->listing_id)
                ->with(['reviewer', 'program'])
                ->firstOrFail();

            if ($order->program->user_id !== Auth::id()) {
                throw new \Exception('Unauthorized', 403);
            }

            if ($order->payment_status === 'completed') {
                throw new \Exception('Already paid', 422);
            }

            if (!in_array($order->work_status, ['delivered', 'approved'])) {
                throw new \Exception('Reviewer has not delivered work yet', 422);
            }

            if (!$order->reviewer->lipr_wallet) {
                throw new \Exception('Reviewer does not have a LIPR wallet configured', 422);
            }

            $amount = $order->fee_amount;
            $callbackUrl = \App\Service\LiprMpesa\LiprCallback::url('/lipr-callback/reviewer-payment');
        }
        else {
            throw new \Exception('Invalid purpose', 400);
        }

        return compact('amount', 'amountKes', 'callbackUrl', 'order');
    }

    /**
     * What M-Pesa charges for a KSh amount due: the checkout's total (the amount plus the platform and gateway fees it
     * shows), never less than the amount due and never more than the fees could add (10%).
     */
    private static function kesWithFees($due, $requested): float
    {
        $due = (float) $due;
        $requested = is_numeric($requested) ? (float) $requested : 0;

        return round(min(max($requested, $due), $due * 1.10), 0);
    }

    private function calculateInitiatePaymentAmount(string $purpose, $amount, $amountKes)
    {
        if ($purpose !== 'bids') {
            if (in_array($purpose, [
                'program_milestone_bulk',
                'program_milestone',
                // the escrow amount (total, or the unreleased remainder) is worked out in $amount, not $amountKes
                'program_disbursement_escrow',
                'capital_milestone',
                'reviewer_payment',
            ])) {
                $amountKes = round($amount, 0);
            } else {
                //$amountPayable = $this->checkoutCalculator->mpesa($amount, 'mpesa');
                $amountKes = round($amountKes, 0);
            }
        }

        return $amountKes;
    }

    private function buildInitiatePaymentPayload(Request $request, string $callbackUrl, $amountKes, $platform_wallet): array
    {
        return [
            'requestId' => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
            'resultUrl' => $callbackUrl,
            'timeoutUrl' => \App\Service\LiprMpesa\LiprCallback::url('/lipr-callback/timeout'),
            'metadata' => ['listingId' => $request->listing_id],
            'wallet' => $platform_wallet, // escrow(test), tujitume(prod)
            'narration' => $request->purpose,
            'recipients' => [[
                'amount' => $amountKes,
                'account' => $request->acc_number,
            ]],
        ];
    }

    /** Why LIPR did not start a payment (no reply, a non-200 status, or the first recipient refused), or null when it did. */
    public static function liprRefusal($result): ?string
    {
        if (! is_array($result)) {
            return 'M-Pesa could not be reached';
        }
        $first = $result['data'][0] ?? null;
        if (($result['status'] ?? null) !== 200 || ($result['success'] ?? true) === false || (is_array($first) && ($first['success'] ?? true) === false)) {
            $reason = (is_array($first) ? ($first['reason'] ?? null) : null) ?? $result['message'] ?? $result['error'] ?? 'M-Pesa refused the request';

            return is_string($reason) ? rtrim($reason, '.') : 'M-Pesa refused the request';
        }

        return null;
    }

    private function sendInitiatePaymentRequest(array $fields, $token)
    {
        $url = config('services.lipr.base_path') . '/partners/v1/payments/mobile-money/stk';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Cache-Control: no-cache',
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        return json_decode(curl_exec($ch), true);
    }



    private function processBulkMilestonePayment(string $referenceId, array $pitchIds, $user): void
    {
        DB::transaction(function () use ($referenceId, $pitchIds, $user) {
            $payment = LiprPayment::where('reference_id', $referenceId)->lockForUpdate()->first();

            if ($payment->status === 'completed') {
                throw new \Exception('Payment already completed.', 409);
            }

            if (strtolower($payment->status) !== 'successful') {
                throw new \Exception('Payment not ready for crediting.', 422);
            }

            foreach ($pitchIds as $pitchId) {
                $this->processBulkMilestoneForApplication($pitchId, $user);
            }

            $payment->update(['status' => 'completed']);
        });
    }

    private function processBulkMilestoneForApplication(int $pitchId, $user): void
    {
        $pitch = ProgramApplication::with('program')->find($pitchId);
        $milestone = ProgramMilestone::where('app_id', $pitchId)->where('fund_release_status', '!=', 'released')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->first();

        if (!$pitch || !$milestone) {
            return;
        }

        if ($pitch->program_owner_id !== $user->id) {
            throw new \Exception('Unauthorized action.', 403);
        }

        if ($milestone->status === 1) {
            return;
        }

        $emails = User::whereIn('id', [$pitch->user_id, $pitch->program_owner_id])
            ->pluck('email', 'id');
        $smeEmail = $emails[$pitch->user_id];
        $programOwnerEmail = $emails[$pitch->program_owner_id];

        $transferAmount = round($milestone->amount, 2);
        $amountToTransfer = $this->checkoutCalculator->mpesaGC($transferAmount, 'mpesa');
        $amountKes = round($this->usdToKes * $amountToTransfer, 2);

        $transfer = $this->liprW2W->send(
            $amountKes,
            $pitch->sme->lipr_wallet,
            $this->tujitume_lipr,
            'Program Bulk Milestone Disbursement'
        );

        if (!$transfer) {
            throw new \Exception('Tujitme lipr wallet does not exist.', 404);
        }

        if (!$transfer['success']) {
            throw new \Exception($transfer['errors'][0], 422);
        }

        $milestone->update([
            'status' => 1,
            'fund_released' => 1,
        ]);
        $pitch->program->decrement('available_amount', $milestone->amount);
        $this->balance->updateBalance($pitch->user_id, $amountToTransfer, 'lipr');

        $this->notifyBulkMilestoneReleased($pitch, $milestone, $programOwnerEmail, $smeEmail);
    }

    private function notifyBulkMilestoneReleased(
        ProgramApplication $pitch,
        ProgramMilestone $milestone,
        $programOwnerEmail,
        $smeEmail
    ): void {
        try {
            $text = $milestone->title . ' fund for ' . $pitch->program->program_title . ' has been released.';
            $this->notification->create(
                $pitch->user_id,
                $pitch->program->user_id,
                $text,
                'programs-overview/programs/discover',
                ' program'
            );

            $info = [
                'program' => $pitch->program->program_title,
                'amount' => $milestone->amount,
                'milestone_title' => $milestone->title,
            ];
            $recipients = [$programOwnerEmail, $smeEmail];

            Mail::send('opportunities.program_milestone', $info, function ($message) use ($recipients) {
                $message->to($recipients);
                $message->subject(' Program Milestone');
            });
        } catch (\Throwable $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);
            Log::warning('Mail sending or NotificationService failed: ' . $e->getMessage());
        }
    }

    private function recordBulkMilestoneTransaction(int $userId, $amount, string $referenceId): void
    {
        try {
            $this->transaction->create($userId, 'program_milestone_bulk', 'lipr', $amount, $referenceId);
        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);
            Log::info('Transaction Create Error: ' . $e->getMessage());
        }
    }


    // H E L P E R S


//Class Ends
}
