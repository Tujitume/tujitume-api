<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\ApplicationRoundHistory;
use App\Models\Auth\User;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\ProgramWatchlist;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Models\Programs\Rounds\RoundRequiredDocument;
use App\Service\Program\RoundHelperService;
use App\Service\Program\RoundHistoryService;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgramApplicationController extends Controller
{
    protected $roundHistory;
    public function __construct()
    {
        $this->roundHistory = new RoundHistoryService();
        parent::__construct();
    }
    #All pitches/application for a program
    public function index($program_id)
    {
        $user_id = Auth::id();

        $pitches = ProgramApplication::with('program_milestones')
            ->where('program_id', $program_id)->latest()->get();


        $apps = $pitches->map(function ($pitch) {
            return [
                ...$pitch->toArray(),
                'status' => [
                    'value' => $pitch->status,
                    'color' => config('status.program_application.' . $pitch->status, 'gray'),
                ],
            ];
        });


        return response()->json(['pitches' => $apps]);
    }
    #Single pitch details
    public function show($id)
    {
        try {
            $pitch = ProgramApplication::with([
                'program_milestones',
                'program:id,program_title,program_type,status,total_rounds,funding_per_business,mid_milestone_required',
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
                        'color' => config('status.program_application.' . $pitch->status, 'gray'),
                    ],
                    'round_status' => [
                        'value' => $pitch->round_status,
                        'color' => config('status.program_application_round.' . $pitch->round_status, 'gray'),
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
            $watchlistPitchIds = ProgramWatchlist::where('program_owner_id', $user->program_owner_id)->pluck('pitch_id')->toArray();
            $pitches = ProgramApplication::with(['program', 'currentRound', 'program_milestones'])
                ->where('program_owner_id', $user->program_owner_id)
                ->whereNot('status', 'draft')
                ->latest()->get();
        }
        else if ($user->user_type_id == 4){
            $watchlistPitchIds = ProgramWatchlist::where('program_owner_id', $user->id)->pluck('pitch_id')->toArray();
            $pitches = ProgramApplication::with(['program', 'currentRound', 'program_milestones'])
                ->where('program_owner_id', $user->id)
                ->whereNot('status', 'draft')
                ->latest()->get();
        }
        else {
            $watchlistPitchIds = [];
            $pitches = ProgramApplication::with([
                'program', 'currentRound',
                'program_milestones'
            ])->where('user_id',$user->id)->latest()->get();
        }

        foreach ($pitches as $pitch){
            $pitch->in_my_watchlist = in_array($pitch->id, $watchlistPitchIds);

            $status = $pitch->status; // store original value first

            $pitch->status = [
                'value' => $status,
                'color' => config('status.program_application.' . $status, 'gray'), // fallback
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

    // added by owen - get program details for SME application modal (round 1 questions, required docs, etc.)
    public function application_info($program_id)
    {
        try{
            $program = Program::with([
                'wallet'
            ])->withCount('liked')->find($program_id);

            //  Handle not found
            if (!$program) {
                return response()->json([
                    'message' => 'Program not found'
                ], 404);
            }

            $round1 = $program->rounds()->with(['questions', 'knockoutQuestions'])->orderBy('id')->first();;

            //  Better naming (no overwrite)
            if (Auth::check()) {
                $program->liked = $program->liked()
                    ->where('user_id', Auth::id())
                    ->exists();
            }

            $programData = [
                ...$program->toArray(),
                'status' => [
                    'value' => $program->status,
                    'color' => config('status.program.' . $program->status, 'gray'),
                ],
            ];

            $roundData = $round1 ? [
                ...$round1->toArray(),
                'status' => [
                    'value' => $round1->status,
                    'color' => config('status.program_round.' . $round1->status, 'gray'),
                ],
            ] : null;

            return response()->json([
                'program_data' => $programData,
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
    public function roundsHistory(ProgramApplication $application)
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

    public function roundsHistoryByRound(ProgramRound  $round)
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

    public function store_application(Request $request, Program $program)
    {
        $uploadedFiles = [];
        DB::beginTransaction();

        try{
//            if(!$program || $program ->status != 'open') {
//                return response()->json(['message' => 'This program is not open for applications.'], 400);
//            }

            $validated = $request->validate([
                // Required: Business info
                'business_id' => 'required|exists:listings,id',
                'startup_name' => 'required|string|max:255',
                'contact_person_name' => 'required|string|max:100',
                'contact_person_email' => 'required|email|max:100',
                'sector' => 'required|string|max:100',
                'headquarters_location' => 'required|string|max:255',
                'total_amount_requested' => 'required|numeric|min:0|max:' . $program->funding_per_business,

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
            $validated['program_id'] = $program->id;  $validated['program_owner_id'] = $program->user_id;
            $validated['user_id'] = auth()->id(); $validated['status'] = 'pending';
            $validated['current_round_id'] = $program->rounds()
                ->where('status', 'published')
                ->orderBy('round_number')
                ->first()?->id ?? $program->rounds()->orderBy('round_number')->first()?->id;

            if( !$validated['current_round_id']) {
                return response()->json(['message' => 'No round exists for the program.'], 422);
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

            $exists = ProgramApplication::where('program_id', $program->id)->where('user_id', Auth::id())->exists();
            if($exists) {
                //return response()->json(['message' => 'You have already applied for this program.'], 400);
            }


            //Upload Files
            $loc='files/programApps/'.$program->id;

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

            $application = ProgramApplication::create(
                Arr::except($validated, ['round_answers', 'round_documents'])
            );

            $doc_path = "files/programApps/{$program->id}/rounds/{$application->current_round_id}";

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
                $program->user_id, $application->user_id,$text,'dashboard.programOrg.applications','program'
            );

            // Commit changes
            DB::commit();

            // E M A I L
            $sme = Auth::user();
            $smeName = $sme->fname. ' '. $sme->lname;
            $go_email = User::where('id', $program->user_id)->value('email');

            $info=[ 'program'=>$program->program_title, 'SME'=>$smeName ];
            $this->emailService->send('New Program Pitch', 'opportunities.program_pitch', $info, $go_email);

            return response()->json(['message' => 'Program Application Successful.'], 200);
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
    public function accept(Request $request, ProgramApplication $pitch)
    {
        if($pitch->program_owner_id !== Auth::id()) {
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
            $totalMilestones = $pitch->program_milestones()->sum('amount');
            $awardedAmount = $totalMilestones;

            // NEW: Check if max awardees limit reached
            $program = $pitch->program;
            $awardedCount = ProgramApplication::where('program_id', $program->id)->where('status', 'awarded')->count();
            $maxAwardees = $program->max_awardees ?? floor($program->total_program_amount / $program->funding_per_business);

            if ($awardedCount >= $maxAwardees) {
                return response()->json([
                    'error' => "Cannot accept more applications. Maximum of {$maxAwardees} businesses can be funded already approved)."
                ], 422);
            }


            $pitch->status = 'approved';

            if ($program->program_type === 'single_round') {
                // Single round: approve + award immediately
                $pitch->status = 'awarded'; $pitch->awarded_at = now();
                $pitch->round_status = 'advanced';


            } else {
                // Multi-round: approve + advance to Round 2
                $nextRound = ProgramRound::where('program_id', $program->id)
                    ->where('round_number', $pitch->currentRound->round_number + 1)->first();

                if (!$nextRound) {
                    DB::rollBack();
                    return response()->json(['error' => 'Round 2 not found'], 422);
                }

                $pitch->current_round_id = $nextRound->id;
                $pitch->round_status = 'draft';

                // Track round history
                $this->roundHistory->closeAndCreate(
                    $pitch, $nextRound, $program->applications()->count(), 'in_progress'
                );

                $this->programNotification->send('round.advanced', [$pitch->user], [
                    'program_title' => $pitch->program->program_title, 'round_name' => $nextRound->round_name ?? 'Final Round', 'application_id' => $pitch->id,
                ]);
            }

            $pitch->save();
            DB::commit();


            // Notify applicant
            $this->programNotification->send('application.accepted',
                [$pitch->user], [
                    'program_title' => $pitch->program->program_title,
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


    public function reject(Request $request, ProgramApplication $pitch)
    {
        if($pitch->program_owner_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        try{
            $pitch->update(['status' => 'rejected', 'round_status' => 'not_selected']);

            // Notify applicant
            $this->programNotification->send('application.rejected',
                [$pitch->user], [
                    'program_title' => $pitch->program->program_title,
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
            $pitch = ProgramApplication::with('program')->where('id',$pitch_id)->first();
            $user = User::select('fname','lname')->where('id',$pitch->user_id)->first();

            $text = $user->fname.' '.$user->lname. 'Has requested funding to the Program'.$pitch->program->program_title;
            $notification = new NotificationService();
            $notification->create($pitch->program->user_id,$pitch->user_id,$text,
                'dashboard.programOrg.applications','program_fund_request');

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

    public function setPlanningMode(Request $request, ProgramApplication $application)
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

    public function awarded($program_id)
    {
        $user_id = Auth::id();

        $program = Program::find($program_id);

        if($program->user_id !== $user_id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $pitches = $program->applications()
            ->with([
                'program',
                'program_milestones'
            ])
            ->where('status', 'awarded')->get();


        $pitches = $pitches->map(function ($pitch) {
            return [
                ...$pitch->toArray(),
                'status' => [
                    'value' => $pitch->status,
                    'color' => config('status.program_application.' . $pitch->status, 'gray'),
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
            ->with(['program:program_title'])
            ->where('status', 'awarded')
            ->paginate(20);

        $pitches->getCollection()->transform(function ($pitch) {
            return [
                ...$pitch->toArray(),
                'status' => [
                    'value' => $pitch->status,
                    'color' => config('status.program_application.' . $pitch->status, 'gray'),
                ],
            ];
        });

        return response()->json(['applications' => $pitches]);
    }

    // H e l p e r s
    public function get_role()
    {
        $user = Auth::user()->load('organizationRole.role');
        $user->role = $user->organizationRole?->role?->name ?? 'super-admin';
        $user->program_owner_id = $user->organizationOwnerId();
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
