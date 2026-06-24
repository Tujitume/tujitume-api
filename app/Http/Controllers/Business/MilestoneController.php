<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Listing;
use App\Models\Milestones\FinalApprovalDocuments;
use App\Models\Milestones\FinalApprovalVote;
use App\Models\Milestones\MilestoneExecutionDocuments;
use App\Models\Milestones\Milestones;
use App\Models\Shared\PMAudit;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\Business\Milestone\MilestoneLifecycleService;
use App\Service\Business\Milestone\MilestoneNotificationService;
use App\Service\Business\Milestone\MilestonePMCandidates;
use App\Service\File\FileUploadService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use App\Service\Misc\GlobalVotingService;
use Carbon\Carbon;
use DateTime;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

class MilestoneController extends Controller
{
    protected $milestoneLifeCycle;
    protected LiprW2W $liprW2W;
    protected $votingService;
    protected $Client;
    public function __construct(StripeClient $client)
    {
        $this->Client = $client;

        $this->public = env('LIPA_PUBLIC_KEY');
        $this->secret = env('LIPA_SECRET');

        parent::__construct();

        $this->balance = new BalanceService();

        $this->liprW2W = new LiprW2W();
        $this->convert = new CurrencyConverter();
        $this->usdToKes = $this->convert->UsdToKes();
        $this->milestoneLifeCycle = new MilestoneLifecycleService();
        $this->candidates = new MilestonePMCandidates();
        $this->votingService = new GlobalVotingService();
    }

    /* Returns all milestones for a user */
    public function index(){
        try{
            if( !Auth::check() ){
                return response()->json(['message' => 'Unauthorized!' ],401);
            }
            $user = Auth::user();
            //$user_id = $user->id;

            $listings = Listing::with(['milestones.accepted_bids'])->where('user_id', $user->id)->get();
            if($listings->count() < 1){
                return response()->json([ 'message' => 'No listings found.' ], 404);
            }

            foreach($listings as $listing){
                // Evaluate milestones (updates statuses, active flags, deadlines)
                $this->milestoneLifeCycle->evaluateListing($listing);

            }

            return response()->json([
                'listings' => $listings,
                //'milestones' => $milestones
            ]);
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

    /* Returns all milestones for a business */
    public function show($listing_id){
        try{
            /** @var \App\Models\Business\Listing $listing */

            $listing = Listing::with(['milestones.accepted_bids'])->findOrFail($listing_id);

            // Evaluate milestones (updates statuses, active flags, deadlines)
            $milestones = $this->milestoneLifeCycle->evaluateListing($listing);

            // Return as JSON or pass to view
            return response()->json([
                //'listing' => $listing,
                'milestones' => $milestones,
                'listing_threshold' => $listing->threshold_met,
            ]);
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

    // S T O R E
    public function saveMilestone(Request $request)
    {
        $uploadedFiles = [];

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'title'            => 'required|string|max:255',
                'business_id'      => 'required|integer|exists:listings,id',
                'amount'           => 'required|integer|min:1',
                'file'             => 'nullable|file|mimes:pdf,doc,docx|max:2048',
                'start_date'       => 'nullable|date|after_or_equal:today',
                'n_o_days'         => 'required|integer|min:1',
                'funding_duration' => 'required|integer|min:1',
                'time_type'        => 'nullable|string|in:Days,Weeks,Months',
            ]);

            $listing = Listing::findOrFail($validated['business_id']);

            if($listing->user_id !== Auth::id()){
                return response()->json(['message' => 'Unauthorized!'], 401);
            }

            $noDays = match($validated['time_type'] ?? 'Days') {
                'Weeks'  => $validated['n_o_days'] * 7,
                'Months' => $validated['n_o_days'] * 30,
                default  => $validated['n_o_days'],
            };

            $milestones  = $listing->milestones()->orderBy('id')->get();
            $startDate   = $milestones->isNotEmpty()
                ? Carbon::parse($milestones->last()->deadline_date)
                : Carbon::parse($validated['start_date']);
            $deadlineDate = $startDate->copy()->addDays($validated['funding_duration']);

            //Auto fill
            $validated['start_date'] = $startDate->format('Y-m-d');
            $validated['deadline_date'] = $validated['expected_end_date'] = $deadlineDate->format('Y-m-d');

            $coveredAmount = $listing->milestones()->sum('amount') + $validated['amount'];
            if ((int) $coveredAmount > (int) $listing->investment_needed) {
                return response()->json(['message' => 'Amount exceeds the total investment needed.'], 422);
            }

            if ($request->hasFile('file')) {
                $validated['document']  =  $this->fileUpload->saveFile(
                    $request->file('file'), 'files/milestones/' . $validated['business_id']
                );
                $uploadedFiles[] = $validated['document'];
            }

            Milestones::create([
                ...$validated,
                'user_id'            => Auth::id(),
                'listing_id'         => $validated['business_id'],
                'n_o_days'           => $noDays,
                'share'              => round(($validated['amount'] / $listing->investment_needed) * ($listing->share ?? 0), 2),
                'status'             => $milestones->isNotEmpty() ? 'to_do' : 'locked',
            ]);

            DB::commit();
            return response()->json(['message' => 'Milestone created successfully.'], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (Exception $e) {
            DB::rollBack();

            foreach ($uploadedFiles as $file) {
                if ($file && file_exists(public_path($file))) unlink(public_path($file));
            }
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function deleteMilestone(int $id)
    {
        try {
            $milestone = Milestones::findOrFail($id);

            if ($milestone->accepted_bids()->sum('amount') > 0) {
                return response()->json(['message' => 'An active milestone cannot be deleted.'], 400);
            }

            $milestone->accepted_bids()->delete();
            $milestone->delete();

            return response()->json(['message' => 'Milestone deleted successfully.'], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['id' => $id]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    // F I N A L  A P P R O V A L


    // F I N A L  A P P R O V A L    E N D S


    // All Documents
    public function documents($milestoneId)
    {
        try{
            $user_id = Auth::id();
            $milestone = Milestones::with([
                'pre_release_requests.docs',
                'rmeps',
            ])->find($milestoneId);

            $pre_release_documents = $milestone->pre_release_requests
                ->pluck('docs')
                ->flatten(1)
                ->map(fn($doc) => collect($doc)
                    ->except(['id','request_id','created_at','updated_at'])
                    ->filter()
                )
                ->reduce(function ($carry, $item) {
                    return array_merge($carry, $item->toArray());
                }, []);

            $data = [
                'title' => $milestone->title,
                'amount' => $milestone->amount,
                'progress_percentage' => $milestone->progress_percentage,
                'status' => $milestone->status,
                'milestone_document' => $milestone->document ?? null,
                'rmep_document' => $milestone->rmeps->pluck('rmep_document') ?? null,
                'pre_release_documents' => $pre_release_documents ?? null,
                'execution_proof_document' => null,
            ];

            return response()->json([
                'data'    => collect($data)
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }


    public function mile_status(Request $request){
        try{
            $mile_id = $request->id;$thisMile = Milestones::where('id',$mile_id)->first();$listing_id = $thisMile->listing_id;

            if($request->status == 'done'){
                if($thisMile->progress_percentage < 100 || $thisMile->status !== 'in_progress'){
                    return response()->json(['message' => 'Milestone progress is less than 100%'], 422);
                }
                //Last Milestone Check
                $next_mile = Milestones::where('listing_id',$listing_id)->where(function($q){
                    $q->where('status','to_do')->orWhere('status','in_progress'); })->first();

                if(!$next_mile){
                    $bids = AcceptedBids::where('business_id',$listing_id)
                        ->where('ms_id',$mile_id)->get();
                    foreach($bids as $bid){
                        $investor = User::where('id',$bid->investor_id)->first();
                        $investor_mail = $investor->email;
                        $list = listing::where('id',$bid->business_id)->first();
                        $info=[ 'business_name'=>$list->name,'business_id' => base64_encode(base64_encode($list->id)) ];
                        $user['to'] =  $investor_mail; //'tottenham266@gmail.com';
                        //Email
                        Mail::send('bids.invest_completion_alert', $info, function($msg) use ($user){
                            $msg->to($user['to']);
                            $msg->subject('Investment Completion Alert');
                        });
                        $text = 'All milestones of business '.$list->name.'
                is done.<br />You can now review the business?';
                        $this->createNotification($investor->id, $list->id, $text
                            ,'business_review',' business');
                    }
                    return response()->json(['status' => 200, 'message' => 'Status set success, mail sent!'], 200);
                }
                $thisMile->update([ 'status' => $request->status ]);
                $bids = AcceptedBids::where('business_id',$listing_id)
                    ->where('ms_id',$mile_id)->get();
                foreach($bids as $bid){
                    $investor = User::where('id',$bid->investor_id)->first();
                    if($investor)
                        $investor_mail = $investor->email;

                    $list = listing::where('id',$bid->business_id)->first();
                    $info=[ 'business_name'=>$list->name, 'mile_name'=>$thisMile->title,
                        'bid_id' => $bid->id ];
                    $user['to'] =  $investor_mail; //'tottenham266@gmail.com';

                    Mail::send('bids.milecompletion_alert', $info, function($msg) use ($user){
                        $msg->to($user['to']);
                        $msg->subject('Milestone Completion Alert');
                    });

                    $text = 'Milestone '.$thisMile->title.' of business '.$list->name.'
                is done. Do you want to Continue to the Next Milestone?';
                    $this->notification->createWithBidId(
                        $investor->id,$bid->owner_id, $bid->id, $text
                        ,'next_mile_agree',' business'
                    );
                }
                return response()->json(['status' => 200,'message' => 'Status set success, mail sent!']);
            }
            else {
                return response()->json(['status' => 200,'message' => 'Status set success!']);
            }
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    //** Maybe unused */
    public function getMilestones(int $id)
    {
        $investorId = Auth::id();
        $milestones = Milestones::where('listing_id', $id)->get();

        if ($milestones->isEmpty()) {
            return response()->json(['data' => $milestones, 'progress' => 0, 'length' => 0]);
        }

        $listing      = Listing::findOrFail($id);
        $investCheck  = AcceptedBids::where('business_id', $id)->where('investor_id', $investorId)->exists();
        $hasOpenMile  = $milestones->whereIn('status', ['To Do', 'In Progress'])->isNotEmpty();
        $allowToReview = !$hasOpenMile && $investCheck;

        $amountCovered = AcceptedBids::where('business_id', $id)->sum('amount');
        $progress      = $listing->investment_needed > 0
            ? ($amountCovered / $listing->investment_needed) * 100
            : 0;

        foreach ($milestones as $mile) {
            $mile->access  = ($mile->investor_id === $investorId);
            $dueDate       = date('Y-m-d H:i:s', strtotime($mile->created_at . ' +' . $mile->n_o_days . ' days'));
            $now           = now();
            $diff          = (new DateTime($now))->diff(new DateTime($dueDate));
            $mile->time_left = $now > $dueDate
                ? 'L A T E !'
                : $diff->d . ' days, ' . $diff->h . ' hours, ' . $diff->i . ' minutes';
        }

        return response()->json([
            'data'            => $milestones,
            'progress'        => $progress,
            'share'           => ($listing->share ?? 0) / 100,
            'amount_required' => $listing->investment_needed - $listing->amount_collected,
            'running'         => $milestones->where('active', true)->isNotEmpty() ? 1 : 0,
            'allowToReview'   => $allowToReview,
        ]);
    }


    //  H  E  L  P  E  R  S
    public function notify($recipient, $type, $milestone, $info = null)
    {
        $notifyService = new MilestoneNotificationService();
        $notifyService->notify($recipient, $type, $milestone, $info);

    }

    //If investor rejects AND admin confirms issue is valid
    public function refundBid($milestoneId)
    {
        //...
    }



}
