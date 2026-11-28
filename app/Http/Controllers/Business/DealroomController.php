<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\Listing;
use App\Models\Milestones\MilestoneCommunications;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DealroomController extends Controller
{
    private const PAID_IN = ['confirmed', 'Confirmed', 'verified', 'equipment_released', 'awaiting_payment', 'under_verification'];

    /** 'owner' for the business owner, 'investor' for someone with an accepted investment in it, else null. */
    private function roleIn(?Listing $listing): ?string
    {
        if (!$listing || !Auth::check()) return null;
        if ((int) $listing->user_id === (int) Auth::id()) return 'owner';
        $invested = \App\Models\Business\AcceptedBids::where('business_id', $listing->id)->where('investor_id', Auth::id())
            ->whereIn('status', self::PAID_IN)->exists()
            // a pending bidder too: they vote on a recovery plan (RMEP) for an under-funded milestone
            || \App\Models\Business\BusinessBids::where('business_id', $listing->id)->where('investor_id', Auth::id())->exists();
        return $invested ? 'investor' : null;
    }

    public function businesses(){
        try{
            if( !Auth::check() ){
                return response()->json(['message' => 'Unauthorized!' ],401);
            }
            $user = Auth::user();

            $listings = Listing::with('milestones')
                ->withCount([
                    'milestones as completed_milestones' => function ($q) {
                        $q->where('status', 'done');
                    },
                    'milestones as active_milestones' => function ($q) {
                        $q->where('status', '!=', 'done')
                            ->where('status', '!=', 'locked');
                    },
                ])
                // The owner's own businesses and the ones they invested in (investors had an empty deal room)
                ->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhereIn('id', \App\Models\Business\AcceptedBids::where('investor_id', $user->id)->whereIn('status', self::PAID_IN)->select('business_id'))
                      ->orWhereIn('id', \App\Models\Business\BusinessBids::where('investor_id', $user->id)->select('business_id'));
                })->get();

            foreach($listings as $listing){
                $listing->pending_investor_count = $listing->pending_investors()->count();
                $listing->deal_room_role = (int) $listing->user_id === (int) $user->id ? 'owner' : 'investor';
            }

            // Return as JSON or pass to view
            return response()->json([
                'listings' => $listings,
                //'milestones' => $milestones
            ], 200);
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

    public function business_stats($businessId){
        try{
            if( !Auth::check() ){
                return response()->json(['message' => 'Unauthorized!' ],401);
            }
            $user = Auth::user();

            $listing = Listing::with('milestones')
                ->withCount([
                    'milestones as completed_milestones' => function ($q) {
                        $q->where('status', 'done');
                    },
                    'milestones as active_milestones' => function ($q) {
                        $q->where('status', '!=', 'done')
                            ->where('status', '!=', 'locked');
                    },
                ])
                ->where('id', $businessId)->first();
            if ($listing && !$this->roleIn($listing)) $listing = null; // only its owner and investors

            if(!$listing){
                return response()->json(['message' => 'No listing found.'], 404);
            }
            $listing->avg_investment = $listing->invest_count > 0
                ? ($listing->amount_collected / $listing->invest_count)
                : 0;

            // Return as JSON or pass to view
            return response()->json([
                'listing' => $listing,
                //'milestones' => $milestones
            ], 200);
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


    public function participants($businessId){
        try{
            if( !Auth::check() ){
                return response()->json(['message' => 'Unauthorized!' ],401);
            }

            $listing = Listing::with([
                'milestones'       // preloads all milestones
            ])->find($businessId);
            if ($listing && !$this->roleIn($listing)) $listing = null; // only its owner and investors

            if(!$listing){
                return response()->json(['message' => 'No listing found.'], 404);
            }

            // Pending Investors
            $pendingInvestors = $listing->pending_investors()
                ->select('users.id', 'users.first_name','users.last_name','users.email')
                ->get(); // returns a Collection of User models
            //$total_investors = $investors->count();

            $pendingInvestors->map(function ($investor) use ($listing){
                $investor->amount = $investor->pivot->amount ?? 0;
                $investor->percentage = $investor->pivot->representation ?? 0;
                $investor->investment_date = $investor->pivot->date ?? null;

                // Add milestone object if ms_id exists
                $investor->milestones = $investor->pivot->ms_id
                    ? $listing->milestones->where('id', $investor->pivot->ms_id)->first()
                    : null;

                return $investor;
            });


            // Active Investors
            $investors = $listing->investors()
                ->select('users.id', 'users.first_name','users.last_name','users.email')
                ->get(); // returns a Collection of User models
            $total_investors = $investors->count();

            $investors->map(function ($investor) use ($listing){
                $investor->amount = $investor->pivot->amount ?? 0;
                $investor->percentage = $investor->pivot->representation ?? 0;
                $investor->investment_date = $investor->pivot->date ?? null;

                // Add milestone object if ms_id exists
                $investor->milestones = $investor->pivot->ms_id
                    ? $listing->milestones->where('id', $investor->pivot->ms_id)->first()
                    : null;

                return $investor;
            });
            // One entry per investor: an investment that spilled into the next milestone is two accepted rows, which used
            // to show the same person twice (and count them as two investors)
            $investors = $investors->groupBy('id')->map(function ($rows) {
                $first = $rows->first();
                if ($rows->count() > 1) {
                    $first->amount = $rows->sum(fn ($r) => (float) $r->amount);
                    $first->percentage = number_format($rows->sum(fn ($r) => (float) $r->percentage), 2, '.', '');
                    $first->milestone_ids = $rows->pluck('milestones.id')->filter()->values();
                }

                return $first;
            })->values();
            $total_investors = $investors->count();

            $stats = collect([
                'total_investors' => $listing->invest_count,
                'total_investment' => $listing->investment_needed,
                'avg_investment' => $listing->invest_count > 0
                    ? ($listing->amount_collected / $listing->invest_count)
                    : 0,
            ]);

            // Return as JSON or pass to view
            return response()->json([
                'pending_investors' => $pendingInvestors,
                'investors' => $investors,
                'stats' => $stats
            ], 200);
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

    // C o m m u n i c a t i o n s
    public function communications($milestoneId)
    {
        try{
            $milestone = \App\Models\Milestones\Milestones::with('listing')->find($milestoneId);
            if (!$this->roleIn($milestone?->listing)) {
                return response()->json(['message' => 'Milestone not found.'], 404); // only the business owner and its investors read it
            }
            $rmep = MilestoneCommunications::with('sender')
                ->where('milestone_id', $milestoneId)->orderBy('id')->get();

            return response()->json([
                'data'    => $rmep
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }

    public function communications_store(Request $request)
    {
        try{
            $validated = $request->validate([
                'message'     => ['required', 'string'],
                'milestone_id'   => ['required', 'integer', 'exists:milestones,id'],
                //'sender_id'   => ['required', 'integer', 'exists:users,id'],
                'sender_type' => ['nullable', 'string'],
                'type'        => ['required', 'string'],
            ]);

            $sender = Auth::user();
            $milestone = \App\Models\Milestones\Milestones::with('listing')->find($validated['milestone_id']);
            $role = $this->roleIn($milestone?->listing);
            if (!$role) {
                return response()->json(['message' => 'Milestone not found.'], 404);
            }

            $communication = MilestoneCommunications::create([
                'message'     => $validated['message'],
                'milestone_id'   => $validated['milestone_id'],
                'sender_id'   => $sender->id,
                'sender_type' => $role, // worked out here, not taken from the request
                'type'        => $validated['type'] ?? null,
            ]);

            // The other side is told: the investors when the owner writes, the owner when an investor writes
            $listing = $milestone->listing;
            $recipients = $role === 'owner'
                ? \App\Models\Business\AcceptedBids::where('business_id', $listing->id)->whereIn('status', self::PAID_IN)->pluck('investor_id')->unique()
                : collect([$listing->user_id]);
            foreach ($recipients->reject(fn ($id) => (int) $id === (int) $sender->id) as $rid) {
                $this->notification->create((int) $rid, $sender->id,
                    '_name wrote in the deal room about '.$milestone->title.' ('.$listing->name.').',
                    'dealroom', 'investor'); // 'investor' type shows the sender's name in the notice
            }

            return response()->json([
                'message' => 'Communication created successfully.',
                'data'    => $communication
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.' ], 500);
        }
    }



    /**
     * A short-lived link to one stored piece of milestone evidence (pre-release documents, mid-milestone and completion
     * proof, the RMEP document), for the business owner and its investors only. They are private S3 files, so the deal
     * room could only show their names.
     */
    public function evidenceFile(Request $request, $milestoneId)
    {
        $milestone = \App\Models\Milestones\Milestones::with('listing')->find($milestoneId);
        if (!$this->roleIn($milestone?->listing)) {
            return response()->json(['message' => 'Milestone not found.'], 404);
        }

        $kind = (string) $request->query('kind');
        $id = (int) $request->query('id');
        $path = null;

        if ($kind === 'pre_release') {
            $fields = ['quotation', 'proforma_invoice', 'vendor_estimate', 'mou_supplier_confirm', 'industry_benchmark', 'prior_proposal_align',
                'project_budget_match', 'kra_pin', 'business_registration', 'updated_budget', 'project_plan_details', 'photo_location', 'photo_equipment', 'photo_current_state'];
            $field = (string) $request->query('field');
            $doc = \Illuminate\Support\Facades\DB::table('milestone_pre_release_docs as d')
                ->join('milestone_pre_release_requests as r', 'r.id', '=', 'd.request_id')
                ->where('d.id', $id)->where('r.milestone_id', $milestone->id)->first(['d.*']);
            $path = ($doc && in_array($field, $fields, true)) ? ($doc->{$field} ?? null) : null;
        } elseif ($kind === 'mid') {
            $path = \Illuminate\Support\Facades\DB::table('mid_milestone_documents as d')->join('mid_milestones as m', 'm.id', '=', 'd.mid_milestone_id')
                ->where('d.id', $id)->where('m.milestone_id', $milestone->id)->value('d.file_path');
        } elseif ($kind === 'final') {
            $path = \Illuminate\Support\Facades\DB::table('final_approval_documents as d')->join('milestone_execution_documents as e', 'e.id', '=', 'd.milestone_execution_id')
                ->where('d.id', $id)->where('e.milestone_id', $milestone->id)->value('d.file_path');
        } elseif ($kind === 'rmep') {
            $path = \Illuminate\Support\Facades\DB::table('milestone_rmeps')->where('id', $id)->where('milestone_id', $milestone->id)->value('rmep_document');
        } elseif ($kind === 'nc') {
            // the owner's answer to a non-compliance case: like the case itself, only the owner and this milestone's investors
            $party = (int) $milestone->listing->user_id === (int) Auth::id()
                || $milestone->accepted_bids()->where('investor_id', Auth::id())->whereIn('status', ['confirmed', 'Confirmed', 'verified', 'equipment_released'])->exists();
            $path = $party ? \Illuminate\Support\Facades\DB::table('nc_documents as d')->join('milestone_non_compliances as c', 'c.id', '=', 'd.nc_id')
                ->where('d.id', $id)->where('c.milestone_id', $milestone->id)->value('d.file_path') : null;
        }

        if (!$path || $path === 'null.pdf') {
            return response()->json(['message' => 'File not found.'], 404);
        }

        try {
            $url = \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(45), ['ResponseContentDisposition' => 'inline']);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['milestone_id' => $milestone->id, 'kind' => $kind]);
            return response()->json(['message' => 'This file cannot be opened right now.'], 503);
        }

        return response()->json(['url' => $url, 'expires_in_minutes' => 45], 200);
    }
}
