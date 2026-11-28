<?php

namespace App\Http\Controllers\Business;


use Exception;
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

    public function storeAssetBid(Request $request, ImageUploadService $imageUpload)
    {
        $investor = Auth::user(); $investorId = $investor->id;

        try {
            $validated = $request->validate([
                'listing_id'   => 'required|exists:listings,id',
                'amount'       => 'required|numeric|min:1',
                'percent'      => 'nullable|numeric|min:0|max:100', // ignored: the share is worked out from the amount
                'serial'       => 'required|string|max:255',
                'legal_doc'    => 'nullable|file|mimes:pdf,docx|max:5120',
                'optional_doc' => 'nullable|file|mimes:pdf,docx|max:5120',
                'photos'       => 'nullable|file|image|mimes:jpg,jpeg,png,gif|max:5120',
            ]);
        } catch (ValidationException $ve) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $ve->errors()], 422);
        }

        $listing = Listing::with('owner')->findOrFail($validated['listing_id']);
        if ((int) $listing->user_id === (int) $investorId) {
            return response()->json(['message' => 'You cannot invest in your own business.'], 403);
        }
        if (!$listing->active) {
            return response()->json(['message' => 'This business is not taking investments yet.'], 422);
        }
        $stillNeeded = (float) $listing->investment_needed - (float) $listing->amount_collected;
        if ((float) $validated['amount'] > $stillNeeded) {
            return response()->json(['message' => 'The asset is worth more than this business still needs.'], 422);
        }

        try {
            $uploadPath = "files/bidsEquip/{$listing->id}/{$investorId}";
            $files = app(\App\Service\File\FileUploadService::class);
            // A photo goes through the image saver; the documents are files (they used to be sent to the image saver too)
            $photosPath = $imageUpload->save($request->file('photos'), $uploadPath.'/');
            $legalDocPath = $request->hasFile('legal_doc') ? $files->saveFile($request->file('legal_doc'), $uploadPath) : null;
            $optionalDocPath = $request->hasFile('optional_doc') ? $files->saveFile($request->file('optional_doc'), $uploadPath) : null;

            $share = $listing->investment_needed > 0
                ? round((float) $validated['amount'] / (float) $listing->investment_needed * (float) ($listing->share ?? 0), 4) : 0;

            $bid = DB::transaction(function () use ($investorId, $listing, $validated, $share, $legalDocPath, $optionalDocPath, $photosPath) {
                $bid = BusinessBids::create([
                    'date'           => now()->toDateString(),
                    'investor_id'    => $investorId,
                    'business_id'    => $listing->id,
                    'owner_id'       => $listing->user_id,
                    'type'           => 'Asset',
                    'amount'         => $validated['amount'],
                    'representation' => $share,
                    'serial'         => $validated['serial'],
                    'legal_doc'      => $legalDocPath,
                    'optional_doc'   => $optionalDocPath,
                    'photos'         => $photosPath,
                ]);

                // Same rule as a money bid: it counts as pending on the first milestone not yet fully funded
                $milestone = $listing->milestones()->orderBy('id')->get()->first(fn ($m) => (float) $m->funding_collected < (float) $m->amount);
                if ($milestone) {
                    $bid->update(['ms_id' => $milestone->id]);
                    $milestone->increment('pending_collected', (float) $validated['amount']);
                    $milestone->refresh();
                    if (!$listing->threshold_met && ((float) $milestone->pending_collected + (float) $milestone->funding_collected) >= (float) $milestone->amount) {
                        $listing->update(['threshold_met' => 1]);
                        $this->emailService->send('Fulfills a milestone', 'bids.mile_fulfill', ['business_name' => $listing->name], $listing->owner->email);
                        $this->notification->create($listing->user_id, $investorId,
                            "A milestone for your business {$listing->name} can now be fulfilled. Start reviewing/accepting bids.", 'investment-bids', 'investor');
                    }
                }

                return $bid;
            });

            // The owner is told in the app and by email; the investor gets "under review"
            $this->notification->create($listing->user_id, $investorId, 'You have a new bid from _name.', 'investment-bids', 'investor');
            $this->emailService->send('You Have a New Bid', 'bids.new_bid_owner', [
                'owner_name' => $listing->owner->first_name, 'investor_name' => trim($investor->first_name.' '.$investor->last_name),
                'business_name' => $listing->name, 'type' => 'Asset', 'amount' => config('app.currency', 'KES').' '.number_format((float) $validated['amount'], 2),
                'action_url' => \App\Service\Notification\EmailLink::url('investment-bids', $listing->owner), 'action_label' => 'Review the bid',
            ], $listing->owner->email);
            $this->emailService->send('Bid Under Review!', 'bids.under_review', ['business_name' => $listing->name, 'bid_id' => $bid->id, 'type' => 'Asset'], $investor->email);

            return response()->json(['message' => 'Success! You will get a notification if your bid is accepted!', 'bid_id' => $bid->id], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function CancelAssetBid($bidId, $action)
    {
        // A public email link (the id is only base64): it no longer cancels anything by itself. It opens the
        // investor's investments, where they withdraw signed in (with a confirmation) — the same rule as the other
        // money links in emails.
        $bid = AcceptedBids::find((int) base64_decode((string) $bidId));
        $investor = $bid ? User::find($bid->investor_id) : null;

        return redirect()->to(\App\Service\Notification\EmailLink::url('my-investments', $investor) ?? \App\Service\Notification\RecipientUrl::url('dashboard', $investor));
    }

    public function CancelAssetRelease($bidId, $action)
    {
        // A public email link (the id is only base64): it no longer cancels anything by itself. It opens the
        // investor's investments, where they withdraw signed in (with a confirmation) — the same rule as the other
        // money links in emails.
        $bid = AcceptedBids::find((int) base64_decode((string) $bidId));
        $investor = $bid ? User::find($bid->investor_id) : null;

        return redirect()->to(\App\Service\Notification\EmailLink::url('my-investments', $investor) ?? \App\Service\Notification\RecipientUrl::url('dashboard', $investor));
    }

    public function releaseEquipment($business_id, $manager_id, $bid_id)
    {
        try {
            $bid_id_decoded = base64_decode($bid_id);

            // Only the investor whose asset this is releases it, once the owner has verified it
            $accepted = AcceptedBids::find((int) $bid_id_decoded);
            if (!$accepted || (int) $accepted->investor_id !== (int) Auth::id() || (int) $accepted->business_id !== (int) $business_id) {
                return response()->json(['message' => 'Investment not found.'], 404);
            }
            if ($accepted->type !== 'Asset' || $accepted->status !== 'verified') {
                return response()->json(['message' => 'The business owner has to verify the equipment before it is released.'], 422);
            }

            $listing = Listing::findOrFail($business_id);
            $investor = User::findOrFail(Auth::id());
            $b_owner = $listing->owner;

            $investor_name = $investor->first_name.' '.$investor->last_name;
            $manager = $manager_id ? User::find($manager_id) : null;
            $manager_name = $manager ? $manager->first_name.' '.$manager->last_name : null;

            // Voting / release payment processing logic
            $agreeToVotingService = new AgreeToProgressVotingService($this->Client);

            $agreeToVotingService->agreeToReleaseAssetAndMonetaryBid($bid_id);

            // Notification & Email to Business Owner
            $this->emailService->send(
                'Equipment Released', 'bids.manager_eqp_alert',
                [
                    'investor_name'=>$investor_name,
                    'contact'=>$investor->email,
                    'owner_name'=>$b_owner->first_name.' '.$b_owner->last_name,
                    'contact2'=>$b_owner->email,
                    'b_name'=>$listing->name,
                    'to'=> $manager ? 'PM' : 'Owner'
                ],
                $manager ? $manager->email : $b_owner->email
            );

            $this->notification->create( // (createNotification does not exist here: the release answered 500 and never marked the equipment released)
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

                $this->notification->create( // (createNotification does not exist here: the release answered 500 and never marked the equipment released)
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
            $bid = AcceptedBids::find($id);
            if (!$bid || (int) $bid->owner_id !== (int) Auth::id()) {
                return response()->json(['message' => 'Investment not found.'], 404);
            }

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
            $bid     = AcceptedBids::with('investor')->find($bidId);
            if (!$bid || (int) $bid->investor_id !== (int) Auth::id()) {
                return response()->json(['message' => 'Investment not found.'], 404);
            }
            if ($bid->type !== 'Asset') {
                return response()->json(['message' => 'Only an asset investment is verified.'], 422);
            }
            $listing = Listing::select('name', 'user_id')->findOrFail($bid->business_id);
            $owner   = User::findOrFail($listing->user_id);
            $investorName = trim(($bid->investor->first_name ?? '').' '.($bid->investor->last_name ?? ''));

            $text = 'Investor ' . $investorName . ' requested you to verify their equipment for ' . $listing->name;

            $this->notification->createWithBidId(
                $bid->owner_id, $bid->investor_id, $bidId, $text, 'verify_request', 'investor'
            );

            $this->emailService->send(
                'Equipment Verify Request', 'bids.verify_request',
                ['business_name' => $listing->name, 'investor' => $investorName],
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
        $bid = AcceptedBids::find($id);
        if (!$bid || (int) $bid->owner_id !== (int) Auth::id()) {
            return response()->json(['message' => 'Investment not found.'], 404); // only the business owner verifies the asset
        }
        if ($bid->status === 'verified') {
            return response()->json(['message' => 'This investment is already verified.'], 409);
        }

        DB::beginTransaction();
        try {
            $bid->update(['status' => 'verified']);

            $listing = $bid->listing;
            $listing->increment('amount_collected', $bid->amount);
            $listing->refreshInvestorCount(); // distinct investors (this was +1 per verified asset)
            // The asset now counts on the milestone it was accepted for
            if ($bid->ms_id) {
                \App\Models\Milestones\Milestones::where('id', $bid->ms_id)->increment('funding_collected', $bid->amount);
            } else {
                $listing->active_milestone()?->increment('funding_collected', $bid->amount);
            }

            DB::commit();

            // The investor is the one who now releases the equipment (this notice used to go to the owner)
            $this->notification->createWithBidId(
                $bid->investor_id, $bid->owner_id, $bid->id,
                'Your asset for ' . $listing->name . ' is verified. You can now release the equipment.',
                'awaiting_release_eqp', 'business'
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
