<?php

namespace App\Http\Controllers\Grant;

use App\Http\Controllers\Controller;
use App\Models\ApplicationRoundHistory;
use App\Models\Auth\User;
use App\Models\Grants\Grant;
use App\Models\Grants\GrantApplication;
use App\Models\Grants\GrantMilestone;
use App\Models\Grants\GrantWatchlist;
use App\Models\Grants\Rounds\ApplicationRoundResponse;
use App\Models\Grants\Rounds\GrantRound;
use App\Models\Grants\Rounds\RoundCustomQuestion;
use App\Models\Grants\Rounds\RoundRequiredDocument;
use App\Service\Grant\RoundHelperService;
use App\Service\Grant\RoundHistoryService;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrantApplicationController extends Controller
{
    protected $roundHistory;
    public function __construct()
    {
        $this->roundHistory = new RoundHistoryService();
        parent::__construct();
    }
    #All pitches/application for a grant
    public function index($grant_id)
    {
        $user_id = Auth::id();

        $pitches = GrantApplication::with('grant_milestones')
            ->where('grant_id', $grant_id)->latest()->get();


        $apps = $pitches->map(function ($pitch) {
            return [
                ...$pitch->toArray(),
                'status' => [
                    'value' => $pitch->status,
                    'color' => config('status.grant_application.' . $pitch->status, 'gray'),
                ],
            ];
        });


        return response()->json(['pitches' => $apps]);
    }
    #Single pitch details
    public function show($id)
    {
        try {
            $pitch = GrantApplication::with([
                'grant_milestones',
                'grant:id,grant_title,grant_type,status,total_rounds,funding_per_business,mid_milestone_required',
                'currentRound',
            ])->findOrFail($id);

            $roundData = null;
            $roundService = new RoundHelperService();
            if ($pitch->current_round_id) {
                $roundData = $roundService->buildRoundData($pitch->currentRound, $pitch);
            }

            return response()->json([
                'pitch' => [
                    ...$pitch->toArray(),
                    'status' => [
                        'value' => $pitch->status,
                        'color' => config('status.grant_application.' . $pitch->status, 'gray'),
                    ],
                    'round_status' => [
                        'value' => $pitch->round_status,
                        'color' => config('status.grant_application_round.' . $pitch->round_status, 'gray'),
                    ],
                    'funding_setup_status' => [
                        'value' => $pitch->funding_setup_status,
                        'color' => config('status.funding_setup.' . $pitch->funding_setup_status, 'gray'),
                    ],
                    'knockout_status' => [
                        'value' => $pitch->knockout_status,
                        'color' => config('status.knockout.' . $pitch->knockout_status, 'gray'),
                    ],
                ],
                'round_data' => $roundData,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Application not found'], 404);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }



    // fetch pitches for all types of user
    public function myPitches()
    {
        $match_score = 0;
        $user = $this->get_role();

        if (in_array($user->role, ['editor', 'viewer', 'admin'])) {
            $watchlistPitchIds = GrantWatchlist::where('grant_owner_id', $user->grant_owner_id)->pluck('pitch_id')->toArray();
            $pitches = GrantApplication::with(['grant', 'currentRound', 'grant_milestones'])
                ->where('grant_owner_id', $user->grant_owner_id)
                ->whereNot('status', 'draft')
                ->latest()->get();
        }
        else if ($user->user_type_id == 2){
            $watchlistPitchIds = GrantWatchlist::where('grant_owner_id', $user->id)->pluck('pitch_id')->toArray();
            $pitches = GrantApplication::with(['grant', 'currentRound', 'grant_milestones'])
                ->where('grant_owner_id', $user->id)
                ->whereNot('status', 'draft')
                ->latest()->get();
        }
        else {
            $watchlistPitchIds = [];
            $pitches = GrantApplication::with([
                'grant', 'currentRound',
                'grant_milestones'
            ])->where('user_id',$user->id)->latest()->get();
        }

        foreach ($pitches as $pitch){
            $pitch->in_my_watchlist = in_array($pitch->id, $watchlistPitchIds);

            $status = $pitch->status; // store original value first

            $pitch->status = [
                'value' => $status,
                'color' => config('status.grant_application.' . $status, 'gray'), // fallback
            ];
        }

        $match_score = $pitches->sum('score');
        $pitch_count = $pitches->count();
        $pitch_count_accept = $pitches->where('status', 1)->count();
        $pitch_count_pending = $pitch_count-$pitch_count_accept;
        $avg_match_score = round($pitch_count > 0 ? $match_score/$pitch_count : 0, 2);;
        $accept_rate = $pitch_count ? round( ($pitch_count_accept/$pitch_count)*100, 2) : 0;

        return response()->json([
            'pitches' => $pitches,
            'avg_match_score' => $avg_match_score,
            'accept_rate' => $accept_rate,
            'pitch_count' => $pitch_count,
            'pitch_count_pending' => $pitch_count_pending,
        ]);
    }

    // added by owen - get grant details for SME application modal (round 1 questions, required docs, etc.)
    public function application_info($grant_id)
    {
        try{
            $grant = Grant::with([
                'wallet'
            ])->withCount('liked')->find($grant_id);

            //  Handle not found
            if (!$grant) {
                return response()->json([
                    'message' => 'Grant not found'
                ], 404);
            }

            $round1 = $grant->rounds()->with(['questions', 'knockoutQuestions'])->orderBy('id')->first();;

            //  Better naming (no overwrite)
            if (Auth::check()) {
                $grant->liked = $grant->liked()
                    ->where('user_id', Auth::id())
                    ->exists();
            }

            $grantData = [
                ...$grant->toArray(),
                'status' => [
                    'value' => $grant->status,
                    'color' => config('status.grant.' . $grant->status, 'gray'),
                ],
            ];

            $roundData = $round1 ? [
                ...$round1->toArray(),
                'status' => [
                    'value' => $round1->status,
                    'color' => config('status.grant_round.' . $round1->status, 'gray'),
                ],
            ] : null;

            return response()->json([
                'grant_data' => $grantData,
                'application_round' => $roundData,
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

    // all rounds for an application
    public function roundsHistory(GrantApplication $application)
    {
        try {
            $history = ApplicationRoundHistory::where('application_id', $application->id)
                ->with([
                    'round',
                    'round.reviewers',
                    'round.questions',
                    'round.answers',
                    'round.scores',
                    'round.uploadedDocuments',
                ])->orderBy('round_number')
                ->get();
            return response()->json([ 'rounds' => $history ]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function roundsHistoryByRound(GrantRound  $round)
    {
        try {
            $history = ApplicationRoundHistory::where('round_id', $round->id)
                ->with([
                    'round.reviewers',
                    'round.questions',
                    'round.scores',
                    'round.uploadedDocuments',
                ])->orderBy('round_number')
                ->get();
            return response()->json([ 'history' => $history ]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function store_application(Request $request, Grant $grant)
    {
        $uploadedFiles = [];
        DB::beginTransaction();

        try{
//            if(!$grant || $grant ->status != 'open') {
//                return response()->json(['message' => 'This grant is not open for applications.'], 400);
//            }

            $validated = $request->validate([
                // Required: Business info
                'business_id' => 'required|exists:listings,id',
                'startup_name' => 'required|string|max:255',
                'contact_person_name' => 'required|string|max:100',
                'contact_person_email' => 'required|email|max:100',
                'sector' => 'required|string|max:100',
                'headquarters_location' => 'required|string|max:255',
                'total_amount_requested' => 'required|numeric|min:0|max:' . $grant->funding_per_business,

                // Optional: Business details
                'stage' => 'nullable|string|max:100',
                'revenue_last_12_months' => 'nullable|numeric|min:0',
                'team_experience_avg_years' => 'nullable|integer|min:0',
                'traction_kpis' => 'nullable|string',

                // Optional: Files
                'pitch_deck_file' => 'nullable|file|mimes:pdf,ppt,pptx|max:20480',  // 20MB
                'pitch_video' => 'nullable|url',
                'business_plan_file' => 'nullable|file|mimes:pdf,doc,docx|max:20480',

                // Optional: Impact
                'social_impact_areas' => 'nullable|array',
                'bonus_points' => 'nullable|string|max:300',
                'match_score' => 'required|numeric',
                'score_breakdown' => 'required|array',

                // Separate section validation
                'round_answers' => 'nullable|array',
                'round_answers.*.question_id' => 'required_with:round_answers|integer|exists:round_custom_questions,id',
                'round_answers.*.response' => 'nullable|string|max:1000',


                // Documents
                'round_documents' => 'nullable|array',
                'round_documents.*.document_type' => 'required_with:round_documents|string|max:100',
                'round_documents.*.file' => 'required_with:round_documents|file|max:20480',

                //'custom_field_confirmations' => 'nullable|array',


            ]);

            // Auto-set fields
            $validated['grant_id'] = $grant->id;  $validated['grant_owner_id'] = $grant->user_id;
            $validated['user_id'] = auth()->id(); $validated['status'] = 'pending';
            $validated['current_round_id'] = $grant->rounds()
                ->where('status', 'published')
                ->orderBy('round_number')
                ->first()?->id ?? $grant->rounds()->orderBy('round_number')->first()?->id;

            if( !$validated['current_round_id']) {
                return response()->json(['message' => 'No round exists for the grant.'], 422);
            }

            // Knockout answer check
            $knockoutQuestionIds = RoundCustomQuestion::where('round_id', $validated['current_round_id'])
                ->where('question_type', 'knockout')->pluck('id');

            $inputAnswers = collect($request->input('round_answers', []));
            $answeredIds = $inputAnswers->filter(fn($a) => !empty($a['response']))->pluck('question_id');
            $missing = $knockoutQuestionIds->diff($answeredIds);

            if ($missing->isNotEmpty()) {
                return response()->json([
                    'message' => 'All knockout questions must be answered.', 'missing_question_ids' => $missing->values(),
                    'round_answers_raw' => $request->input('round_answers'),
                    'request' => $request->round_answers,
                ], 422);
            }

            $exists = GrantApplication::where('grant_id', $grant->id)->where('user_id', Auth::id())->exists();
            if($exists) {
                //return response()->json(['message' => 'You have already applied for this grant.'], 400);
            }


            //Upload Files
            $loc='files/grantApps/'.$grant->id;

            if ($request->hasFile('pitch_deck_file')) {
                $pitch_deck_file = $request->file('pitch_deck_file');
                $validated['pitch_deck_file'] = $this->fileUpload->saveFile($pitch_deck_file, $loc);
                $uploadedFiles[] = $validated['pitch_deck_file'];
            }

            if ($request->hasFile('business_plan_file')) {
                $business_plan_file = $request->file('business_plan_file');
                $validated['business_plan_file'] = $this->fileUpload->saveFile($business_plan_file, $loc);
                $uploadedFiles[] = $validated['business_plan_file'];
            }

            $application = GrantApplication::create(
                Arr::except($validated, ['round_answers', 'round_documents'])
            );

            $doc_path = "files/grantApps/{$grant->id}/rounds/{$application->current_round_id}";

            // Save round document uploads & answers
            $this->handleRoundData($request, $application, $doc_path, $uploadedFiles, $validated['current_round_id']);

            // check if knockout questions answered
            $ko_questionIds = RoundCustomQuestion::where('round_id', $application->current_round_id)
                ->where('question_type', 'knockout')
                ->pluck('id')->toArray();

            $answered = ApplicationRoundResponse::whereIn('question_id', $ko_questionIds)->count();

            if($answered < count($ko_questionIds)) {
                return response()->json(['message' => 'All knockout questions must be answered.'], 422);
            }

            $text = 'You have a new application pitch.';
            $this->notification->create(
                $grant->user_id, $application->user_id,$text,'dashboard.grantOrg.applications','grant'
            );

            // Commit changes
            DB::commit();

            // E M A I L
            $sme = Auth::user();
            $smeName = $sme->fname. ' '. $sme->lname;
            $go_email = User::where('id', $grant->user_id)->value('email');

            $info=[ 'grant'=>$grant->grant_title, 'SME'=>$smeName ];
            $this->emailService->send('New Grant Pitch', 'opportunities.grant_pitch', $info, $go_email);

            return response()->json(['message' => 'Grant Application Successful.'], 200);
        }
        catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.', 'errors'  => $e->errors()
            ], 422);
        }
        catch (\Exception $e) {
            DB::rollBack();

            foreach ($uploadedFiles as $file) {
                if ($file && file_exists(public_path($file))) {
                    unlink(public_path($file));
                }
            }
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    //Accept / Reject Pitch
    public function accept(Request $request, GrantApplication $pitch)
    {
        if($pitch->grant_owner_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Check application status
        if ($pitch->status !== 'submitted') {
            return response()->json([
                'error' => 'Can only accept submitted applications. Current status: ' . $pitch->status
            ], 422);
        }

        DB::beginTransaction();
        try{
            $totalMilestones = $pitch->grant_milestones()->sum('amount');
            $awardedAmount = $totalMilestones;

            // NEW: Check if max awardees limit reached
            $grant = $pitch->grant;
            $awardedCount = GrantApplication::where('grant_id', $grant->id)->where('status', 'awarded')->count();
            $maxAwardees = $grant->max_awardees ?? floor($grant->total_grant_amount / $grant->funding_per_business);

            if ($awardedCount >= $maxAwardees) {
                return response()->json([
                    'error' => "Cannot accept more applications. Maximum of {$maxAwardees} businesses can be funded already approved)."
                ], 422);
            }


            $pitch->status = 'approved';

            if ($grant->grant_type === 'single_round') {
                // Single round: approve + award immediately
                $pitch->status = 'awarded'; $pitch->awarded_at = now();
                $pitch->round_status = 'advanced';


            } else {
                // Multi-round: approve + advance to Round 2
                $nextRound = GrantRound::where('grant_id', $grant->id)
                    ->where('round_number', $pitch->currentRound->round_number + 1)->first();

                if (!$nextRound) {
                    DB::rollBack();
                    return response()->json(['error' => 'Round 2 not found'], 422);
                }

                $pitch->current_round_id = $nextRound->id;
                $pitch->round_status = 'draft';

                // Track round history
                $this->roundHistory->closeAndCreate(
                    $pitch, $nextRound, $grant->applications()->count(), 'in_progress'
                );

                $this->grantNotification->send('round.advanced', [$pitch->user], [
                    'grant_title' => $pitch->grant->grant_title, 'round_name' => $nextRound->round_name ?? 'Final Round', 'application_id' => $pitch->id,
                ]);
            }

            $pitch->save();
            DB::commit();


            // Notify applicant
            $this->grantNotification->send('application.accepted',
                [$pitch->user], [
                    'grant_title' => $pitch->grant->grant_title,
                    'business_name' => $pitch->business?->name,
                ]
            );

            return response()->json([
                'message' => 'Pitch Approved.', 'data' => $pitch,
                'remaining_slots' => $maxAwardees - ($awardedCount + 1)
            ], 200);
        }
        catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    public function reject(Request $request, GrantApplication $pitch)
    {
        if($pitch->grant_owner_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        try{
            $pitch->update(['status' => 'rejected', 'round_status' => 'not_selected']);

            // Notify applicant
            $this->grantNotification->send('application.rejected',
                [$pitch->user], [
                    'grant_title' => $pitch->grant->grant_title,
                    'business_name' => $pitch->business?->name,
                ]
            );

            return response()->json(['message' => 'Pitch Rejected.'], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }




    public function fund_request($pitch_id)
    {
        try{
            $pitch = GrantApplication::with('grant')->where('id',$pitch_id)->first();
            $user = User::select('fname','lname')->where('id',$pitch->user_id)->first();

            $text = $user->fname.' '.$user->lname. 'Has requested funding to the Grant'.$pitch->grant->grant_title;
            $notification = new NotificationService();
            $notification->create($pitch->grant->user_id,$pitch->user_id,$text,
                'dashboard.grantOrg.applications','grant_fund_request');

            //MAIL
            return response()->json(['message' => 'Fund Requested.'], 200);
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

    public function setPlanningMode(Request $request, GrantApplication $application)
    {
        try{
            $request->validate([
                'planning_mode' => 'required|in:locked,hybrid',
            ]);

            if(!$application){
                throw new \Exception('Pitch not found.');
            }

            $application->update([
                'planning_mode' => $request->planning_mode,
            ]);

            //MAIL
            return response()->json(['message' => 'Planning mode set success'], 200);
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

    public function awarded($grant_id)
    {
        $user_id = Auth::id();

        $grant = Grant::find($grant_id);

        if($grant->user_id !== $user_id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $pitches = $grant->applications()
            ->with([
                'grant',
                'grant_milestones'
            ])
            ->where('status', 'awarded')->get();


        $pitches = $pitches->map(function ($pitch) {
            return [
                ...$pitch->toArray(),
                'status' => [
                    'value' => $pitch->status,
                    'color' => config('status.grant_application.' . $pitch->status, 'gray'),
                ],
            ];
        });

        return response()->json(['applications' => $pitches]);
    }

    public function smeAwarded()
    {
        $user= Auth::user();

        if($user->user_type_id !== 4) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $pitches = $user->myApplications()
            ->with(['grant:grant_title'])
            ->where('status', 'awarded')
            ->paginate(20);

        $pitches->getCollection()->transform(function ($pitch) {
            return [
                ...$pitch->toArray(),
                'status' => [
                    'value' => $pitch->status,
                    'color' => config('status.grant_application.' . $pitch->status, 'gray'),
                ],
            ];
        });

        return response()->json(['applications' => $pitches]);
    }

    // H e l p e r s
    public function get_role()
    {
        $user = Auth::user()->load('grant_profile.role');
        $user->role = $user->grant_profile?->role?->name ?? 'super-admin';
        $user->grant_owner_id = $user->grant_profile?->grant_owner_id;
        return $user;
    }

    private function handleRoundData($request, $application, $path, &$uploadedFiles, $current_round_id)
    {
        // Entered on a round
        $this->roundHistory->create($current_round_id, $application->id, 1, 'in_progress');

        // Save answers
        if ($request->filled('round_answers')) {
            $inputAnswers = collect($request->input('round_answers'));

            $answers = $inputAnswers->map(function ($answer) use ($application, $current_round_id) {
                return [
                    'application_id' => $application->id,
                    'round_id' => $current_round_id,
                    'question_id' => $answer['question_id'],
                    'response' => $answer['response'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->toArray();

            $questionIds = $inputAnswers->pluck('question_id');

            ApplicationRoundResponse::where('application_id', $application->id)
                ->where('round_id', $current_round_id)
                ->whereIn('question_id', $questionIds)
                ->delete();

            ApplicationRoundResponse::insert($answers); // 🚀 bulk insert (faster)
        }

        // ✅ Save documents
        if ($request->has('round_documents')) {

            $documents = collect($request->input('round_documents'))
                ->map(function ($doc, $index) use ($request, $application, $path, &$uploadedFiles) {

                    // 🔥 get file using index
                    $file = $request->file("round_documents.$index.file");

                    if (!$file) return null; // skip invalid

                    $path = $this->fileUpload->saveFile($file, $path);
                    $uploadedFiles[] = $path;

                    return [
                        'application_id' => $application->id,
                        'round_id' => $application->current_round_id,
                        'document_type' => $doc['document_type'] ?? null,
                        'file_path' => $path,

                        //'original_filename' => $file->getClientOriginalName(),
                        //'file_size' => $file->getSize(),
                        //'mime_type' => $file->getMimeType(),

                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                })
                ->filter() // remove nulls
                ->values()
                ->toArray();

            if (!empty($documents)) {
                RoundRequiredDocument::insert($documents);
            }
        }
    }

}
