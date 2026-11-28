<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\Listing;
use App\Models\Milestones\Dispute;
use App\Models\Milestones\Milestones;
use App\Models\Milestones\NonCompliance\MilestoneNonCompliance;
use App\Models\Milestones\NonCompliance\MilestoneNoncomplianceVotes;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Services;
use App\Service\File\FileUploadService;
use App\Service\Misc\ErrorLogService;
use App\Service\Noncompliance\NonCompliantService;
use App\Service\Noncompliance\VotingService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolutionController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->ncVotingService = new VotingService();
        $this->ncService = new NonCompliantService();
    }

    /** Investors whose money is in this milestone (the ones who vote on its non-compliance case). */
    private function isMilestoneInvestor(Milestones $milestone, int $userId): bool
    {
        return $milestone->accepted_bids()->where('investor_id', $userId)
            ->whereIn('status', ['confirmed', 'Confirmed', 'verified', 'equipment_released'])->exists();
    }

    // Non Compliance for a milestone: its owner and its investors only
    public function non_compliances($milestone_id){
        try{
            $user = Auth::user();
            $milestone = Milestones::with('listing')->find($milestone_id);
            if (!$milestone) {
                return response()->json(['message' => 'Milestone not found.'], 404);
            }
            $isOwner = (int) $milestone->listing?->user_id === (int) $user->id;
            if (!$isOwner && !$this->isMilestoneInvestor($milestone, (int) $user->id)) {
                return response()->json(['message' => 'Only the business owner and this milestone\'s investors can see its non-compliance case.'], 403);
            }

            $nc = MilestoneNonCompliance::with(['votes.investor', 'documents'])->where('milestone_id', $milestone_id)
                ->latest()->first();

            if(!$nc){
                return response()->json(['message' => 'Milestone Non Compliance record not found.'], 404);
            }
            $nc->voted = $nc->votes->contains('investor_id', $user->id);
            $nc->can_respond = $isOwner && $nc->stage === 'response_window';
            $nc->vote_ends_at = $nc->stage === 'ipm' && $nc->ipm_started_at
                ? $nc->ipm_started_at->copy()->addDays(NonCompliantService::VOTE_DAYS) : null;
            return response()->json(['data' => $nc ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // INVESTOR VOTE (weighted): continue, freeze or dispute, while the case is with the investors (stage ipm)
    public function nc_vote_store(Request $req)
    {
        $req->validate([
            'non_compliance_id' => 'required|integer',
            'vote' => 'required|in:continue,freeze,dispute',
            'reason' => 'nullable|string|max:2000|required_if:vote,freeze,dispute',
        ]);
        try{
            $user = Auth::user();
            $nc = MilestoneNonCompliance::with(['milestone', 'votes'])->find($req->non_compliance_id);

            if(!$nc){
                return response()->json(['message' => 'Non Compliance record not found.'], 404);
            }
            if (!$this->isMilestoneInvestor($nc->milestone, (int) $user->id)) {
                return response()->json(['message' => 'Only this milestone\'s investors can vote.'], 403);
            }
            if($nc->milestone->fund_released){
                return response()->json(['message' => 'Fund already released for this milestone.'], 422);
            }
            if( $nc->stage != 'ipm' ){
                return response()->json(['message' => $nc->stage === 'response_window'
                    ? 'Voting opens when the owner answers or their 72 hours are up.'
                    : 'Voting on this case has ended.'], 422);
            }
            if( !$nc->activeVote ){
                return response()->json(['message' => 'Voting period has ended for this Non-Compliance.'], 422);
            }

            $weights = $nc->milestone->investor_weights()->pluck('total', 'investor_id');
            $weight = $weights[$user->id] ?? 0;

            MilestoneNoncomplianceVotes::updateOrCreate(
                ['non_compliance_id' => $nc->id, 'investor_id' => $user->id],
                ['vote' => $req->vote, 'weight' => $weight, 'reason' => $req->reason ?: null]
            );
            $nc->refresh();

            // Decided as soon as investors holding 51% of the money have voted (else when the 7 days are up)
            $totalInvestment = (float) $nc->milestone->funding_collected;
            $participatedWeight = (float) $nc->votes()->sum('weight');
            if ($totalInvestment > 0 && $participatedWeight >= $totalInvestment * 0.51) {
                $this->ncService->getAndSetIPMResult($nc);
            }

            return response()->json(['message' => 'Vote recorded', 'stage' => $nc->fresh()->stage], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // The owner's answer in the 72-hour window (completion proof, a revised plan or other documents): the investors
    // take over straight away
    public function nc_response_store(Request $request)
    {
        $request->validate([
            'non_compliance_id' => ['required', 'integer'],
            'documents'         => ['required', 'array', 'min:1', 'max:5'],
            'documents.*'       => ['file', 'mimes:pdf,docx,xlsx', 'max:10240'],
            'response_type'     => ['required', 'in:rmep,completion_proof,other'],
            'note'              => ['nullable', 'string', 'max:2000'],
        ]);
        $saved = [];
        try{
            $nc = MilestoneNonCompliance::with('milestone.listing')->find($request->non_compliance_id);
            if(!$nc){
                return response()->json(['message' => 'Non Compliance record not found.'], 404);
            }
            if ((int) $nc->milestone?->listing?->user_id !== (int) Auth::id()) {
                return response()->json(['message' => 'Only the business owner can answer this case.'], 403);
            }
            if ( $nc->stage != 'response_window' ) {
                return response()->json(['message' => 'The 72-hour response window is closed.'], 422);
            }

            $fileUpload = new FileUploadService();
            $path = 'files/milestoneNC/' . $nc->id;
            foreach ($request->file('documents', []) as $file) {
                $filePath = $fileUpload->saveFile($file, $path);
                $saved[] = $filePath;
                $nc->documents()->create([
                    'nc_id' => $nc->id, 'file_path' => $filePath, 'file_type' => $request->response_type, 'uploaded_by' => Auth::id(),
                ]);
            }

            $nc->update(['owner_response_type' => $request->response_type, 'owner_response_note' => $request->note ?: null]);
            if (! $this->ncService->triggerIPM($nc)) {
                return response()->json(['message' => 'The 72-hour response window is closed.'], 422);
            }

            return response()->json(['message' => 'Your response was sent to your investors.'], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }

    // Milestone Dispute

    public function raiseDispute(Request $request)
    {
        $uploadedFiles = [];

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'type'       => 'required|in:B,S',
                'project_id' => 'required|integer',
                'reason'     => 'required|string|max:500',
                'details'    => 'required|string|max:2000',
                'document'   => 'nullable|file|mimes:pdf,docx|max:2048',
            ]);

            $mile = $validated['type'] === 'B'
                ? Milestones::findOrFail($validated['project_id'])
                : ServiceBookingMilestone::findOrFail($validated['project_id']);

            $project = $validated['type'] === 'B'
                ? Listing::findOrFail($mile->listing_id)
                : Services::findOrFail($mile->service_id);

            $owner = User::findOrFail($project->user_id);

            $finalDocument = null;
            if ($request->hasFile('document')) {
                $finalDocument   = $this->fileUpload->saveFile($request->file('document'), 'files/disputes/' . $validated['project_id']);
                $uploadedFiles[] = $finalDocument;
            }
            Dispute::create([
                ...$validated,
                'user_id'      => Auth::id(),
                'mile_id'      => $validated['project_id'],
                'mile_name'    => $mile->title,
                'project_name' => $project->name,
                'document'     => $finalDocument,
            ]);

            DB::commit();

            $this->emailService->send(
                'Dispute Raised', 'dispute_mail',
                ['business_name' => $project->name, 'mile_name' => $mile->title, 'p_id' => base64_encode(base64_encode($project->id))],
                $owner->email
            );

            return response()->json([
                'message' => 'Dispute opened. We will review the details and get back to you.'
            ], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            foreach ($uploadedFiles as $file) {
                if ($file && file_exists(public_path($file))) unlink(public_path($file));
            }
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function checkDispute(int $listingId, string $type)
    {
        $toDispute = $type === 'B'
            ? AcceptedBids::where('business_id', $listingId)->where('investor_id', Auth::id())->exists()
            : ServiceBooking::where('service_id', $listingId)->where('booker_id', Auth::id())->where('paid', 1)->exists();

        return response()->json(['dispute' => $toDispute]);
    }

}
