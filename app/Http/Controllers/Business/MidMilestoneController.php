<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Milestones\MidMilestone;
use App\Models\Milestones\MidMilestoneDocuments;
use App\Models\Milestones\MidMilestoneVote;
use App\Models\Milestones\Milestones;
use App\Models\Misc\Setting;
use App\Models\Shared\PMAudit;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\Business\Milestone\MilestoneFundReleaseService;
use App\Service\Business\Milestone\MilestoneNotificationService;
use App\Service\Business\Milestone\MilestonePMCandidates;
use App\Service\File\FileUploadService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use App\Service\Misc\GlobalVotingService;
use App\Service\Notification\EmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

class MidMilestoneController extends Controller
{
    // PM bonus factor (if you want PM votes to have extra weight)
    protected LiprW2W $liprW2W;
    protected $votingService;
    protected $tujitume_lipr_wallet;
    public function __construct(EmailService $emailService, StripeClient $client)
    {
        parent::__construct();

        $this->Client = $client;
        $this->public = env('LIPA_PUBLIC_KEY');
        $this->secret = env('LIPA_SECRET');
        $this->balance = new BalanceService();
        $this->liprW2W = new LiprW2W();
        $this->convert = new CurrencyConverter();
        $this->usdToKes = $this->convert->UsdToKes();
        $this->candidates = new MilestonePMCandidates();
        $this->votingService = new GlobalVotingService();
        $this->tujitume_lipr_wallet = Setting::where('key', 'platform_lipr_wallet')->first()?->value ?? null;
    }


    public function index($milestone_id){
        try{
            $user_id = Auth::id();

            $mid_milestone = MidMilestone::with([
                'votes.investor',
                'documents'
            ])
                ->where('milestone_id', $milestone_id)->latest()->first();

            if(!$mid_milestone){
                return response()->json(['message' => 'Mid milestone not found.'], 404);
            }

            $mid_milestone->voted = $mid_milestone->votes->contains('investor_id', $user_id);

            // Return as JSON or pass to view
            return response()->json([
                'data' => $mid_milestone
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // BO SUBMIT MID-MILESTONE
    public function store(Request $req)
    {
        $uploadedFiles = [];
        try {
            $fileUpload = new FileUploadService();
            $req->validate([
                'milestone_id' => 'required|exists:milestones,id',
                'progress_statement' => 'required|string|max:5000',
                'timeline_forecast' => 'required|string|max:2000',
                'challenges' => 'nullable|string|max:2000',
                // Proof uploads (at least one should exist – handled below)
                'photos'      => 'required|file|mimes:jpg,jpeg,png,webp|max:10240', // 10MB
                'video'       => 'nullable|file|mimes:mp4,mov,avi|max:51200',       // 50MB
                'receipt'     => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
                'work_logs'   => 'required|file|mimes:pdf,doc,docx|max:10240',
                'screenshots' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            ]);

            $user = Auth::user();
            $milestone = Milestones::find($req->milestone_id);

            //ensure owner
            if ($milestone->listing->user_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized: only milestone owner can submit mid update'], 403);
            }

            if(!$milestone->fund_released_75){
                return response()->json(['message' => 'Milestone pre-release is not verified yet.'], 422);
            }

            DB::beginTransaction();

            $mid = MidMilestone::create([
                'milestone_id' => $milestone->id,
                'created_by' => $user->id,
                'progress_statement' => $req->progress_statement,
                'progress_percent' => $milestone->progress_percentage,
                'challenges' => $req->challenges,
                'timeline_forecast' => $req->timeline_forecast,
                'status' => 'pending_investor_review',
            ]);

            //Proof upload
            $uploadTypes = [
                'photos' => 'photo',
                'video'  => 'video',
                'receipt' => 'receipt',
                'work_logs' => 'work_log',
                'supplier_doc' => 'supplier_doc',
                'screenshots' => 'screenshot',
            ];
            $path = 'files/midMilestone/' . $mid->id;

            foreach($uploadTypes as $input =>$type){
                if(!$req->hasFile($input)){
                    continue;
                }
                $files = $req->file($input);
                $files = is_array($files) ? $files : [$files];
                foreach($files as $file){
                    $filePath = $fileUpload->saveFile($file, $path);
                    MidMilestoneDocuments::create([
                        'mid_milestone_id' => $mid->id,
                        'file_path' => $filePath ?? 'null.pdf',
                        'type' => $type,
                    ]);
                    $uploadedFiles[] = $filePath;
                }
            }

            $milestone->status = 'mid_milestone_submitted';
            $milestone->save();

            // notify investors with email & in-app notification
            $this->notify($mid->milestone->listing->owner, 'mid_milestone_submitted', $mid);
            DB::commit();
            return response()->json(['message' => 'Mid-milestone submitted', 'data' => $mid], 200);

        } catch (\Exception $e) {
            DB::rollback();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);

            if($uploadedFiles){
                foreach ($uploadedFiles as $file) {
                    if(file_exists($file)){
                        unlink($file);
                    }

                }
            }
            return response()->json([
                //'message' => 'Something went wrong, please try again later.'
                'message' => $e->getMessage()
            ], 500);
        }
    }


    // INVESTOR VOTE (weighted)
    public function vote_store(Request $req)
    {
        try{
            $req->validate([
                'mid_milestone_id' => 'required',
                'vote' => 'required|in:approve,reject,audit',
                'reason' => 'nullable|string|max:2000',
            ]);

            $user = Auth::user();
            $mid = MidMilestone::with('milestone')->find($req->mid_milestone_id);

            if(!$mid){
                return response()->json(['message' => 'Mid milestone not found.'], 404);
            }

            $window = $mid->votingWindow;

//            if (!$window || $window->status !== 'open') {
//                return response()->json(['message' => 'Voting window is closed'], 400);
//            }

            if($mid->milestone->fund_released){
                return response()->json(['message' => 'Fund already released.'], 400);
            }

            // verify investor
            $isInvestor = $mid->milestone->accepted_bids()->where('investor_id', $user->id)->exists();
            if (!$isInvestor) {
                return response()->json(['message' => 'Only investors can vote'], 403);
            }

            // upsert vote
            $weights = $mid->milestone->investor_weights()->pluck('total', 'investor_id');
            $weight = $weights[$user->id] ?? 0;

            MidMilestoneVote::updateOrCreate(
                ['mid_milestone_id' => $mid->id, 'investor_id' => $user->id],
                ['vote' => $req->vote, 'weight' => $weight, 'reason' => $req->reason ?? null]
            );

            $mid->save(); $mid->refresh();
            $mid->load('votes');

            $totalInvestment = $mid->milestone->funding_collected; // or $weights->sum();
            $participatedWeight = $mid->votes->sum('weight');
            $quorumReached = $participatedWeight >= ($totalInvestment * 0.51); //51%

            // evaluate votes after each vote if 51% quorum reached or all investors voted
            if($quorumReached && !$mid->milestone->fund_released){
                $winner = $this->votingService->result( 'mid_milestone', $mid);

                //$req->vote->update([ 'status' => 'closed', 'ends_at' => now() ]);
                $this->midResultActions($winner, $mid);
            }


            return response()->json(['message' => 'Vote recorded'], 200);
        }
        catch (\Exception $e) {
            DB::rollback();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json([
            'message' => 'Something went wrong, please try again later.'
                //'message' => $e->getMessage(),
                //'line' => $e->getLine(),
            ], 500);
        }
    }

    // Evaluate votes (weighted by accepted_bids.amount)
    protected function midResultActions($winner, $mid)
    {
        $votes = MidMilestoneVote::where('mid_milestone_id', $mid->id)->get();
        if ($votes->isEmpty()) {
            return;
        }

        // threshold: 51%, 25% fund release
        if ($winner == 'approve') {
            if($mid->milestone->fund_released_75){

                $mid->status = 'approved'; $mid->save();

                $fundReleaseService = new MilestoneFundReleaseService($this->Client);

                $result = $fundReleaseService->release($mid->milestone, 25);

                if (!$result->success) {
                    return response()->json(['message' => $result->message], 422);
                }

                $mid->milestone->fund_released = true;
                $mid->milestone->final_approval_started = true;
                $mid->milestone->status = 'in_progress';
                $mid->milestone->save();
                $this->notify($mid->milestone->listing->owner, 'approved', $mid);

            }
        }
        else if ($winner == 'reject') {
            $mid->status = 'rejected';
            $mid->increment('attempt_count',1);

            if($mid->attemp_count >= 2){
                $mid->status = 'pm_audit';
                $this->notify($mid->milestone->listing->owner, 'pm_audit', $mid);
            }
            else{
                $this->notify($mid->milestone->listing->owner, 'rejected', $mid);
            }
            $mid->save();
        }
        else if ($winner == 'audit') {
            $mid->status = 'pm_audit';
            $mid->save();
            //Generate PM list & show to investors for PM vote
            $candidate_ids = $this->candidates->get($mid->milestone);

            $audit = PMAudit::create([
                'type' => 'mid_milestone',
                'mid_milestone_id' => $mid->id,
                'milestone_id' => $mid->milestone->id,
                'candidate_pm_ids' => $candidate_ids,
            ]);
            $this->notify($mid->milestone->listing->owner, 'pm_audit', $mid);

            //voting should stop
        }

    }

    // H   E   L   P   E   R   S

    // N  O  T  I  F  Y   helper (Notify Owner + Investor)
    public function notify($recipient, $type, $midMilestone)
    {
        $milestone = $midMilestone->milestone;
        $notifyService = new MilestoneNotificationService();

        if($type == 'rejected'){
            $notifyService->notify($recipient, $type, $midMilestone);
        }
        else{
            $notifyService->notify($recipient, $type, $milestone);
        }
    }

}
