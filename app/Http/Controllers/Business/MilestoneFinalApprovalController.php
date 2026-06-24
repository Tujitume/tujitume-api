<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Milestones\FinalApprovalDocuments;
use App\Models\Milestones\FinalApprovalVote;
use App\Models\Milestones\MilestoneExecutionDocuments;
use App\Models\Misc\Setting;
use App\Models\Shared\PMAudit;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\Business\Milestone\MilestoneNotificationService;
use App\Service\Business\Milestone\MilestonePMCandidates;
use App\Service\File\FileUploadService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use App\Service\Misc\GlobalVotingService;
use App\Service\Notification\EmailService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

class MilestoneFinalApprovalController extends Controller
{
    protected LiprW2W $liprW2W;
    protected $votingService;
    protected $tujitume_lipr_wallet;
    public function __construct(EmailService $emailService, StripeClient $client)
    {
        parent::__construct();

        $this->Client = $client;

        $this->balance = new BalanceService();
        $this->liprW2W = new LiprW2W();
        $this->convert = new CurrencyConverter();
        $this->usdToKes = $this->convert->UsdToKes();
        $this->candidates = new MilestonePMCandidates();
        $this->votingService = new GlobalVotingService();
        $this->tujitume_lipr_wallet = Setting::where('key', 'platform_lipr_wallet')->first()?->value ?? null;
    }


    public function final_proof_get($milestoneId)
    {
        try{
            $user_id = Auth::id();
            $exec = MilestoneExecutionDocuments::with(['documents','votes'])
                ->where('milestone_id',$milestoneId)->latest()->first();


            $exec->voted = $exec->votes->contains('investor_id', $user_id);

            return response()->json([
                'data'    => $exec
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }


    public function final_proof_store(Request $request)
    {
        $uploadedFiles = [];
        try{
            $validated = $request->validate([
                'milestone_id' => ['required', 'integer', 'exists:milestones,id'],

                // Photos (multiple allowed)
                'photos'      => ['nullable', 'array'],
                'photos.*'    => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], // 5MB each
                // Video (single)
                'video'       => ['nullable', 'file', 'mimes:mp4,mov,avi,webm', 'max:51200'], // 50MB
                // Receipt document
                'receipt'     => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
                // Installation document
                'installation_doc' => ['nullable', 'file', 'mimes:pdf,doc,docx,zip', 'max:10240'],
                // Reports
                'reports'     => ['nullable', 'file', 'mimes:pdf,doc,docx,zip', 'max:10240'],
                'delay_reason'     => ['nullable', 'string', 'max:500'],
            ]);

            $fileUpload = new FileUploadService();
            //$milestone = Milestones::find($validated['milestone_id']);
            $exec = MilestoneExecutionDocuments::create([
                'milestone_id'    => $validated['milestone_id'],
                'delay_reason'    => $validated['delay_reason'] ?? null,
            ]);

            //Proof upload
            $uploadTypes = [
                'photos' => 'photo',
                'video'  => 'video',
                'receipt' => 'receipt',
                'installation_doc' => 'installation_doc',
                'reports' => 'reports',
            ];
            $path = 'files/milestoneFinal/' . $exec->id;

            // File type check for all
            foreach($uploadTypes as $input =>$type) {
//                if (!$request->hasFile($input)) {
//                    continue;
//                }

                $files = $request->file($input);
                $files = is_array($files) ? $files : [$files];
                foreach($files as $file){
                    if (!$file instanceof UploadedFile) {
                        return response()->json([
                            'message' => "Invalid file uploaded for $type."
                        ], 422);
                    }
                }
            }


            // upload files
            foreach($uploadTypes as $input =>$type){
                if(!$request->hasFile($input)){
                    continue;
                }
                $files = $request->file($input);
                $files = is_array($files) ? $files : [$files];
                foreach($files as $file){
                    $filePath = $fileUpload->saveFile($file, $path);
                    FinalApprovalDocuments::create([
                        'milestone_execution_id' => $exec->id,
                        'file_path' => $filePath ?? 'null.pdf',
                        'type' => $type,
                    ]);
                    $uploadedFiles[] = $filePath;
                }
            }
            //Proof upload

            $exec->milestone->status = 'execution_submitted';
            $exec->milestone->save();
            $investors = $exec->milestone->investors;
            $this->notify($investors, 'final_approval_submitted', $exec->milestone);

            return response()->json([
                'message' => 'Execution proof submitted.', 'data'    => $exec
            ], 200);
        }
        catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors'  => $e->errors()], 422);
        }
        catch (\Exception $e) {

            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);

            if($uploadedFiles){
                foreach ($uploadedFiles as $file) {
                    if (file_exists($file)) {
                        unlink($file);
                    }
                }
            }

            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }


    //FINAL Votes
    public function final_approval_vote(Request $request)
    {
        try{
            $validated = $request->validate([
                'execution_id'     => ['required', 'integer', 'exists:milestone_execution_documents,id'],
                'action'        => ['required', 'in:approve,reject,audit'],
                'reason'     => ['nullable', 'string'],
            ]);

            $investorId = Auth::id();
            $execution = MilestoneExecutionDocuments::with('milestone')->find($validated['execution_id']);

            if(!$execution){
                return response()->json(['message' => 'Final execution not found.'], 404);
            }

            $window = $execution->votingWindow;
            if (!$window || $window->status !== 'open') {
                return response()->json(['message' => 'Voting window is closed'], 422);
            }

            // verify investor
            $isInvestor = $execution->milestone->accepted_bids()->where('investor_id', $investorId)->exists();
            if (!$isInvestor) {
                return response()->json(['message' => 'Only investors can vote'], 422);
            }

            if ($execution->votes()->where('investor_id', $investorId)->exists()) {
                return response()->json(['message' => 'Already voted'], 409);
            }

            if ($execution->status === 'in_audit') {
                return response()->json(['message' => 'Final approval under audit'], 422);
            }

            // upsert vote
            $weights = $execution->milestone->investor_weights()->pluck('total', 'investor_id');
            $weight = $weights[$investorId] ?? 0;

            FinalApprovalVote::updateOrCreate(
                ['final_approval_id' => $execution->id, 'investor_id' => $investorId],
                ['vote' => $validated['action'], 'weight' => $weight, 'reason' => $validated['reason'] ?? null]
            );
            $execution->save(); $execution->refresh();
            $execution->load('votes');

            $totalInvestment = $execution->milestone->funding_collected; // or $weights->sum();
            $participatedWeight = $execution->votes->sum('weight');
            $quorumReached = $participatedWeight >= ($totalInvestment * 0.51); //51%

            // evaluate votes after each vote if 51% quorum reached or all investors voted
            if($quorumReached){
                $winner = $this->votingService->result( 'final_approval', $execution);

                //$req->vote->update([ 'status' => 'closed', 'ends_at' => now() ]);
                $this->finalResultActions($winner, $execution);
            }

            return response()->json([
                'message' => 'Vote submitted successfully.'
            ], 200);
        }
        catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors'  => $e->errors()], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }

    protected function finalResultActions($winner, $execution)
    {
        $milestone = $execution->milestone;

        if ($winner == 'approve') {
            if($milestone->fund_released){
                // release funds to BO...

                $execution->status = 'approved'; $execution->save();
                $milestone->status = 'done'; $milestone->save();
                $this->notify($milestone->listing->owner, 'final_approved', $milestone);
            }
        }
        else if ($winner == 'reject') {
            $execution->status = 'rejected'; $execution->save();
            $execution->milestone->status = 'in_progress'; $execution->milestone->save();
            $this->notify($execution->milestone->listing->owner, 'final_rejected', $execution->milestone);

        }
        else if ($winner == 'audit') {
            $execution->status = 'pm_audit'; $execution->save();
            $candidate_ids = $this->candidates->get($execution->milestone);

            $audit = PMAudit::create([
                'type' => 'final_approval',
                'milestone_id' => $execution->milestone->id,
                'candidate_pm_ids' => $candidate_ids,
            ]);
            $this->notify($execution->milestone->listing->owner, 'final_pm_audit', $execution->milestone);
            // close voting window
        }
    }

    //  H  E  L  P  E  R  S
    public function notify($recipient, $type, $milestone, $info = null)
    {
        $notifyService = new MilestoneNotificationService();
        $notifyService->notify($recipient, $type, $milestone, $info);

    }

}
