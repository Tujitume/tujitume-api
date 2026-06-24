<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Milestones\FinalPMVote;
use App\Models\Milestones\MidPMVote;
use App\Models\Milestones\MilestonePreReleaseRequest;
use App\Models\Milestones\PrPMVote;
use App\Models\Shared\PMAudit;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\Business\Milestone\MilestoneFundReleaseService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\EmailService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

class MilestonePMController extends Controller
{

    protected LiprW2W $liprW2W;
    protected EmailService $emailService;
    //protected $Client;
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
    }

    public function audits($type){
        try{
            $user_id = Auth::id();

            if($type=='mid_milestone'){
                $with = 'midMilestone.milestone.listing';
            }
            elseif($type=='pre_release'){
                $with = [
                    'preReleaseRequest.milestone.listing',
                    'preReleaseRequest.docs',
                    ];

            }
            elseif($type=='final_approval'){
                $with = [
                    'milestone.listing',
                    //'thisMilestone.listing',
                ];
            }

            $audits = PMAudit::with($with)->where([
                'assigned_pm_id' => $user_id,
                'type' => $type,
            ])->latest()->get();

            return response()->json([
                'audits' => $audits,
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function audit_store(Request $request)
    {
        try{
            $validated = $request->validate([
                'type'              => ['required', 'string', 'in:mid_milestone,pre_release,final_approval'],
                'mid_milestone_id'  => ['required_if:type,mid_milestone', 'integer', 'exists:mid_milestones,id'],
                'pr_request_id'     => ['required_if:type,pre_release', 'integer', 'exists:milestone_pre_release_requests,id'],
                'milestone_id'      => ['required_if:type,final_approval', 'integer', 'exists:milestones,id'],
                'candidate_pm_ids'  => ['required','array'],
            ]);

            $map = [
                'mid_milestone' => 'mid_milestone_id',
                'pre_release' => 'pr_request_id',
                'final_approval' => 'milestone_id',
            ];

            $milestone_type_id = $map[$validated['type']];


            $data = [
                $milestone_type_id => $validated[$milestone_type_id],
                'candidate_pm_ids' => $validated['candidate_pm_ids'],
                'type'             => $validated['type'],
            ];

            if ($validated['type'] === 'pre_release') {
                $req = MilestonePreReleaseRequest::find($validated['pr_request_id']);
                $data['milestone_id'] = $req->milestone_id;
            }

            $audit = PMAudit::create($data);

            //Notify Investors

            return response()->json([
                'message' => 'Audit record created successfully.',
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
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // For PM to accept/reject
    public function audit_request(Request $request)
    {
        try{
            $validated = $request->validate([
                'audit_id'   => 'required|integer|exists:p_m_audits,id',
                'action'        => 'required|string|in:accept,reject',
            ]);

            $audit = PMAudit::find($validated['audit_id']);
            if (!$audit) {
                return response()->json(['message' => 'PM audit record not found.'], 404);
            }

            $audit->status = $validated['action'] .'ed';
            $audit->save();

            return response()->json([
                'message' => 'Request '. $validated['action'] .'ed.',
            ], 200);
        }
        catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.', 'errors'  => $e->errors()
            ], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    // PM Audit For Mid Milestone
    public function mid_pm_candidates($mid_milestone_id){
        try{
            $audit = PMAudit::where([
                'mid_milestone_id' => $mid_milestone_id,
                'type' => 'mid_milestone'
            ])->latest()->first();

            if (!$audit) {
                return response()->json(['message' => 'PM audit record not found.'], 404);
            }
            $voted = $audit->midVotes->contains('investor_id', Auth::id());

            $candidates = $audit->candidates;
            return response()->json([
                'candidates' => $candidates,
                'audit_id' => $audit->id,
                'voted' => $voted
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function mid_pm_vote(Request $request)
    {
        try{
            $validated = $request->validate([
                'audit_id'   => 'required|integer|exists:p_m_audits,id',
                'voted_pm_id'        => 'required|integer|exists:users,id',
            ]);
            $investorId = auth()->id();
            // prevent double voting
            $exists = MidPMVote::where('pm_audit_id', $validated['audit_id'])
                ->where('investor_id', $investorId)->exists();
            if ($exists) {
                return response()->json(['message' => 'You already submitted your vote.'], 409);
            }
            $pmAudit = PMAudit::find($validated['audit_id']);
            $investor_share = $pmAudit->midMilestone->milestone->accepted_bids()
                ->where('investor_id', $investorId)->sum('representation');

            // store the vote
            $vote = MidPMVote::create([
                'pm_audit_id'  => $validated['audit_id'],
                'investor_id'       => $investorId,
                'investor_share'       => $investor_share,
                'voted_pm_id' => $validated['voted_pm_id'],
            ]);

            $pmAudit->load('midVotes');
            $uniqueInvestors = $pmAudit->midMilestone->milestone->investors()->distinct('users.id')->count('users.id');
            $total_votes = $pmAudit->midVotes->count();

            //return '//'.$total_votes .'>='. $uniqueInvestors;

            // evaluate votes after each vote - will release / escalate / reject as per rules
            if($total_votes >= $uniqueInvestors && !$pmAudit->assigned_pm_id){
                $this->evaluateVotes($pmAudit);
            }

            return response()->json([
                'message' => 'Vote submitted successfully.',
                'data' => $vote
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
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    protected function evaluateVotes($pmAudit)
    {
        $votes = $pmAudit->midVotes;
        if ($votes->isEmpty()) {
            return;
        }
        $voteCounts = $pmAudit->midVotes->pluck('voted_pm_id')->countBy();
        $maxVotes = $voteCounts->max();
        $tied_pm_ids = $voteCounts->filter(fn($count) => $count === $maxVotes)->keys()->all();

        if(count($tied_pm_ids) > 1){
            $tiedVotes = $pmAudit->midVotes->whereIn('voted_pm_id', $tied_pm_ids);
            $weightedCounts = $tiedVotes->groupBy('voted_pm_id')->map(function($votesForPM) {
                return $votesForPM->sum(fn($vote) => $vote->investor_share ?? 0);
            });
            // Pick PM ID with highest weighted investment
            $selected_pm_id = $weightedCounts->sortDesc()->keys()->first();
        } else {
            // No tie
            $selected_pm_id = $tied_pm_ids[0];
        }

        // Case: Tie
        $pmAudit->assigned_pm_id = $selected_pm_id;
        $pmAudit->save();

        $selected_pm = User::find($selected_pm_id);
        // Post selection process
        $this->notify($selected_pm, 'mid_pm_assigned', $pmAudit);
        // Send request to pm
    }


    // PM Audit For P R E  R E L E A S E
    public function pr_pm_candidates($milestone_id){
        try{

            $audit = PMAudit::with('prVotes')->where([
                'milestone_id' => $milestone_id,
                'type' => 'pre_release'
            ])->latest()->first();

            if (!$audit) {
                return response()->json(['message' => 'PM audit record not found.'], 404);
            }

            $candidates = $audit->candidates;
            foreach ($candidates as $candidate) {
                $candidate->vote_count = $audit->prVotes->where('voted_pm_id', $candidate->id)->count();
            }
            $voted = $audit->prVotes->contains('investor_id', Auth::id());

            return response()->json([
                'candidates' => $candidates,
                'audit_id' => $audit->id,
                'voted' => $voted,
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function pr_pm_vote(Request $request)
    {
        try{
            $validated = $request->validate([
                'audit_id'   => 'required|integer|exists:p_m_audits,id',
                'voted_pm_id'        => 'required|integer|exists:users,id',
            ]);
            $investorId = auth()->id();
            // prevent double voting
            $exists = PrPMVote::where('pm_audit_id', $validated['audit_id'])
                ->where('investor_id', $investorId)->exists();
            if ($exists) {
                return response()->json(['message' => 'You already submitted your vote.'], 409);
            }
            $pmAudit = PMAudit::with('prVotes')->find($validated['audit_id']);
            $investor_share = $pmAudit->preReleaseRequest->milestone->accepted_bids()
                ->where('investor_id', $investorId)->sum('representation');

            // store the vote
            $vote = PrPMVote::create([
                'pm_audit_id'  => $validated['audit_id'],
                'investor_id'       => $investorId,
                'investor_share'       => $investor_share,
                'voted_pm_id' => $validated['voted_pm_id'],
            ]);

            // reload relation
            //$pmAudit->load('prVotes');

            $uniqueInvestors = $pmAudit->preReleaseRequest->milestone->investors()->distinct('users.id')->count('users.id');
            $total_votes = PrPMVote::where('pm_audit_id', $validated['audit_id'])->count();
            //$total_votes = $pmAudit->prVotes->count();
            //return '//'.$total_votes .'>='. $uniqueInvestors;

            // evaluate votes after each vote - will release / escalate / reject as per rules
            if($total_votes >= $uniqueInvestors ){ //&& !$pmAudit->assigned_pm_id
                $this->PrEvaluateVotes($pmAudit);
            }

            return response()->json([
                'message' => 'Vote submitted successfully.',
                'data' => $vote
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
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    protected function PrEvaluateVotes($pmAudit)
    {
        $votes = $pmAudit->prVotes;
        if ($votes->isEmpty()) {
            return;
        }
        $voteCounts = $pmAudit->prVotes->pluck('voted_pm_id')->countBy();
        $maxVotes = $voteCounts->max();
        $tied_pm_ids = $voteCounts->filter(fn($count) => $count === $maxVotes)->keys()->all();

        if(count($tied_pm_ids) > 1){
            $tiedVotes = $pmAudit->prVotes->whereIn('voted_pm_id', $tied_pm_ids);
            $weightedCounts = $tiedVotes->groupBy('voted_pm_id')->map(function($votesForPM) {
                return $votesForPM->sum(fn($vote) => $vote->investor_share ?? 0);
            });
            // Pick PM ID with highest weighted investment
            $selected_pm_id = $weightedCounts->sortDesc()->keys()->first();
        } else {
            // No tie
            $selected_pm_id = $tied_pm_ids[0];
        }

        // Case: Tie
        $pmAudit->assigned_pm_id = $selected_pm_id;
        $pmAudit->save();

        $selected_pm = User::find($selected_pm_id);
        // Post selection process
        $this->notify($selected_pm, 'pr_pm_assigned', $pmAudit);
        // Send request to pm
    }

    // PM Audit For P R E  R E L E A S E
    public function final_pm_candidates($milestone_id){
        try{
            $audit = PMAudit::where([
                'milestone_id' => $milestone_id,
                'type' => 'final_approval'
            ])->latest()->first();

            if (!$audit) {
                return response()->json(['message' => 'PM audit record not found.'], 404);
            }
            $voted = $audit->finalVotes->contains('investor_id', Auth::id());

            $candidates = $audit->candidates;
            return response()->json([
                'candidates' => $candidates,
                'audit_id' => $audit->id,
                'voted' => $voted
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function final_pm_vote(Request $request)
    {
        try{
            $validated = $request->validate([
                'audit_id'   => 'required|integer|exists:p_m_audits,id',
                'voted_pm_id'        => 'required|integer|exists:users,id',
            ]);
            $investorId = auth()->id();
            // prevent double voting
            $exists = FinalPMVote::where('pm_audit_id', $validated['audit_id'])
                ->where('investor_id', $investorId)->exists();
            if ($exists) {
                return response()->json(['message' => 'You already submitted your vote.'], 409);
            }
            $pmAudit = PMAudit::with('finalVotes')->find($validated['audit_id']);
            $investor_share = $pmAudit->thisMilestone->accepted_bids()
                ->where('investor_id', $investorId)->sum('representation');

            // store the vote
            $vote = FinalPMVote::create([
                'pm_audit_id'  => $validated['audit_id'],
                'investor_id'       => $investorId,
                'investor_share'       => $investor_share,
                'voted_pm_id' => $validated['voted_pm_id'],
            ]);
            $pmAudit->refresh();

            $uniqueInvestors = $pmAudit->milestone->investors()->distinct('users.id')->count('users.id');
            $total_votes = $pmAudit->finalVotes->count();

            // evaluate votes after each vote - will release / escalate / reject as per rules
            if($total_votes >= $uniqueInvestors && !$pmAudit->assigned_pm_id){
                $this->finalEvaluateVotes($pmAudit);
            }

            return response()->json([
                'message' => 'Vote submitted successfully.',
                'data' => $vote
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
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    protected function finalEvaluateVotes($pmAudit)
    {
        $votes = $pmAudit->finalVotes;
        if ($votes->isEmpty()) {
            return;
        }
        $voteCounts = $pmAudit->finalVotes->pluck('voted_pm_id')->countBy();
        $maxVotes = $voteCounts->max();
        $tied_pm_ids = $voteCounts->filter(fn($count) => $count === $maxVotes)->keys()->all();

        if(count($tied_pm_ids) > 1){
            $tiedVotes = $pmAudit->finalVotes->whereIn('voted_pm_id', $tied_pm_ids);
            $weightedCounts = $tiedVotes->groupBy('voted_pm_id')->map(function($votesForPM) {
                return $votesForPM->sum(fn($vote) => $vote->investor_share ?? 0);
            });
            // Pick PM ID with highest weighted investment
            $selected_pm_id = $weightedCounts->sortDesc()->keys()->first();
        } else {
            // No tie
            $selected_pm_id = $tied_pm_ids[0];
        }

        // Case: Tie
        $pmAudit->assigned_pm_id = $selected_pm_id;
        $pmAudit->save();

        $selected_pm = User::find($selected_pm_id);
        // Post selection process
        $this->notify($selected_pm, 'final_pm_assigned', $pmAudit);
        // Send request to pm
    }
    // Audit  E  N  D  S


    // N O T I F Y
    public function notify($recipient, $type, $pmAudit, $info = null)
    {
        $milestone = $pmAudit->midMilestone?->milestone ?? $pmAudit->milestone ?? $pmAudit->preReleaseRequest?->milestone;

        if(!$milestone){
            return response()->json(['message' => 'Milestone not found.'], 404);
        }

        if ($type == 'pr_pm_assigned') {
            $owner = $milestone->listing->owner;

            //Manager NotificationService
            $text1 = 'You have been assigned as Project Manager for Milestone ' . $milestone->title;
            $this->notification->create(
                $recipient->id, null, $text1, 'milestones', 'milestone'
            );

            //Owner NotificationService
            $text = 'Project manager '.$recipient->fname.' '.$recipient->lanme.' has been assigned for  Your Milestone ' . $milestone->title . ' to progress with the pre-release audit.';
            $this->notification->create(
                $owner->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l to PM and Owner
            $subject = 'Project Manager assigned for Pre-Release Audit';
            $mail_to = [$recipient->email, $owner->email];
            $data = [
                'boName' => $milestone->listing->name,
                'milestoneName' => $milestone->title,
                'pmName' => $recipient->fname . ' ' . $recipient->lname,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.pre_release.pm_assigned', $data, $mail_to);
        }
        if ($type == 'mid_pm_assigned') {
            $owner = $milestone->listing->owner;

            //Manager NotificationService
            $text1 = 'You have been assigned as Project Manager for Milestone ' . $milestone->title;
            $this->notification->create(
                $recipient->id, null, $text1, 'milestones', 'milestone'
            );

            //Owner NotificationService
            $text = 'Project manager '.$recipient->fname.' '.$recipient->lanme.' has been assigned for  Your Milestone ' . $milestone->title . ' to progress with the pre-release audit.';
            $this->notification->create(
                $owner->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l to PM and Owner
            $subject = 'Project Manager assigned for Mid-Milestone Audit';
            $mail_to = [$recipient->email, $owner->email];
            $data = [
                'boName' => $milestone->listing->name,
                'milestoneName' => $milestone->title,
                'pmName' => $recipient->fname . ' ' . $recipient->lname,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.mid_milestone.pm_assigned', $data, $mail_to);
        }
        if ($type == 'final_pm_assigned') {
            $owner = $milestone->listing->owner;

            //Manager NotificationService
            $text1 = 'You have been assigned as Project Manager for Milestone ' . $milestone->title;
            $this->notification->create(
                $recipient->id, null, $text1, 'milestones', 'milestone'
            );

            //Owner NotificationService
            $text = 'Project manager '.$recipient->fname.' '.$recipient->lanme.' has been assigned for  Your Milestone ' . $milestone->title . ' to progress with the final-approval audit.';
            $this->notification->create(
                $owner->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l to PM and Owner
            $subject = 'Project Manager assigned for final Milestone Approval';
            $mail_to = [$recipient->email, $owner->email];
            $data = [
                'boName' => $milestone->listing->name,
                'milestoneName' => $milestone->title,
                'pmName' => $recipient->fname . ' ' . $recipient->lname,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.final_approval.pm_assigned', $data, $mail_to);

            // Notice  to  Investors
            $investors = $milestone->investors;
            foreach($investors as $investor) {
                $text1 = 'Project manager '.$recipient->fname.' '.$recipient->lanme.' has been assigned for  Your Milestone ' . $milestone->title . ' to progress with the final-approval audit.';
                $this->notification->create(
                    $investor->id, null, $text1, 'milestones', 'milestone'
                );
            }
        }
    }


    // PM approve/reject an audit after review
    public function audit_review(Request $request)
    {
        try{
            $validated = $request->validate([
                'audit_id'   => 'required|integer|exists:p_m_audits,id',
                'action'        => 'required|string|in:approved,rejected',
            ]);

            $audit = PMAudit::find($validated['audit_id']);
            if (!$audit) { return response()->json(['message' => 'PM audit record not found.'], 404); }

            $milestone = $audit->milestone ?? $audit->midMilestone->milestone ?? $audit->preReleaseRequest->milestone;
            $type = $audit->type;

            if($type=='mid_milestone'){

                $audit->midMilestone->status = $validated['action'];
                $audit->midMilestone->save();

                if ($milestone->fund_released) {
                    return response()->json(['message' => 'Milestone already approved, and funds already released.'], 409);
                }

                // release 25%
                if($validated['action']=='approved'){

                    $fundReleaseService = new MilestoneFundReleaseService($this->Client);

                    $result = $fundReleaseService->release($milestone, 25);

                    if (!$result->success) {
                        return response()->json(['message' => $result->message], 422);
                    }

                    $milestone->fund_released = true;
                    $milestone->final_approval_started = true;
                    $milestone->status = 'in_progress';

                    $milestone->save();
                    //$midController->notify($milestone->listing->owner, 'approved', $audit->midMilestone);
                }
                elseif ($validated['action']=='rejected'){
                    $audit->midMilestone->status = $validated['action'];
                    $audit->midMilestone->save();
                    // Notify Owner
                    $midController = new MidMilestoneController($this->emailService, $this->Client);
                    //$midController->notify($milestone->listing->owner, 'rejected', $audit->midMilestone);
                }
            }
            elseif($type=='pre_release'){
                $milestone->status = 'pr_'.$validated['action'];
                $audit->preReleaseRequest->status = 'pr_'.$validated['action'];

                if($validated['action']=='approved'){

                    if ($milestone->fund_released || $milestone->fund_released_75) {
                        return response()->json(['message' => 'Pre-release already approved, and funds already released.'], 409);
                    }

                    // Release 75%
                    $fundReleaseService = new MilestoneFundReleaseService($this->Client);

                    $result = $fundReleaseService->release($milestone, 75);

                    if (!$result->success) {
                        return response()->json(['message' => $result->message], 422);
                    }


                    $daysToExec = $milestone->no_o_days;
                    $milestone->fund_released_75 = true;
                    $milestone->status = 'in_progress';

                    $milestone->exec_deadline_date = now()->addDays($daysToExec)->format('Y-m-d');

                    $milestone->save();
                    $audit->preReleaseRequest->save();

                    $milestoneController = new MilestoneController($this->Client);
                    $milestoneController->notify($milestone->listing->owner, 'pr_approved', $milestone);

                }
                elseif( $validated['action']=='rejected'){
                    $audit->preReleaseRequest->status = 'pr_'.$validated['action'];
                    $audit->preReleaseRequest->save();
                    // Notify Owner
                    $milestoneController = new MilestoneController($this->Client);
                    $milestoneController->notify($milestone->listing->owner, 'pr_rejected', $milestone);
                }
            }
            elseif($type=='final_approval'){

                if($validated['action']=='approved'){
                    if ($milestone->status == 'done') {
                        return response()->json(['message' => 'Milestone already approved.'], 409);
                    }

                    $milestone->status = 'done';
                    $milestone->verified = true;
                    $milestone->save();

                    // Notify Owner
                    $milestoneController = new MilestoneController($this->Client);
                    $milestoneController->notify($milestone->listing->owner, 'final_approved', $milestone);
                }
                elseif( $validated['action']=='rejected'){
                    if ($milestone->status == 'done') {
                        return response()->json(['message' => 'Milestone already approved.'], 409);
                    }

                    $milestone->status = 'in_progress';
                    $milestone->save();
                    // Notify Owner
                    $milestoneController = new MilestoneController($this->Client);
                    $milestoneController->notify($milestone->listing->owner, 'final_rejected', $milestone);
                }

            }

            return response()->json([
                'message' => 'Review submitted',
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
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

}
