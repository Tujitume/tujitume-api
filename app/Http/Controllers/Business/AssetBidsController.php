<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Listing;
use App\Models\Misc\Setting;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\Business\Bid\AgreeToProgressVotingService;
use App\Service\Business\Bid\PendingAndAssetBidService;
use App\Service\File\ImageUploadService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

class AssetBidsController extends Controller
{
    protected $Client;
    protected BalanceService $balance;
    protected LiprW2W $liprW2W;
    protected $convert;
    protected $tujitume_lipr;

    public function __construct(StripeClient $client)
    {
        parent::__construct();

        $this->Client = $client;
        $this->balance = new BalanceService();
        $this->liprW2W = new LiprW2W();
        $this->convert = new CurrencyConverter();
        $this->tujitume_lipr = Setting::where('key', 'tujitume_lipr_wallet')->first()?->value ?? null;

    }

    public function storeAssetBid(Request $request, ImageUploadService $imageUpload )
    {
        try {
            $investor = Auth::user(); $investorId = $investor->id;

            $validated = $request->validate([
                'listing_id' => 'required|exists:listings,id',
                'amount'     => 'required|numeric|min:1',
                'percent'    => 'required|numeric|min:0|max:100',
                'serial'     => 'required|string|max:255',
                'legal_doc'  => 'nullable|file|mimes:pdf,docx',
                'optional_doc'=> 'nullable|file|mimes:pdf,docx',
                'photos'     => 'nullable|file|image|mimes:jpg,jpeg,png,gif',
            ]);

            $listing = listing::findOrFail($validated['listing_id']);
            $uploadPath = "files/bidsEquip/{$listing->id}/{$investorId}/";

            $photo = $request->file('photos');
            $legalDoc = $request->file('legal_doc');
            $optionalDoc = $request->file('optional_doc');

            // Handle file uploads
            //$compressor = new ImageCompressor();
            $photosPath = $imageUpload->save( $photo, $uploadPath);
            $legalDocPath = $imageUpload->save( $legalDoc, $uploadPath);
            $optionalDocPath = $imageUpload->save( $optionalDoc, $uploadPath);


            // Create bid
            $bid = BusinessBids::create([
                'date'          => now()->toDateString(),
                'investor_id'   => $investorId,
                'business_id'   => $listing->id,
                'owner_id'      => $listing->user_id,
                'type'          => 'Asset',
                'amount'        => $validated['amount'],
                'representation'=> $validated['percent'],
                'serial'        => $validated['serial'],
                'legal_doc'     => $legalDocPath,
                'optional_doc'  => $optionalDocPath,
                'photos'        => $photosPath
            ]);

            // Check milestone fulfillment
            $activeMilestone = $listing->milestones()->where('active',1)->first();
            $totalBid = $listing->business_bids->sum('amount');

            if ($activeMilestone && $totalBid >= $activeMilestone->amount && !$listing->threshold_met) {
                $listing->update(['threshold_met' => 1]);

                $this->emailService->send(
                    'Fulfills a milestone', 'bids.mile_fulfill',
                    ['business_name' => $listing->name], $listing->owner->email
                );

                $this->notification->create(
                    $listing->user_id, $investorId,
                    "A milestone for your business {$listing->name} can now be fulfilled. Start reviewing/accepting bids.",
                    'investment-bids', 'investor'
                );
            }

            // Notify owner about new bid
            $this->notification->create(
                $listing->user_id, $investorId,
                "You have a new bid from {$investor->fname}",
                'investment-bids', 'investor'
            );

            return response()->json([
                'message'=> 'Success! You will get a notification if your bid is accepted!'
            ], 200);

        } catch (ValidationException $ve) {
            return response()->json(['errors' => $ve->errors()], 422);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password','token'])]);

            return response()->json([
                'message'=> 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function CancelAssetBid($bidId, $action)
    {
        $bidId = base64_decode($bidId);

        try {
            DB::beginTransaction();

            $bid = AcceptedBids::findOrFail($bidId);

            $pendingBidService = new PendingAndAssetBidService($this->Client);

            $investor = User::select('id','fname','lname','email')
                ->findOrFail($bid->investor_id);

            $business = $bid->listing; $owner = $business->owner;

            $investorName = $investor->fname.' '.$investor->lname;

            if ($action === 'confirm') {

                $pendingBidService->sendCancelConfirmation(
                    $bid, $investor, $business, $owner, $investorName
                );

            } else {

                $pendingBidService->processBidCancellation(
                    $bid, $business, $owner, $investor, $investorName
                );
            }
            DB::commit();

            return redirect()->to(config('app.app_url').'dashboard')->send();

        } catch (\Exception $e) {

            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password','token']),]);

            return redirect()->to(config('app.app_url').'dashboard')->send();
        }
    }

    public function CancelAssetRelease($bidId, $action)
    {
        $bidId = base64_decode($bidId);

        try {
            $bid = AcceptedBids::findOrFail($bidId);

            $investor = User::select('fname','lname','email')->find($bid->investor_id);
            $owner = $bid->listing->owner; $businessName = $bid->business->name;
            $invName = $investor->fname.' '.$investor->lname;


            if ($action === 'confirm') {
                $this->emailService->send(
                    'Equipment Cancel Confirmation',
                    'bids.eqp_cancel_confirm',
                    [
                        'business_name'=>$businessName,
                        'business_owner'=>$bid->business_id,
                        'manager'=>$bid->project_manager,
                        'bid_id'=>base64_encode($bidId)
                    ],
                    $investor->email
                );

                $this->notification->createWithBidId(
                    $bid->investor_id, $owner->id, $bidId,
                    'Your bid to business '.$businessName.' will be cancelled.',
                    'eqp_cancel_confirm', 'business'
                );

            } else {
                $this->emailService->send(
                    'Bid Cancelled', 'bids.cancelled',
                    ['investor'=>$invName,'type'=>$bid->type,'business_name'=>$businessName],
                    $owner->email
                );

                $this->notification->create(
                    $owner->id, $bid->investor_id,
                    'A bid to business '.$businessName.' was cancelled by '.$invName,
                    'investment-bids', 'business'
                );

                $this->notification->create(
                    $bid->investor_id, $owner->id,
                    'Your bid to business '.$businessName.' was cancelled.',
                    '/my-investments', 'business'
                );

                $bid->delete();
            }

            return redirect()->to(config('app.app_url').'dashboard')->send();

        } catch (\Exception $e) {
            return redirect()->to(config('app.app_url').'dashboard')->send();
        }
    }

    public function releaseEquipment($business_id, $manager_id, $bid_id)
    {
        try {
            $bid_id_decoded = base64_decode($bid_id);

            $listing = Listing::findOrFail($business_id);
            $investor = User::findOrFail(Auth::id());
            $b_owner = $listing->owner;

            $investor_name = $investor->fname.' '.$investor->lname;
            $manager = $manager_id ? User::find($manager_id) : null;
            $manager_name = $manager ? $manager->fname.' '.$manager->lname : null;

            // Voting / release payment processing logic
            $agreeToVotingService = new AgreeToProgressVotingService($this->Client);

            $agreeToVotingService->agreeToReleaseAssetAndMonetaryBid($bid_id);

            // Notification & Email to Business Owner
            $this->emailService->send(
                'Equipment Released', 'bids.manager_eqp_alert',
                [
                    'investor_name'=>$investor_name,
                    'contact'=>$investor->email,
                    'owner_name'=>$b_owner->fname.' '.$b_owner->lname,
                    'contact2'=>$b_owner->email,
                    'b_name'=>$listing->name,
                    'to'=> $manager ? 'PM' : 'Owner'
                ],
                $manager ? $manager->email : $b_owner->email
            );

            $this->createNotification(
                $b_owner->id, $investor->id,
                'Equipment from '.$investor_name.' has been released.',
                '/', 'investor'
            );

            // Notification & Email to Manager if exists
            if ($manager) {
                $this->emailService->send(
                    'Equipment Released', 'bids.manager_eqp_alert',
                    [
                        'investor_name'=>$investor_name,
                        'contact'=>$investor->email,
                        'owner_name'=>$manager_name,
                        'contact2'=>$manager->email,
                        'b_name'=>$listing->name, 'to'=>'BO'
                    ],
                    $b_owner->email
                );

                $this->createNotification(
                    $manager->id, $investor->id,
                    'Equipment from '.$investor_name.' has been released.',
                    '/', 'investor'
                );
            }

            // Update bid status
            AcceptedBids::where('id', $bid_id_decoded)->update(['status'=>'equipment_released']);

            return response()->json(['message'=>'Equipment Released'], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input'=>request()->except(['password','token'])]);

            return response()->json([
                'message'=>'Something went wrong, please try again later.'
            ], 500);
        }
    }

    // Verification of Asset
    public function askInvestorToVerify(int $id)
    {
        try {
            $bid = AcceptedBids::findOrFail($id);

            $investor = User::findOrFail($bid->investor_id);
            $listing  = Listing::select('name')->findOrFail($bid->business_id);

            $this->emailService->send(
                'Investor Verification Request', 'bids.askInvestorToVerify',
                ['business_name' => $listing->name, 'bid_id' => base64_encode($id)],
                $investor->email
            );

            return response()->json(['message' => 'An email with a request has been sent to the investor.'], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['id' => $id]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function requestOwnerToVerify(int $bidId)
    {
        try {
            $bid     = AcceptedBids::with('investor')->findOrFail($bidId);
            $listing = Listing::select('name', 'user_id')->findOrFail($bid->business_id);
            $owner   = User::findOrFail($listing->user_id);

            $text = 'Investor ' . $bid->investor->name . ' requested you to verify their equipment for ' . $listing->name;

            $this->notification->createWithBidId(
                $bid->owner_id, $bid->investor_id, $bidId, $text, 'verify_request', 'investor'
            );

            $this->emailService->send(
                'Equipment Verify Request', 'bids.verify_request',
                ['business_name' => $listing->name, 'investor' => $bid->investor->name],
                $owner->email
            );

            AcceptedBids::where('id', $bidId)->update(['status' => 'under_verification']);

            return response()->json(['message' => 'Success, please wait for the Business Owner to contact you.'], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['bid_id' => $bidId]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function markAsVerified(int $id)
    {
        DB::beginTransaction();
        try {
            $bid = AcceptedBids::findOrFail($id);

            if ($bid->status === 'verified') {
                return response()->json(['message' => 'This investment is already verified.'], 409);
            }

            $bid->update(['status' => 'verified']);

            $listing = $bid->listing;
            $listing->increment('amount_collected', $bid->amount);
            $listing->increment('invest_count', 1);
            $listing->active_milestone()?->increment('funding_collected', $bid->amount);

            DB::commit();

            $this->notification->createWithBidId(
                $bid->owner_id, $bid->investor_id, $bid->id,
                'Your bid is now verified for ' . $listing->name . '. You can now release the milestone.',
                'awaiting_release_eqp', 'investor'
            );

            $this->emailService->send(
                'Equipment Release Request', 'bids.equip_release_request',
                [
                    'business_owner' => $bid->business_id,
                    'manager' => $bid->project_manager,
                    'bid_id' => base64_encode($id)
                ],
                $bid->investor->email
            );

            return response()->json(['message' => 'Bid marked as verified.'], 200);

        } catch (Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['id' => $id]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


}
