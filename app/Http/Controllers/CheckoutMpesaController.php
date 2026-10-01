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
            $callbackUrl = rtrim(config('app.api_url'), '/') . '/lipr-callback';

            if (!$platform_wallet) {
                return response()->json(['message' => 'Tujitume platform lipr account not found.'], 404);
            }

            $payment = $this->resolveInitiatePaymentDetails($request, $callbackUrl);
            $amountKes = $this->calculateInitiatePaymentAmount(
                $request->purpose,
                $payment['amount'],
                $payment['amountKes']
            );

            if ($request->purpose === 'reviewer_payment') {
                $payment['order']->update(['fee_kes' => $amountKes]);
            }

            $amountKes = 100;

            $fields = $this->buildInitiatePaymentPayload($request, $payment['callbackUrl'], $amountKes, $platform_wallet);
            $result = $this->sendInitiatePaymentRequest($fields, $token);

            return response()->json([
                'liprResponse' => $result,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        }
        catch (\Exception $e) {
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
            $amount = $listing->investors_fee;
        } elseif ($request->purpose === 'awaiting_payment') {
            $bid = AcceptedBids::select('id', 'business_id', 'amount')->where('id', $request->listing_id)
                ->firstOrFail();
            $amount = round($bid->amount * 0.75, 2);
        } elseif ($request->purpose === 'program_milestone') {
            $milestone = ProgramMilestone::where('id', $request->listing_id)
                ->with('application')
                ->firstOrFail();
            $amount = $milestone->amount;
            $callbackUrl = rtrim(config('app.api_url'), '/') . '/lipr-callback/disbursement-direct'; // immeadiate

            if ($milestone->application->program_owner_id !== auth()->id()) {
                throw new \Exception('Unauthorized', 403);
            }
        } elseif ($request->purpose === 'program_disbursement_escrow') {
            $milestone = ProgramMilestone::where('id', $request->listing_id)
                ->with('application')
                ->firstOrFail();
            $amount = $milestone->application->total_amount_requested ?? $milestone->application->awarded_amount;
            $callbackUrl = rtrim(config('app.api_url'), '/') . '/lipr-callback/disbursement-escrow';

            if ($milestone->application->program_owner_id !== auth()->id()) {
                throw new \Exception('Unauthorized', 403);
            }
        } elseif ($request->purpose === 'program_milestone_bulk') {
            $pitchIds = $request->listing_id;
            $amount = ProgramMilestone::whereIn('app_id', $pitchIds)
                ->orderBy('id')->get()->groupBy('app_id')
                ->map(fn($group) => $group->first()->amount)
                ->sum();
        } elseif ($request->purpose === 'capital_milestone') {
            $milestone = CapitalMilestone::where('id', $request->listing_id)
                ->with('application')
                ->firstOrFail();
            $amount = $milestone->amount;
        } elseif ($request->purpose === 's_mile') {
            $milestone = ServiceBookingMilestone::with('service')->where('id', $request->listing_id)->first();
            $amount = $milestone->service->price;
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

            $amount = $order->fee_usd;
            $callbackUrl = rtrim(config('app.api_url'), '/') . '/lipr-callback/reviewer-payment';
        }

        return compact('amount', 'amountKes', 'callbackUrl', 'order');
    }

    private function calculateInitiatePaymentAmount(string $purpose, $amount, $amountKes)
    {
        if ($purpose !== 'bids') {
            if (in_array($purpose, [
                'program_milestone_bulk',
                'program_milestone',
                'capital_milestone',
                'reviewer_payment',
            ])) {
                $amountKes = round($amount, 0);
            } else {
                $amountPayable = $this->checkoutCalculator->mpesa($amount, 'mpesa');
                $amountKes = round($amountPayable, 0);
            }
        }

        return $amountKes;
    }

    private function buildInitiatePaymentPayload(Request $request, string $callbackUrl, $amountKes, $platform_wallet): array
    {
        return [
            'requestId' => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
            'resultUrl' => $callbackUrl,
            'timeoutUrl' => rtrim(config('app.api_url'), '/') . '/lipr-callback/timeout',
            'metadata' => ['listingId' => $request->listing_id],
            'wallet' => $platform_wallet, // escrow(test), tujitume(prod)
            'narration' => $request->purpose,
            'recipients' => [[
                'amount' => $amountKes,
                'account' => $request->acc_number,
            ]],
        ];
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
        $milestone = ProgramMilestone::where('app_id', $pitchId)
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
