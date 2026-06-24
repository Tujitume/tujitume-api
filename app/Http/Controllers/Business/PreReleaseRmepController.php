<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\BusinessBids;
use App\Models\Milestones\MilestonePreReleaseDocs;
use App\Models\Milestones\MilestonePreReleaseRequest;
use App\Models\Milestones\MilestoneRmep;
use App\Models\Milestones\Milestones;
use App\Models\Milestones\RmepVotes;
use App\Models\Shared\PMAudit;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\Business\Milestone\MilestoneFundReleaseService;
use App\Service\Business\Milestone\MilestoneLifecycleService;
use App\Service\Business\Milestone\MilestonePMCandidates;
use App\Service\File\FileUploadService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use App\Service\Misc\GlobalVotingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

class PreReleaseRmepController extends Controller
{
    protected $milestoneLifeCycle;
    protected LiprW2W $liprW2W;
    protected $emailService;
    protected $notification;
    protected $votingService;
    protected $Client;
    public function __construct(StripeClient $client)
    {
        parent::__construct();

        $this->Client = $client;

        $this->public = env('LIPA_PUBLIC_KEY');
        $this->secret = env('LIPA_SECRET');

        $this->balance = new BalanceService();

        $this->liprW2W = new LiprW2W();
        $this->convert = new CurrencyConverter();
        $this->usdToKes = $this->convert->UsdToKes();
        $this->milestoneLifeCycle = new MilestoneLifecycleService();
        $this->candidates = new MilestonePMCandidates();
        $this->votingService = new GlobalVotingService();
    }
    // P R E   R E L E A S E   Starts
    public function createPreReleaseRequest(Request $request, $milestoneId)
    {
        try{
            $validated = $request->validate([
                'written_statement'                               => 'required|boolean',
                // B - Proof of Planned Procurement
                'proof_of_procurement.quotation'             => 'nullable|boolean',
                'proof_of_procurement.proforma_invoice'      => 'nullable|boolean',
                'proof_of_procurement.vendor_estimate'       => 'nullable|boolean',
                'proof_of_procurement.mou_supplier_confirm'  => 'nullable|boolean',

                // C - Financial Reasonableness Check
                'financial_reasonableness.industry_benchmark'     => 'nullable|boolean',
                'financial_reasonableness.prior_proposal_align'   => 'nullable|boolean',
                'financial_reasonableness.project_budget_match'   => 'nullable|boolean',

                // D - Risk Flags / Compliance Items
                'risk_flags.kra_pin'                              => 'nullable|boolean',
                'risk_flags.business_registration'                => 'nullable|boolean',
                'risk_flags.updated_budget'                       => 'nullable|boolean',
                'risk_flags.project_plan_details'                 => 'nullable|boolean',

                // E - Media Proof
                'media_proof.photo_location'                      => 'nullable|boolean',
                'media_proof.photo_equipment'                     => 'nullable|boolean',
                'media_proof.photo_current_state'                 => 'nullable|boolean',
            ]);

            $milestone = Milestones::find($milestoneId);
            $owner =$milestone->listing->owner;

            if($milestone->progress_percentage < 100 || !$milestone->is_funded){
                return response()->json(['message' => 'Milestone is not fully funded yet.'], 422);
            }

            $selected = [
                'proof_of_procurement' => collect($request->proof_of_procurement ?? [])
                    ->filter(fn($v) => $v === true)->keys()->toArray(),

                'financial_reasonableness' => collect($request->financial_reasonableness ?? [])
                    ->filter(fn($v) => $v === true)->keys()->toArray(),

                'risk_flags' => collect($request->risk_flags ?? [])
                    ->filter(fn($v) => $v === true)->keys()->toArray(),

                'media_proof' => collect($request->media_proof ?? [])
                    ->filter(fn($v) => $v === true)->keys()->toArray(),
            ];

            $pre = MilestonePreReleaseRequest::create([
                'milestone_id' => $milestoneId,
                'investor_id'  => Auth::id(),
                'proof_of_procurement' => $selected['proof_of_procurement'],
                'financial_reasonableness'  => $selected['financial_reasonableness'],
                'risk_flags'                => $selected['risk_flags'],
                'media_proof'               => $selected['media_proof'],
            ]);
            //$selectedItems = array_keys(array_filter($validated, fn($value) => $value === true));



            // Update milestone status
            $milestone->status = 'pre_release_requested';
            $milestone->save();

            // Notify BO (frontend handles UI)
            $this->notify($owner, 'pre_release_requested', $milestone, $selected);

            return response()->json(['message' => 'Pre-release requirements submitted'], 200);
        }
        catch (ValidationException $e) {
            // return actual validation errors
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors()
            ], 422);
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

    public function getPreReleaseRequests($milestoneId)
    {
        try{
            $milestone = Milestones::with('pre_release_requests')->find($milestoneId);
            $requests = $milestone->pre_release_requests;
            return response()->json(['data' => $requests], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }

    public function getInvestorPreReleaseRequests($milestoneId)
    {
        try{
            $investor_id = Auth::id();
            $requests = MilestonePreReleaseRequest::with('docs')->where([
                'milestone_id' => $milestoneId,
                'investor_id' => $investor_id
            ])->get();

            //$requests = $requests->pre_release_requests;
            return response()->json(['data' => $requests], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }

    public function uploadPreReleaseDocs(Request $request, $requestId)
    {
        try{
            $fileUpload = new FileUploadService();
            $req = MilestonePreReleaseRequest::findOrFail($requestId);

            $selected = [
                'proof_of_procurement' => $req->proof_of_procurement ?? [],
                'financial_reasonableness'  => $req->financial_reasonableness ?? [],
                'risk_flags'                => $req->risk_flags ?? [],
                'media_proof'               => $req->media_proof ?? [],
            ];

            // dynamic validation rules
            $rules = ['written_statement' => 'required|string',];

            foreach ($selected as $group => $items) {
                foreach ($items as $item) {
                    $rules["{$group}.{$item}"] = 'required|file|mimes:jpg,png,docx,pdf|max:2048';
                    //$rules["{$group}.{$item}"] = 'nullable|mimes:jpg,png,docx,pdf|max:2048';
                }
            }

            $validated = $request->validate($rules);

            // save files
            $docs = new MilestonePreReleaseDocs();
            $docs->request_id = $req->id;

            $docs->written_statement = $validated['written_statement'] ?? null;

            $path = 'files/milestonePreRelease/' . $docs->request_id;

            foreach ($selected as $group => $items) {
                foreach ($items as $item) {
                    $files = $request->file("$group.$item") ?? [];
                    $docs->$item = $fileUpload->saveFile($files, $path);
                }
            }

            $docs->is_complete = true;
            $docs->save();

            // Update request
            $req->status = 'submitted';
            $req->save();

            // Update milestone

            $this->notify($req->investor, 'docs_ready_for_review', $req->milestone);

            return response()->json(['message' => 'Pre-release documents uploaded.'], 200);
        }
        catch (ValidationException $e) {
            // return actual validation errors
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors()
            ], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                //'message' => 'Something went wrong, please try again later.'
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getPreReleaseDocs($requestId)
    {
        try{
            $requests = MilestonePreReleaseRequest::with('docs')->find($requestId);
            $docs = $requests->docs;
            return response()->json(['data' => $docs], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }


    //Final Pre-release Investor vote Release Funds
    public function investorReview(Request $request, $id)
    {
        try{
            $validated = $request->validate([
                'action' => 'required|in:approve,reject,audit',
                'reason' => 'nullable|in:incomplete_docs,wrong_quotations,incorrect_vendor,suspicious_evidence,not_aligned,pm_audit_required|required_if:action,reject',
            ]);

            $investor = Auth::user();
            $req = MilestonePreReleaseRequest::with('milestone')->find($id);
            $window = $req->votingWindow;

//            if (!$window || $window->status !== 'open') {
//                return response()->json(['message' => 'Voting window is closed'], 403);
//            }

            if($req->milestone->fund_released || $req->milestone->fund_released_75){
                return response()->json(['message' => 'Fund already released.'], 400);
            }
            if ($req->vote !== null) {
                return response()->json([ 'message' => 'You have already voted on this pre-release request.'] , 409);
            }

            if ($validated['action'] === 'approve') {
                $req->status = 'approved'; $req->save();

                // Notify BO
                $text = 'Milestone '. $req->milestone->title. ' has been approved by '.$investor->fname.' '.$investor->lname ;
                $this->notification->create(
                    $req->milestone->listing->user_id, null, $text, 'milestones', 'milestone'
                );
            }
            elseif ($validated['action'] === 'reject'){
                $req->status = 'rejected'; $req->vote = null; $req->increment('reject_count', 1);
                $this->notify($req->milestone->listing->owner, 'pr_rejected_by_one', $req->milestone);

                // Auto escalation after 2 rejections by same investor
                if ($req->reject_count >= 2) {
                    $req->status = 'pm_audit'; //'escalated';
                    $req->milestone->status = 'in_pr_audit';

                    //Generate PM list & show to investors for PM vote
                    $candidate_ids = $this->candidates->get($req->milestone);
                    $audit = PMAudit::create([
                        'type' => 'pre_release',
                        'pr_request_id' => $req->id,
                        'milestone_id' => $req->milestone->id,
                        'candidate_pm_ids' => $candidate_ids,
                    ]);
                    $this->notify($req->milestone->listing->owner, 'pr_admin_escalation', $req->milestone);
                    $req->save();
                }
            }
            elseif($validated['action'] === 'audit'){
                $req->status = 'pm_audit';
            }

            $req->vote = $validated['action'];
            $weights = $req->milestone->investor_weights()->pluck('total', 'investor_id');
            $weight = $weights[$investor->id] ?? 0;
            $req->weight = $weight;

            $req->save(); $req->refresh(); $req->milestone->refresh();

            $totalInvestment = $req->milestone->funding_collected; // or $weights->sum();
            $pr_requests = MilestonePreReleaseRequest::where('milestone_id', $req->milestone->id)
                ->whereNotNull('vote')->get();
            $participatedWeight = $pr_requests->sum('weight');

            $quorumReached = $participatedWeight >= ($totalInvestment * 0.51); //51%

            // evaluate votes after each vote if 60% quorum reached or all investors voted
            if($quorumReached && !$req->milestone->fund_released_75 && $req->milestone->status != 'in_pr_audit'){
                $winner = $this->votingService->result( 'pre_release', $req);

                //$req->vote->update([ 'status' => 'closed', 'ends_at' => now() ]);
                $this->prResultActions($winner, $req);
            }

            return response()->json(['msg' => 'Pre-release '. $validated['action'] .'ed.'], 200);
        }
        catch (ValidationException $e) {
            // return actual validation errors
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors()
            ], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }

    protected function prResultActions($winner, $req)
    {
        //$req == Pr Request
        $milestone = $req->milestone;

        // threshold: 51%, Release 75% funds
        if($winner == 'approve'){

            $fundReleaseService = new MilestoneFundReleaseService($this->Client);

            $result = $fundReleaseService->release($milestone, 75);

            if (!$result->success) {
                return response()->json(['message' => $result->message], 422);
            }

            // if $result == true
            $daysToExec = $req->milestone->no_o_days;
            $req->milestone->fund_released_75 = true;
            $req->milestone->status = 'in_progress';
            $req->milestone->mid_milestone_started = true;

            $old = Carbon::parse($req->milestone->exec_deadline_date);
            $req->milestone->exec_deadline_date = now()->addDays($daysToExec)->format('Y-m-d');

            $req->milestone->save();
            $req->save();
            $this->notify($req->milestone->listing->owner, 'pr_approved', $req->milestone);
        }
        else if ($winner == 'reject'){
            //$this->initiateRefund($req->milestone);
            $req->status = 'rejected'; //$req->reject_count += 1;
            $req->milestone->status = 'pr_rejected';
            $req->save(); $req->milestone->save();
            $this->notify($req->milestone->listing->owner, 'pr_rejected', $req->milestone);
        }
        else if ($winner == 'audit') {
            $req->status = 'pm_audit';
            $req->milestone->status = 'in_pr_audit';
            $req->save(); $req->milestone->save();

            //Generate PM list & show to investors for PM vote
            $candidate_ids = $this->candidates->get($req->milestone);
            $audit = PMAudit::create([
                'type' => 'pre_release',
                'pr_request_id' => $req->id,
                'milestone_id' => $req->milestone->id,
                'candidate_pm_ids' => $candidate_ids,
            ]);
            $this->notify($req->milestone->listing->owner, 'pr_pm_audit', $req->milestone);
        }

    }


    // R M E P  Starts
    public function rmeps($milestoneId)
    {
        try{
            $user_id = Auth::id();
            $rmeps = MilestoneRmep::with([
                'milestone',
                'votes.voter',
            ])->where('milestone_id',$milestoneId)
                ->latest()->get();

            foreach($rmeps as $rmep){
                $rmep->voted = $rmep->votes->contains('investor_id', $user_id);
            }

            return response()->json([
                'data'    => $rmeps
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }

    public function rmep_store(Request $request)
    {
        try{
            $validated = $request->validate([
                'milestone_id'    => ['required', 'integer', 'exists:milestones,id'],
                'milestone_name'  => ['required', 'string'],
                'description'     => ['required', 'string'],
                'voting_deadline' => ['required', 'date'],
                //'rmep_document' => ['required', 'file','mimes:pdf,doc,docx'],
            ]);
            $fileUpload = new FileUploadService();

            $eligible_voters = BusinessBids::where('ms_id', $validated['milestone_id'])->pluck('investor_id')->unique()->toArray();
            $rmep = MilestoneRmep::create([
                'milestone_id'    => $validated['milestone_id'],
                'milestone_name'  => $validated['milestone_name'],
                'description'     => $validated['description'],
                'voting_deadline' => $validated['voting_deadline'],
                'eligible_voters' => $eligible_voters,
                //'eligible_voters' => json_encode($eligible_voters),
            ]);

            if($request->hasFile('rmep_document')){
                $path = 'files/milestoneRmeps/' . $rmep->id;
                $rmep->rmep_document = $fileUpload->saveFile($request->file('rmep_document'), $path);
                $rmep->save();
            }

            $rmep->milestone->status = 'rmep_submitted';
            $rmep->milestone->save();

            $investors = $rmep->milestone->pending_investors;
            $this->notify($investors, 'rmep_submitted', $rmep->milestone);


            return response()->json([
                'message' => 'RMEP created successfully.',
                'data'    => $rmep
            ], 200);
        }
        catch (ValidationException $e) {
            // return actual validation errors
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors()
            ], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }

    //RMEP Votes
    public function vote_store(Request $request)
    {
        try{
            $validated = $request->validate([
                'rmep_id'     => ['required', 'integer', 'exists:milestone_rmeps,id'],
                'vote'        => ['required', 'in:approve,reject'],
                'comment'     => ['nullable', 'string'],
            ]);
            $investor = Auth::user();
            $rmep = MilestoneRmep::with(['milestone','votes'])->find($validated['rmep_id']);

            if(!$rmep){
                return response()->json(['message' => 'Milestone RMEP not found.'], 404);
            }
            $window = $rmep->votingWindow;

//            if (!$window || $window->status !== 'open') {
//                return response()->json(['message' => 'Voting window is closed'], 400);
//            }



            // verify investor
            $isInvestor = $rmep->milestone->pending_bids()->where('investor_id', $investor->id)->exists();
            $isVoted = $rmep->votes()->where('investor_id', $investor->id)->exists();

            if (!$isInvestor || $isVoted) {
                return response()->json(['message' => 'Invalid investor or already voted'], 422);
            }

            $weights = $rmep->milestone->investor_weights()->pluck('total', 'investor_id');
            $weight = $weights[$investor->id] ?? 0;

            RmepVotes::updateOrCreate(
                ['rmep_id' => $validated['rmep_id'], 'investor_id' => $investor->id],
                ['vote' => $validated['vote'], 'weight' => $weight, 'comment' => $validated['comment'] ?? null]
            );


            $rmep->save(); $rmep->refresh();

            $rmep->load('votes');

            $totalInvestment = $rmep->milestone->pending_collected; // or $weights->sum();
            $participatedWeight = $rmep->votes->sum('weight');
            $quorumReached = $participatedWeight >= ($totalInvestment * 0.51); //51%

            // evaluate votes after each vote if 51% quorum reached
            if($quorumReached && $rmep->status == 'active'){
                $winner = $this->votingService->result( 'rmep', $rmep);

                //$req->vote->update([ 'status' => 'closed', 'ends_at' => now() ]);
                $this->rmepResultActions($winner, $rmep);
            }

            return response()->json([
                'message' => 'Vote submitted successfully.'
            ], 200);
        }
        catch (ValidationException $e) {
            // return actual validation errors
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors()
            ], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }

    protected function rmepResultActions($winner, $rmep)
    {
        if ($winner == 'approve') {
            $rmep->status = 'approved';
            // extend deadline ...
            $deadline = Carbon::parse($rmep->milestone->deadline_date);
            $rmep->milestone->deadline_date   = $deadline->addDays(7);
            $rmep->milestone->status = 'to_do';
            $rmep->milestone->continuation_approved = true;

            $rmep->milestone->save();
            $this->notify($rmep->milestone->listing->owner, 'rmep_approved', $rmep->milestone);
        }
        elseif ($winner == 'reject') {
            $rmep->status = 'rejected';
            $rmep->milestone->status = 'continuation_triggered';
            $rmep->milestone->save();

//            if($rmep->reject_count >= 2){
//                $rmep->status = 'admin';
//                $this->notify($mid->milestone->listing->owner, 'pm_audit', $mid);
//            }
//            else{
//                $this->notify($rmep->milestone->listing->owner, 'rmep_rejected', $rmep->milestone);
//            }
            $this->notify($rmep->milestone->listing->owner, 'rmep_rejected', $rmep->milestone);
        }
        $rmep->save();
    }

}
