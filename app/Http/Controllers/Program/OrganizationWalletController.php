<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Finance\LiprPayment;
use App\Models\Kyc\KycVerification;
use App\Models\Organizations\Organization;
use App\Models\Programs\ProgramWallet;
use App\Service\Misc\TransactionRecord as TransactionService;
use App\Service\Misc\ErrorLogService;
use App\Service\Program\OrganizationMainWallet as OrgWallet;
use App\Service\Program\OrganizationWalletLipr;
use App\Service\Program\OrganizationWithdrawal;
use App\Service\Program\ProgramAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The organization wallet (DECISION-6): its own LIPR account and KES balance, separate from everyone's personal
 * "My Wallet". Super Admins (and the owner) deposit, move money to programs and back, and withdraw (to their own My
 * Wallet, after an emailed code); the team can look.
 */
class OrganizationWalletController extends Controller
{
    private function organization(Request $request): Organization
    {
        $user = $request->user();
        $organization = $user?->organization_id ? Organization::find($user->organization_id) : Organization::where('owner_user_id', $user?->id)->first();
        if (! $organization || ! OrgWallet::canView($user, $organization)) {
            throw new RuntimeException('You are not part of an organization.', 403);
        }

        return $organization;
    }

    private function manageable(Request $request): Organization
    {
        $organization = $this->organization($request);
        if (! OrgWallet::canManage($request->user(), $organization)) {
            throw new RuntimeException('Only a Super Admin can do this with the organization wallet.', 403);
        }

        return $organization;
    }

    /** GET programs/organization-wallet */
    public function show(Request $request)
    {
        return $this->guard(function () use ($request) {
            $organization = $this->organization($request);

            return response()->json(['data' => OrgWallet::summary($organization) + [
                'can_manage' => OrgWallet::canManage($request->user(), $organization),
                // Taking money out: the account owner only, to their own My Wallet
                'can_withdraw' => OrganizationWithdrawal::canWithdraw($request->user(), $organization),
                'owner_name' => trim(($organization->owner?->first_name ?? '').' '.($organization->owner?->last_name ?? '')) ?: null,
                'kyc_verified' => $this->kycVerified($organization),
            ]]);
        });
    }

    /** POST programs/organization-wallet/setup: its own LIPR account, once the organization's KYC is verified */
    public function setup(Request $request, OrganizationWalletLipr $lipr)
    {
        return $this->guard(function () use ($request, $lipr) {
            $organization = $this->manageable($request);
            if (filled($organization->lipr_wallet)) {
                return response()->json(['message' => 'The organization wallet is already set up.', 'data' => OrgWallet::summary($organization)]);
            }
            if (! $this->kycVerified($organization)) {
                throw new RuntimeException("Verify your organization's KYC first; the organization wallet needs it.", 422);
            }
            $created = $lipr->createAccount($organization);
            if (! $created['success']) throw new RuntimeException($created['message'], 422);
            $organization->forceFill(['lipr_wallet' => $created['account']])->save();

            return response()->json(['message' => 'Organization wallet set up.', 'data' => OrgWallet::summary($organization->fresh())]);
        });
    }

    /** POST programs/organization-wallet/deposit {amount, phone}: M-Pesa asks the phone to pay into the organization wallet */
    public function deposit(Request $request, OrganizationWalletLipr $lipr)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:10', 'phone' => 'required|string|max:20']);

        return $this->guard(function () use ($request, $lipr, $data) {
            $organization = $this->manageable($request);
            if (! filled($organization->lipr_wallet)) throw new RuntimeException(OrgWallet::MISSING, 422);
            $stk = $lipr->stkDeposit($organization, round((float) $data['amount'], 2), preg_replace('/\D/', '', $data['phone']));
            if (! $stk['success']) throw new RuntimeException($stk['message'], 422);

            return response()->json(['message' => 'Check the phone to approve the payment.', 'reference' => $stk['reference']]);
        });
    }

    /** POST programs/organization-wallet/deposit-status {reference}: once M-Pesa confirms, the money is added (once) */
    public function depositStatus(Request $request, TransactionService $transactions)
    {
        $data = $request->validate(['reference' => 'required|string']);

        return $this->guard(function () use ($request, $data, $transactions) {
            $organization = $this->manageable($request);

            // 'success' keeps the program response wrapper from dropping 'status', which the page polls for
            return DB::transaction(function () use ($organization, $data, $transactions, $request) {
                $payment = LiprPayment::where('reference_id', $data['reference'])->lockForUpdate()->first();
                if (! $payment) return response()->json(['success' => true, 'status' => 'pending']);
                $status = strtolower((string) $payment->status);
                if ($status === 'failed') return response()->json(['success' => true, 'status' => 'failed', 'message' => 'The payment was rejected or not made.']);
                if ($status === 'completed') return response()->json(['success' => true, 'status' => 'completed', 'data' => OrgWallet::summary($organization->fresh())]);
                if ($status !== 'successful') return response()->json(['success' => true, 'status' => 'pending']);

                OrgWallet::adjust($organization, (float) $payment->amount);
                $payment->update(['status' => 'completed']);
                $transactions->create($request->user()->id, 'deposit', 'org-wallet', (float) $payment->amount, $data['reference']);

                return response()->json(['success' => true, 'status' => 'completed', 'data' => OrgWallet::summary($organization->fresh())]);
            });
        });
    }

    /** POST programs/organization-wallet/withdraw/code {amount}: email the Super Admin a code for this withdrawal */
    public function withdrawCode(Request $request)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:10|max:10000000']);

        return $this->guard(function () use ($request, $data) {
            $organization = $this->manageable($request);
            $email = OrganizationWithdrawal::sendCode($request->user(), $organization, round((float) $data['amount'], 2));

            return response()->json(['message' => 'We emailed a code to '.$email.'.', 'data' => [
                'email' => $email, 'expires_in_minutes' => OrganizationWithdrawal::CODE_MINUTES,
            ]]);
        });
    }

    /**
     * POST programs/organization-wallet/withdraw {amount, code}: free organization money to the Super Admin's own
     * My Wallet, after the emailed code (organization money never goes straight to a phone, paybill or till).
     */
    public function withdraw(Request $request)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:10|max:10000000', 'code' => 'required|digits:6']);

        return $this->guard(function () use ($request, $data) {
            $organization = $this->manageable($request);
            OrganizationWithdrawal::withdraw($request->user(), $organization, round((float) $data['amount'], 2), $data['code']);

            return response()->json(['message' => 'Sent to your My Wallet.', 'data' => OrgWallet::summary($organization->fresh())]);
        });
    }

    /** POST programs/wallets/{wallet}/move-back {amount}: unused program money back to the organization wallet */
    public function moveBack(Request $request, ProgramWallet $wallet)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:1']);

        return $this->guard(function () use ($request, $wallet, $data) {
            $organization = $this->manageable($request);
            if (OrgWallet::organizationOf($wallet->program)?->id !== $organization->id) throw new RuntimeException('This program is not your organization\'s.', 403);
            OrgWallet::moveBack($organization, $wallet, round((float) $data['amount'], 2));

            return response()->json(['message' => 'Moved back to the organization wallet.', 'data' => OrgWallet::summary($organization->fresh())]);
        });
    }

    private function kycVerified(Organization $organization): bool
    {
        return KycVerification::where('organization_id', $organization->id)->where('verification_type', 'organization')->where('status', 'verified')->exists();
    }

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code < 500 ? $code : 422);
        } catch (Throwable $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }
}
