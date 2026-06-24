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
use App\Models\Services\ServiceBook;
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
        $this->ncService = new NoncompliantService();
    }

    // Non Compliance for a milestone
    public function non_compliances($milestone_id){
        try{
            $user_id = Auth::id();
            $nc = MilestoneNonCompliance::with(['votes.investor', 'documents'])->where('milestone_id', $milestone_id)
                ->latest()->first();

            if(!$nc){
                return response()->json(['message' => 'Milestone Non Compliance record not found.'], 404);
            }
            $nc->voted = $nc->votes->contains('investor_id', $user_id);
            return response()->json(['data' => $nc ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // INVESTOR VOTE (weighted)
    public function nc_vote_store(Request $req)
    {
        try{
            $req->validate([
                'non_compliance_id' => 'required',
                'vote' => 'required|in:continue,freeze,dispute',
                'reason' => 'nullable|string|max:2000',
            ]);

            $user = Auth::user();
            $nc = MilestoneNonCompliance::with(['milestone', 'votes'])->find($req->non_compliance_id);

            if(!$nc){
                return response()->json(['message' => 'Non Compliance record not found.'], 404);
            }
            if($nc->milestone->fund_released){
                return response()->json(['message' => 'Fund already released for this milestone.'], 400);
            }

            if( $nc->stage != 'ipm' ){
                return response()->json(['message' => 'Voting period has not began yet.'], 400);
            }

            if( !$nc->activeVote ){
                return response()->json(['message' => 'Voting period has ended for this Non-Compliance.'], 400);
            }

            // verify investor
            $isInvestor = $nc->milestone->accepted_bids()->where('investor_id', $user->id)->exists();
            if (!$isInvestor) {
                return response()->json(['message' => 'Only investors can vote'], 403);
            }

            // upsert vote
            $weights = $nc->milestone->investor_weights()->pluck('total', 'investor_id');
            $weight = $weights[$user->id] ?? 0;

            MilestoneNoncomplianceVotes::updateOrCreate(
                ['non_compliance_id' => $nc->id, 'investor_id' => $user->id],
                ['vote' => $req->vote, 'weight' => $weight]
            );

            $nc->save(); $nc->refresh();

            $totalInvestment = $nc->milestone->funding_collected; // or $weights->sum();
            $participatedWeight = $nc->votes->sum('weight');
            $quorumReached = $participatedWeight >= ($totalInvestment * 0.51); //51%

            // evaluate votes after each vote if 60% quorum reached or all investors voted
            if($quorumReached && !$nc->milestone->fund_released){
                $this->ncService->getAndSetIPMResult($nc);
                //$req->vote->update([ 'status' => 'closed', 'ends_at' => now() ]);
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

    // NC Response - RMEP / Document Submission
    public function nc_response_store(Request $request)
    {
        try{
            $fileUpload = new FileUploadService();
            $validated = $request->validate([
                'non_compliance_id' => ['required', 'exists:milestone_non_compliances,id'],
                'documents.*'       => ['required', 'file', 'mimes:pdf,docx,xlsx'], // adjust allowed types
                'response_type'     => ['required', 'in:rmep,completion_proof,other'],
            ]);

            $nc = MilestoneNonCompliance::with('milestone')->findOrFail($request->non_compliance_id);

            if(!$nc){
                return response()->json(['message' => 'Non Compliance record not found.'], 404);
            }

            // Check if Stage 2 window is active
            if ( $nc->stage != 'response_window' ) {
                return response()->json(['message' => 'Stage 2 response window is closed.'], 400);
            }


            $milestone = $nc->milestone;

            if ($milestone->listing->owner->id !== Auth::id()) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }

            $path = 'files/milestoneNC/' . $nc->id;

            $uploadedFiles = [];
            $files = $request->file('documents', []);
            foreach ($files as $file) {
                $filePath = $fileUpload->saveFile($file, $path);

                // save to NC documents table
                $doc = $nc->documents()->create([
                    'nc_id'     => $nc->id,
                    'file_path' => $filePath,
                    'file_type'      => $request->response_type, // can be RMEP or other type if needed
                    'uploaded_by'=> Auth::id(),
                ]);
                $uploadedFiles[] = $doc;
            }

            $nc->update([
                'owner_response_type' => $request->response_type,
                'stage' => 'ipm',
                'ipm_started_at' => now()
            ]);

            return response()->json([
                'message' => 'Document submitted successfully.',
            ], 200);

            // Notify investors about the response submission ...
        }
        catch (ValidationException $e) {
            // return actual validation errors
            return response()->json(['message' => 'Validation failed.', 'errors'  => $e->errors()], 422);
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
            : serviceBook::where('service_id', $listingId)->where('booker_id', Auth::id())->where('paid', 1)->exists();

        return response()->json(['dispute' => $toDispute]);
    }

}
