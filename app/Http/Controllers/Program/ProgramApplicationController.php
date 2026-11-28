<?php

namespace App\Http\Controllers\Program;

use App\Support\ApiContract;
use App\Support\ListShapes;

use App\Http\Controllers\Controller;
use App\Models\ApplicationRoundHistory;
use App\Models\Auth\User;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\ProgramWatchlist;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Models\Programs\Rounds\RoundRequiredDocument;
use App\Service\Program\AwardedApplicationList;
use App\Service\Program\OwnerAwardedList;
use App\Service\Program\MyApplicationList;
use App\Service\Program\ProgramAccess;
use App\Service\Program\RoundMatchScore;
use App\Service\Program\KnockoutEvaluator;
use App\Service\Program\RoundHelperService;
use App\Service\Program\RoundHistoryService;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        (new ProgramAccess())->authorizeProgramStaff(auth()->user(), Program::findOrFail($program_id));

        $pitches = ProgramApplication::with([
            'program_milestones',
            'program:id,use_match_score,match_score_limit',
        ])
            ->where('program_id', $program_id)->latest()->get();


        $apps = $pitches->map(function ($pitch) {
            $pitch->setAttribute('below_threshold', $this->isBelowMatchThreshold($pitch));
            $pitch->makeVisible('score_breakdown');
            return [
                ...$pitch->toArray(),
                'status' => \App\Support\ApiContract::statusMeta('program_application', $pitch->status),
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
                'program:id,user_id,organization_id,program_title,program_type,status,currency,total_rounds,funding_per_business,use_match_score,match_score_limit',
                'currentRound',
            ])->findOrFail($id);

            (new ProgramAccess())->authorizeApplication(auth()->user(), $pitch);
            if (! (new ProgramAccess())->isApplicant(auth()->user(), $pitch)) {
                $pitch->setAttribute('below_threshold', $this->isBelowMatchThreshold($pitch));
                $pitch->makeVisible('score_breakdown');
            }

            $roundData = null;
            $roundService = new RoundHelperService();
            if ($pitch->current_round_id) {
                $roundData = $roundService->buildRoundData($pitch->currentRound, $pitch);
            }
            $myReviewAssignment = ReviewerApplicationAssignment::where('application_id', $pitch->id)
                ->where('reviewer_id', Auth::id())
                ->latest('id')
                ->first();

            return response()->json([
                'pitch' => [
                    ...$pitch->toArray(),
                    ...$pitch->applicantProfile(),
                    'my_review_assignment' => $myReviewAssignment,
                    'status' => \App\Support\ApiContract::statusMeta('program_application', $pitch->status),
                    'round_status' => \App\Support\ApiContract::statusMeta('program_application_round', $pitch->round_status),
                    'funding_setup_status' => \App\Support\ApiContract::statusMeta('funding_setup', $pitch->funding_setup_status),
                    'knockout_status' => \App\Support\ApiContract::statusMeta('knockout', $pitch->knockout_status),
                ],
                'round_data' => $roundData,
            ]);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e; // handled globally as a 403

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e; // handled globally as a standard 404 not_found response

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }



    // fetch pitches for all types of user
    public function myPitches(Request $request)
    {
        $match_score = 0;
        $user = $this->get_role();

        // My Program Applications table: a page with search / filters / sort / export; no params = the full list below
        if (ListShapes::wanted($request)) {
            return (new MyApplicationList($user))->respond($request);
        }

        if (in_array($user->role, ['editor', 'viewer', 'admin', 'super_admin'])) {
            $watchlistPitchIds = ProgramWatchlist::where('program_owner_id', $user->program_owner_id)->pluck('pitch_id')->toArray();
            // ISSUE-38: applications to any program of the organization, whoever on the team created it
            $pitches = ProgramApplication::with(['program', 'currentRound', 'program_milestones'])
                ->whereIn('program_id', Program::ofOrganization($user)->select('id'))
                ->whereNot('status', 'draft')
                ->latest()->get();
        }
        else if ($user->user_type_id == 4){
            $watchlistPitchIds = ProgramWatchlist::where('program_owner_id', $user->id)->pluck('pitch_id')->toArray();
            $pitches = ProgramApplication::with(['program', 'currentRound', 'program_milestones'])
                ->whereIn('program_id', Program::ofOrganization($user)->select('id'))
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

        // Summary, worked out from the raw status strings BEFORE they are turned into {value, color} below.
        // Drafts are not applications yet, so they are left out of every count.
        $statuses = $pitches->pluck('status')->map(fn ($s) => strtolower((string) $s));
        $submitted = $pitches->filter(fn ($p) => strtolower((string) $p->status) !== 'draft');
        $countIn = fn (array $values) => $statuses->filter(fn ($s) => in_array($s, $values, true))->count();

        $pitch_count = $submitted->count();
        $pitch_count_accept = $countIn(['approved', 'awarded', 'in_monitoring', 'completed']);
        $pitch_count_rejected = $countIn(['rejected']);
        $pitch_count_pending = $countIn(['pending', 'submitted', 'under_review', 'in_review']);
        $pitch_count_draft = $countIn(['draft']);
        $scores = $submitted->pluck('match_score')->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v) => (float) $v);
        $avg_match_score = $scores->count() ? round($scores->avg(), 2) : 0;
        $decided = $pitch_count_accept + $pitch_count_rejected;
        // Share of decided applications that were accepted
        $accept_rate = $decided ? round(($pitch_count_accept / $decided) * 100, 2) : 0;

        foreach ($pitches as $pitch){
            $pitch->in_my_watchlist = in_array($pitch->id, $watchlistPitchIds);

            $status = $pitch->status; // store original value first

            $pitch->status = \App\Support\ApiContract::statusMeta('program_application', $status);
        }

        return response()->json([
            'pitches' => $pitches,
            'avg_match_score' => $avg_match_score,
            'accept_rate' => $accept_rate,
            'pitch_count' => $pitch_count,
            'pitch_count_pending' => $pitch_count_pending,
            'pitch_count_accepted' => $pitch_count_accept,
            'pitch_count_rejected' => $pitch_count_rejected,
            'pitch_count_draft' => $pitch_count_draft,
            'scored_count' => $scores->count(),
            'best_match_score' => $scores->count() ? round($scores->max(), 2) : null,
            'total_requested' => round((float) $submitted->sum('total_amount_requested'), 2),
            'total_awarded' => round((float) $submitted->sum('awarded_amount'), 2),
        ]);
    }

    // added by owen - get program details for SME application modal (round 1 questions, required docs, etc.)
    public function application_info($program_id)
    {
        try{
            $program = Program::with([
                'wallet'
            ])->withCount('liked')->find($program_id);

            //  Handle not found: a draft or hidden program answers like a missing one, except to its own team
            if (!$program || (!\App\Service\Program\ProgramAccess::owns($program) && !$program->isListedForApplicants())) {
                return response()->json([
                    'message' => 'Program not found'
                ], 404);
            }

            $round1 = $program->rounds()->with(['questions', 'knockoutQuestions'])->orderBy('id')->first();;
            if ($round1) {
                foreach (['questions', 'knockoutQuestions'] as $relation) {
                    if ($round1->relationLoaded($relation)) {
                        $round1->setRelation($relation, $round1->{$relation}->map(
                            fn (RoundCustomQuestion $question) => $question->applicantView()
                        ));
                    }
                }
            }

            //  Better naming (no overwrite)
            if (Auth::check()) {
                $program->liked = $program->liked()
                    ->where('user_id', Auth::id())
                    ->exists();
            }

            $closedReason = $program->applicationsClosedReason();

            $programData = [
                ...$program->toArray(),
                'accepting_applications' => $closedReason === null,
                'applications_closed_reason' => $closedReason,
                'status' => \App\Support\ApiContract::statusMeta('program', $program->status),
            ];

            $roundData = $round1 ? [
                ...$round1->toArray(),
                'status' => \App\Support\ApiContract::statusMeta('program_round', $round1->status),
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
        // The applicant, the program's team and its Business Coaches only (outside the try so a refusal stays a 403)
        (new \App\Service\Program\ProgramAccess())->authorizeApplication(auth()->user(), $application);
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
            $history->each(function (ApplicationRoundHistory $record): void {
                if ($record->round && $record->round->relationLoaded('questions')) {
                    $record->round->setRelation('questions', $record->round->questions->map(
                        fn (RoundCustomQuestion $question) => $question->applicantView()
                    ));
                }
            });
            return response()->json([ 'rounds' => $history ]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function roundsHistoryByRound(ProgramRound  $round)
    {
        // A round's history is for the program's own team and the Business Coaches assigned to that round
        (new \App\Service\Program\ProgramAccess())->authorizeRound(auth()->user(), $round);
        try {
            $history = ApplicationRoundHistory::where('round_id', $round->id)
                ->with([
                    'round.reviewers',
                    'round.questions',
                    'round.scores',
                    'round.uploadedDocuments',
                ])->orderBy('round_number')
                ->get();
            $history->each(function (ApplicationRoundHistory $record): void {
                if ($record->round && $record->round->relationLoaded('questions')) {
                    $record->round->setRelation('questions', $record->round->questions->map(
                        fn (RoundCustomQuestion $question) => $question->applicantView()
                    ));
                }
            });
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
        // This endpoint accepts guests, so resolve an optional applicant from Sanctum
        // instead of relying on the default web guard.
        $applicantId = auth('sanctum')->id();
        DB::beginTransaction();

        try{
           

            // Closed once the first round is over and applicants have moved to the next round
            if ($closedReason = $program->applicationsClosedReason()) {
                return response()->json(['message' => $closedReason], 422);
            }

            $validated = $request->validate([
                // No business is needed to apply: the name shown for the application falls back to the applicant's own name
                'startup_name' => 'nullable|string|max:255',
                'business_id' => 'nullable|integer',
                'contact_person_name' => 'required|string|max:100',
                'contact_person_email' => 'required|email|max:100',
                'sector' => 'nullable|string|max:100',
                'headquarters_location' => 'nullable|string|max:255',
                'total_amount_requested' => 'required|numeric|min:0|max:' . $program->funding_per_business,
                'stage' => 'nullable|string|max:100',
                'revenue_last_12_months' => 'nullable|numeric|min:0',
                'team_experience_avg_years' => 'nullable|integer|min:0',
                'traction_kpis' => 'nullable|string',
                'pitch_deck_file' => 'nullable|file|mimes:pdf,ppt,pptx|max:20480',
                'pitch_video' => 'nullable|url',
                'business_plan_file' => 'nullable|file|mimes:pdf,doc,docx|max:20480',
                'social_impact_areas' => 'nullable|array',
                'round_answers' => 'nullable|array',
                'round_answers.*.question_id' => 'required_with:round_answers|integer|exists:round_custom_questions,id',
                'round_answers.*.response' => 'nullable',
                'round_answers.*.files' => 'nullable|array|max:'.RoundMatchScore::MAX_FILES_PER_QUESTION,
                'round_answers.*.files.*' => 'file|max:20480',
                'round_documents' => 'nullable|array',
                'round_documents.*.document_type' => 'required_with:round_documents|string|max:100',
                'round_documents.*.file' => 'required_with:round_documents|file|max:20480',
            ]);

            // A business is optional. When one is named it must be the applicant's own (a guest cannot name one).
            if (filled($validated['business_id'] ?? null)) {
                $ownsBusiness = $applicantId !== null && \App\Models\Business\Listing::whereKey($validated['business_id'])
                    ->where('user_id', $applicantId)->exists();
                if (! $ownsBusiness) {
                    throw ValidationException::withMessages(['business_id' => ['This business does not belong to you.']]);
                }
            } else {
                unset($validated['business_id']);
            }

            // Auto-set fields
            $validated['program_id'] = $program->id;  $validated['program_owner_id'] = $program->user_id;
            $validated['user_id'] = $applicantId; // empty for a guest: no account is needed to apply
            $validated['email'] = $validated['contact_person_email'];
            $validated['startup_name'] = filled($validated['startup_name'] ?? null) ? trim($validated['startup_name']) : trim($validated['contact_person_name']);
            $validated['status'] = 'pending';
            // This endpoint submits immediately; it is not a draft-saving endpoint.
            // Keep the round state consistent with the successful response and
            // overall application status so reviewers and AI can see it.
            $validated['round_status'] = 'submitted';
            // New applications always enter the first round; later rounds only take advanced applicants
            $validated['current_round_id'] = $program->applicationRound()?->id;

            if( !$validated['current_round_id']) {
                return response()->json(['message' => 'No round exists for the program.'], 422);
            }

            $roundAnswers = collect($request->input('round_answers', []));
            $roundQuestions = RoundCustomQuestion::where('round_id', $validated['current_round_id'])->get();
            $answersById = $roundAnswers->keyBy('question_id');
            $roundMatchScore = app(RoundMatchScore::class);
            $missingRequired = $roundQuestions->filter(function (RoundCustomQuestion $question) use ($roundAnswers, $answersById, $request, $roundMatchScore) {
                if (! $question->is_required) {
                    return false;
                }

                $index = $roundAnswers->search(fn ($answer) => (int) ($answer['question_id'] ?? 0) === (int) $question->id);
                $answer = $answersById->get($question->id, []);
                $file = $index === false
                    ? null
                    : ($request->file("round_answers.{$index}.response") ?? $request->file("round_answers.{$index}.file")
                        ?? (($request->file("round_answers.{$index}.files") ?? [])[0] ?? null));
                $response = $answer['response'] ?? null;
                if ($response instanceof \Illuminate\Http\UploadedFile) {
                    $file = $response;
                    $response = null;
                }
                if ($roundMatchScore->answerIsProvided($question->question_type, $response, (bool) $file)) {
                    return false;
                }
                return true;
            });
            if ($missingRequired->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'round_answers' => ['All required round questions must be answered. Missing question IDs: ' . $missingRequired->pluck('id')->implode(', ') . '.'],
                ]);
            }

            // Knockout answer check
            $knockoutQuestionIds = RoundCustomQuestion::where('round_id', $validated['current_round_id'])
                ->whereIn('question_type', ['knockout', 'knockout_yes_no'])->pluck('id');

            $inputAnswers = $roundAnswers;
            $answeredIds = $inputAnswers->filter(fn($a) => !empty($a['response']))->pluck('question_id');
            $missing = $knockoutQuestionIds->diff($answeredIds);

            if ($missing->isNotEmpty()) {
                return response()->json([
                    'message' => 'All knockout questions must be answered.', 'missing_question_ids' => $missing->values(),
                    'round_answers_raw' => $request->input('round_answers'),
                    'request' => $request->round_answers,
                ], 422);
            }

            // Lock through the existence check and insert so parallel submissions
            // for the same program cannot both pass the check.
            Program::whereKey($program->id)->lockForUpdate()->first();
            if ($applicantId !== null && ProgramApplication::where('program_id', $program->id)->where('user_id', $applicantId)->exists()) {
                DB::rollBack();
                return response()->json(['message' => 'You already have an active application for this program.'], 409);
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

            // Sector and place are optional. The columns cannot be empty, so "nothing" is stored as an empty string, and older apps
            // that still send the words "Not specified" are not allowed to store them as if they were an answer.
            foreach (['sector', 'headquarters_location'] as $optional) {
                $given = trim((string) ($validated[$optional] ?? ''));
                $validated[$optional] = strcasecmp($given, 'Not specified') === 0 ? '' : $given;
            }

            $application = ProgramApplication::create(
                Arr::except($validated, ['round_answers', 'round_documents'])
            );

            $doc_path = "files/programApps/{$program->id}/rounds/{$application->current_round_id}";

            // Save round document uploads & answers
            $this->handleRoundData($request, $application, $doc_path, $uploadedFiles, $validated['current_round_id']);
            ApplicationRoundHistory::where('application_id', $application->id)
                ->where('round_id', $application->current_round_id)
                ->update(['submitted_at' => now()]);
            $scoreResult = app(RoundMatchScore::class)->recalculateApplication($application);

            // The program's minimum match score is checked here, on the score the server just worked out from the answers
            // (never one sent by the browser), so it cannot be bypassed by calling the API directly. Rolls everything back.
            $minimum = $program->match_score_limit;
            if ($program->use_match_score && $minimum !== null && $scoreResult['match_score'] !== null && (float) $scoreResult['match_score'] < (float) $minimum) {
                throw ValidationException::withMessages([
                    'match_score' => ["Your match score of {$scoreResult['match_score']}% is below this program's minimum of {$minimum}%, so the application cannot be submitted. Improve your answers and try again."],
                ]);
            }

            // check if knockout questions answered
            $ko_questionIds = RoundCustomQuestion::where('round_id', $application->current_round_id)
                ->whereIn('question_type', ['knockout', 'knockout_yes_no'])
                ->pluck('id')->toArray();

            $answered = ApplicationRoundResponse::where('application_id', $application->id)
                ->whereIn('question_id', $ko_questionIds)->count();

            if($answered < count($ko_questionIds)) {
                return response()->json(['message' => 'All knockout questions must be answered.'], 422);
            }

            // Knockout answers decide eligibility now: a "fail" answer marks the application failed (the applicant is told
            // "An eligibility answer does not qualify"), and it is not sent to a Business Coach or the AI review
            (new KnockoutEvaluator())->evaluate($application);
            $knockedOut = $application->fresh()->knockout_status === 'failed';

            // Automatically route new applications when the round uses an automatic method.
            $assignedCoachId = null;
            if (! $knockedOut) {
                $assignedCoachId = $this->autoAssignNewApplication($application, ProgramRound::find($application->current_round_id));
            }

            $text = 'You have a new application pitch.';
            // straight to this applicant (multi-round programs open the round they applied in)
            $pitchLink = $this->programNotification->orgApplicantLink([
                'program_id'     => $program->id,
                'application_id' => $application->id,
                'round_id'       => $program->grant_type === 'multi_round' ? $application->current_round_id : null,
            ]);
            // C12: the whole program team (organization owner, creator, team roles), not only who created the program
            foreach (\App\Service\Program\ProgramTeam::members($program) as $member) {
                $this->notification->create($member->id, $application->user_id ?? $program->user_id, $text, $pitchLink, 'program');
            }

            // Commit changes
            DB::commit();

            // The Business Coach it was routed to is told, as when the owner assigns applications by hand
            if ($assignedCoachId) try {
                $coachRound = ProgramRound::find($application->current_round_id);
                $this->programNotification->send('round.scoring_assigned', [User::find($assignedCoachId)], [
                    'program_id' => $program->id, 'program_title' => $program->program_title,
                    'round_name' => $coachRound?->round_name, 'round_id' => $coachRound?->id,
                    'max_apps' => 1, 'assignment' => 'applications',
                ]);
            } catch (\Throwable $e) {
                ErrorLogService::report($e, ['application_id' => $application->id]);
            }

            // AI-assisted / AI-only rounds: queue the review (a no-op for manual rounds)
            if (! $knockedOut) \App\Service\AiReview\AiReviewDispatcher::safely($application);

            // E M A I L
            // The route is public (guests can apply), so the signed-in applicant comes from the token, not the web guard
            $sme = auth('sanctum')->user();
            $smeName = $sme ? trim($sme->first_name . ' ' . $sme->last_name) : $application->contact_person_name;
            $go_email = User::where('id', $program->user_id)->value('email');

            // The email button opens this exact application, same as the in-app notification
            $info=[ 'program'=>$program->program_title, 'SME'=>$smeName,
                    'action_url' => \App\Service\Notification\EmailLink::url($pitchLink, $go_email), 'action_label' => 'Review Pitch' ];
            $this->emailService->send('New Program Pitch', 'opportunities.program_pitch', $info, $go_email);

            if (!$application->user_id) {
                try {
                    $this->emailService->send('Application submitted successfully', 'programs.guest_application_received', [
                        'program_title' => $program->program_title,
                        'startup_name' => $application->startup_name,
                        'application_uid' => $application->uid,
                        'tracking_url' => \App\Service\Program\ApplicationTrackingLink::url($application),
                        // An email that already belongs to an account is told to sign in, not to create one
                        'has_account' => \App\Models\Auth\User::whereRaw('LOWER(email) = ?', [strtolower((string) $application->email)])->exists(),
                    ], $application->email);
                } catch (\Throwable $e) {
                    ErrorLogService::report($e, ['application_id' => $application->id]);
                }
            }

            // And a thank-you to the applicant, from the organization (best effort: never fails the submission)
            if ($sme) try {
                $round = $program->applicationRound();
                $this->programNotification->send('application.received', [$sme], [
                    'program_id' => $program->id,
                    'program_title' => $program->program_title,
                    'application_id' => $application->id,
                    'business_name' => $application->startup_name,
                    'round_name' => $round?->round_name,
                    'organization_name' => $program->owner?->display_name,
                    'announcement_date' => $round?->announcement_date?->format('j F Y'),
                    'submitted_at' => now()->format('j M Y, H:i'),
                    'recipientName' => $sme->first_name ?: $sme->display_name,
                ]);
            } catch (\Throwable $e) {
                ErrorLogService::report($e, ['application_id' => $application->id]);
            }

            return response()->json([
                'message' => 'Application submitted successfully.',
                'data' => $application->fresh(),
                'uid' => $application->uid,
            ], 201);
        }
        catch (ValidationException $e) {
            // Nothing from a refused application is kept: not the rows, not the files already saved
            DB::rollBack();
            foreach ($uploadedFiles as $file) {
                if ($file && file_exists(public_path($file))) {
                    unlink(public_path($file));
                }
            }
            $errors = $e->errors();
            return response()->json([
                'message' => isset($errors['match_score']) ? $errors['match_score'][0] : 'Validation failed.',
                'errors'  => $errors,
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

        // ISSUE-23: from round 2 on, an applicant who advanced is "approved"; Approve there means the same as the round's
        // Advance (to the next round, or awarded on the final round), so it is handed to that action
        if ($pitch->status === 'approved' && (int) ($pitch->currentRound?->round_number ?? 1) > 1) {
            return app(\App\Http\Controllers\Program\Rounds\ProgramRoundController::class)->advanceManual($request, $pitch);
        }

        // Check application status
        if ($pitch->status !== 'pending') {
            return response()->json([
                'error' => 'Can only accept pending applications. Current status: ' . $pitch->status
            ], 422);
        }

        DB::beginTransaction();
        try{
            $totalMilestones = $pitch->program_milestones()->sum('amount');
            $awardedAmount = $totalMilestones;

            // NEW: Check if max awardees limit reached
            $program = $pitch->program;
            $awardedCount = ProgramApplication::where('program_id', $program->id)->whereIn('status', ['awarded', 'in_monitoring', 'completed'])->count();
            $maxAwardees = $program->max_awardees ?? floor($program->total_program_amount / $program->funding_per_business);

            if ($awardedCount >= $maxAwardees) {
                return response()->json([
                    'error' => "Cannot accept more applications. Maximum of {$maxAwardees} businesses can be funded already approved)."
                ], 422);
            }


            $pitch->status = 'approved';

            if ($program->program_type === 'single_round') {
                // Single round: approve + award immediately
                $pitch->status = 'awarded'; //$pitch->awarded_at = now();
                $pitch->round_status = 'advanced';
                // The round's history says "awarded" too: Finalize Round counts decisions from it
                $this->roundHistory->update($pitch, 'awarded', 'Approved');


            } else {
                // Multi-round: approve + advance to Round 2
                $nextRound = ProgramRound::where('program_id', $program->id)
                    ->where('round_number', $pitch->currentRound->round_number + 1)->first();

                if (!$nextRound) {
                    DB::rollBack();
                    return response()->json(['error' => 'Round 2 not found'], 422);
                }

                // Close the round they are leaving in the history before the application moves: the history is found by the
                // application's current round, so moving first would leave round 1 looking undecided forever
                $this->roundHistory->closeAndCreate(
                    $pitch, $nextRound, $program->applications()->count(), 'in_progress'
                );

                $pitch->current_round_id = $nextRound->id;
                $pitch->round_status = 'draft';
            }

            $pitch->save();
            DB::commit();

            // One email, sent once the change is saved: moved on to the next round, or accepted (single round)
            if (isset($nextRound)) {
                $this->programNotification->send('round.advanced', [$pitch->user], [
                    'program_title' => $pitch->program->program_title, 'round_name' => $nextRound->round_name ?? 'Final Round',
                    'program_id' => $pitch->program_id, 'application_id' => $pitch->id,
                ]);
            } else {
                $this->programNotification->send('application.accepted',
                    [$pitch->user], [
                        'program_title' => $pitch->program->program_title,
                        'business_name' => $pitch->business?->name ?? $pitch->startup_name,
                        'program_id' => $pitch->program_id,
                        'application_id' => $pitch->id,
                    ]
                );
            }

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
                    'business_name' => $pitch->business?->name ?? $pitch->startup_name,
                    'program_id' => $pitch->program_id,
                    'application_id' => $pitch->id,
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
            $pitch = ProgramApplication::with('program')->findOrFail($pitch_id);
            if (!(new ProgramAccess())->isApplicant(auth()->user(), $pitch)) {
                throw new \Illuminate\Auth\Access\AuthorizationException(ProgramAccess::DENIED);
            }
            $user = User::select('first_name','last_name')->where('id',$pitch->user_id)->first();

            $text = trim($user->first_name.' '.$user->last_name).' has requested funding from the program '.$pitch->program->program_title.'.';
            $notification = new NotificationService();
            $link = $this->programNotification->orgApplicantLink([
                'program_id'     => $pitch->program_id,
                'application_id' => $pitch->id,
                'round_id'       => $pitch->program->grant_type === 'multi_round' ? $pitch->current_round_id : null,
            ]);
            // C12: the whole program team, not only who created the program
            foreach (\App\Service\Program\ProgramTeam::members($pitch->program) as $member) {
                $notification->create($member->id, $pitch->user_id, $text, $link, 'program_fund_request');
            }

            //MAIL
            return response()->json(['message' => 'Fund Requested.'], 200);
        }
        catch (\Illuminate\Auth\Access\AuthorizationException | \Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
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

            // The planning mode decides whether the applicant may edit their own milestones (hybrid), so only the
            // program's own team may set it, never the applicant (or anyone else)
            if (! ProgramAccess::owns($application->program)) {
                return response()->json(['message' => 'Unauthorized.'], 403);
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
        $program = Program::find($program_id);

        // The program's owner and their organization's program team (ProgramAccess::owns), as on the Deal Room list
        if (! $program || ! ProgramAccess::owns($program)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $pitches = $program->applications()
            ->with([
                'program',
                'program_milestones'
            ])
            ->whereIn('status', ['awarded', 'in_monitoring', 'completed'])->get();


        $pitches = $pitches->map(function ($pitch) {
            return [
                ...$pitch->toArray(),
                'status' => \App\Support\ApiContract::statusMeta('program_application', $pitch->status),
            ];
        });

        return response()->json(['applications' => $pitches]);
    }

    /**
     * GET programs/owner/awarded-applications: the awarded applications of every program the signed-in account may run
     * (ProgramAccess::owns: their own and, for a program-team member, the organization's; the programs `awarded` answers
     * for), searched, filtered, sorted and paged on the server for the Program Deal Room.
     */
    public function ownerAwarded(Request $request)
    {
        $user = Auth::user();
        if ((int) $user->user_type_id !== 4) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return (new OwnerAwardedList($user))->respond($request);
    }

    /**
     * The signed-in entrepreneur's awarded applications.
     * GET /programs/my-applications/awarded
     * Every one of them by default (and with export=1); ?page= / ?per_page= returns one Laravel page under
     * `applications` instead, as before.
     */
    public function smeAwarded(Request $request)
    {
        $user= Auth::user();

        if($user->user_type_id !== 1) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Every awarded application (or Laravel's page of them with page / per_page), searched, filtered and sorted on
        // the server; `data` carries the list envelope (items, pagination, facets, stats)
        return (new AwardedApplicationList((int) $user->id))->respond($request);
    }

    // H e l p e r s
    public function get_role()
    {
        $user = Auth::user()->load('organizationRole.role');
        $user->role = $user->organizationRole?->role?->name ?? 'super-admin';
        $user->program_owner_id = $user->organizationOwnerId();
        return $user;
    }

    private function isBelowMatchThreshold(ProgramApplication $application): bool
    {
        $program = $application->program;
        return $program
            && $program->use_match_score
            && $program->match_score_limit !== null
            && $application->match_score !== null
            && (float) $application->match_score < (int) $program->match_score_limit;
    }

    private function handleRoundData($request, $application, $path, &$uploadedFiles, $current_round_id)
    {
        // Entered on a round
        $this->roundHistory->create($current_round_id, $application->id, 1, 'in_progress');

        // Save answers
        if ($request->has('round_answers')) {
            $inputAnswers = collect($request->input('round_answers'));

            $answers = $inputAnswers->map(function ($answer, $index) use ($request, $application, $current_round_id, $path, &$uploadedFiles) {
                $question = RoundCustomQuestion::where('id', $answer['question_id'])
                    ->where('round_id', $current_round_id)
                    ->first();
                if (! $question) {
                    throw ValidationException::withMessages([
                        "round_answers.{$index}.question_id" => ['The selected question does not belong to the application round.'],
                    ]);
                }
                // One file (response / file) or several (files[]): a file-upload question takes as many as its owner allowed
                $files = [];
                foreach (["round_answers.$index.response", "round_answers.$index.file"] as $key) {
                    if ($single = $request->file($key)) {
                        $files[] = $single;
                    }
                }
                foreach ((array) $request->file("round_answers.$index.files") as $many) {
                    $files[] = $many;
                }
                $file = $files[0] ?? null;
                $filePath = null;
                $filePaths = [];
                if ($files) {
                    if ($question->question_type !== 'file_upload') {
                        throw ValidationException::withMessages([
                            "round_answers.{$index}.response" => ['Files may only be submitted for file upload questions.'],
                        ]);
                    }
                    app(RoundMatchScore::class)->validateFileSet($files, $question, "round_answers.{$index}.response");
                    foreach ($files as $oneFile) {
                        $saved = $this->fileUpload->saveFile($oneFile, $path);
                        $uploadedFiles[] = $saved;
                        $filePaths[] = $saved;
                    }
                    $filePath = $filePaths[0];
                }

                $response = $answer['response'] ?? null;
                if ($question->question_type === 'file_upload' && ! $file && is_string($response) && trim($response) !== '') {
                    throw ValidationException::withMessages([
                        "round_answers.{$index}.response" => ['A file upload question requires an uploaded file.'],
                    ]);
                }
                if ($response !== null && ! is_string($response)) {
                    throw ValidationException::withMessages([
                        "round_answers.{$index}.response" => ['Answers must be submitted as a string; encode structured answers as JSON.'],
                    ]);
                }

                return [
                    'application_id' => $application->id,
                    'round_id' => $current_round_id,
                    'question_id' => $answer['question_id'],
                    'response' => $response,
                    'file_path' => $filePath,
                    'file_paths' => $filePaths ? json_encode($filePaths) : null,
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

                    // The bytes must be what the name says (a photo renamed to .pdf is refused, with its name)
                    if ($refusal = app(RoundMatchScore::class)->contentMismatch($file) ?? app(\App\Service\Validation\SpamImageChecker::class)->refusal($file)) {
                        throw ValidationException::withMessages(["round_documents.$index.file" => [$refusal]]);
                    }

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

    //_______________ Helper _______________________

    /** Routes a new application to a Business Coach; returns the coach's user id when one was chosen. */
    private function autoAssignNewApplication(ProgramApplication $application, ?ProgramRound $round): ?int
    {
        $context = [
            'application_id' => $application->id,
            'application_current_round_id' => $application->current_round_id,
            'round_id' => $round?->id,
            'assignment_type' => $round?->assignment_type,
            'assignment_method' => $round?->assignment_method,
        ];
        Log::info('Reviewer auto-assignment: evaluating new application.', $context);

        if (!$round) {
            Log::info('Reviewer auto-assignment: skipped because the application round was not found.', $context);
            return null;
        }

        if ($round->assignment_type === 'owner_only'
            || !in_array($round->assignment_method, ['round_robin', 'load_balanced'], true)) {
            Log::info('Reviewer auto-assignment: skipped because round settings do not enable automatic assignment.', $context);
            return null;
        }

        $acceptedReviewers = DB::table('round_reviewers')
            ->where('round_id', $round->id)
            ->where('acceptance_status', 'accepted')
            ->orderBy('queue_position')
            ->get();

        Log::info('Reviewer auto-assignment: accepted reviewers loaded.', $context + [
            'accepted_reviewer_ids' => $acceptedReviewers->pluck('user_id')->all(),
            'accepted_reviewer_count' => $acceptedReviewers->count(),
        ]);

        if ($acceptedReviewers->isEmpty()) {
            Log::info('Reviewer auto-assignment: skipped because no reviewer has accepted.', $context);
            return null;
        }

        // "Match Business Coaches by expertise": only the coaches whose expertise fits the application's sector
        // (everyone when nobody fits), then the least loaded of them as usual
        if ($round->match_expertise) {
            $acceptedReviewers = app(\App\Service\Program\ExpertiseMatcher::class)->candidates($acceptedReviewers, $application);
            Log::info('Reviewer auto-assignment: candidates matched by expertise.', $context + [
                'candidate_reviewer_ids' => $acceptedReviewers->pluck('user_id')->all(),
            ]);
        }

        // Route each new application to the currently least-loaded accepted reviewer.
        // Queue position breaks ties, keeping the result deterministic.
        $reviewerLoads = ReviewerApplicationAssignment::query()
            ->where('round_id', $round->id)
            ->whereIn('reviewer_id', $acceptedReviewers->pluck('user_id'))
            ->selectRaw('reviewer_id, COUNT(*) as assignment_count')
            ->groupBy('reviewer_id')
            ->pluck('assignment_count', 'reviewer_id');

        $loads = $acceptedReviewers->mapWithKeys(fn ($candidate) => [
            $candidate->user_id => (int) $reviewerLoads->get($candidate->user_id, 0),
        ]);
        Log::info('Reviewer auto-assignment: reviewer assignment counts loaded.', $context + [
            'reviewer_loads' => $loads->all(),
        ]);

        $reviewer = $acceptedReviewers->sortBy(
            fn ($candidate) => (int) $reviewerLoads->get($candidate->user_id, 0)
        )->first();

        if (!$reviewer) {
            Log::warning('Reviewer auto-assignment: no reviewer was selected.', $context);
            return null;
        }

        Log::info('Reviewer auto-assignment: reviewer selected.', $context + [
            'selected_reviewer_id' => $reviewer->user_id,
            'selected_reviewer_load' => $loads->get($reviewer->user_id),
            'selected_reviewer_queue_position' => $reviewer->queue_position,
        ]);

        try {
            $assignment = ReviewerApplicationAssignment::create([
                'round_id'       => $round->id,
                'reviewer_id'    => $reviewer->user_id,
                'application_id' => $application->id,
                'status'         => 'assigned',
                'assigned_at'    => now(),
            ]);

            $application->update([
                'assigned_reviewer_id' => $reviewer->user_id,
                'round_status' => 'assigned',
            ]);

            Log::info('Reviewer auto-assignment: assignment created and application updated.', $context + [
                'assignment_id' => $assignment->id,
                'selected_reviewer_id' => $reviewer->user_id,
                'application_round_status' => $application->fresh()->round_status,
            ]);

            return (int) $reviewer->user_id;
        } catch (\Throwable $e) {
            Log::error('Reviewer auto-assignment: assignment persistence failed.', $context + [
                'selected_reviewer_id' => $reviewer->user_id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

}
