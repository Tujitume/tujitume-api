<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Capital\CapitalProfile;
use App\Models\Communication\Messages;
use App\Models\Finance\BalanceLog;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\ProgramWallet;
use App\Models\Programs\ProgramWatchlist;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Models\Services\ServiceBooking;
use App\Models\Shared\Watchlist;
use App\Service\Account\AccountDeletionEligibilityService;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CheckoutAmountCalculator;
use App\Service\File\ImageCompressor;
use App\Service\File\ImageUploadService;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\NotificationService;
use App\Service\Organization\TeamMemberNotifier;
use App\Service\Program\ApplicantPreviews;
use App\Service\Program\OwnerProgramList;
use App\Support\ProgramSchedule;
use App\Support\ApiContract;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Response;
use Session;
use Stripe\StripeClient;

class ProgramController extends Controller
{
    /**
     * Display a listing of programs.
     */
    protected $Client;

    protected $balance;

    protected $checkoutCalculator;

    public function __construct(StripeClient $client)
    {
        parent::__construct();
        $this->Client = $client;
        $this->balance = new BalanceService();
        $this->checkoutCalculator = new CheckoutAmountCalculator();
    }

    public function get_role()
    {
        $user = Auth::user()->load('organizationRole.role');
        $user->role = $user->organizationRole?->role?->name ?? 'super-admin';
        // The organization owner's id (the user's own id when they are the owner), whose data a team member works on
        $user->program_owner_id = $user->organizationOwnerId();

        return $user;
    }

    /** Add a small, real applicant preview to owner program lists without loading every application model. */
    private function attachApplicantPreviews($programs): void
    {
        ApplicantPreviews::attach($programs);
    }

    // C O R E  Methods
    public function index()
    {
        try{
            if(Auth::check()){
                $user_id = Auth::id();
                $user = User::select('user_type_id','id')->where('id',$user_id)->first();
                if($user->user_type_id == 4){  //Program
                    $user = $this->get_role();

                    // An internal Business Coach (internal_reviewer) may view the organization's programs too (OrganizationPermission: view, review)
                    if (in_array($user->role, ['editor', 'viewer', 'admin', 'super_admin', 'internal_reviewer'])) {
                        $query = Program::withCount(['liked', 'applications'])->ofOrganization($user); // ISSUE-38: every program of the organization
                        $watchlistProgramIds = Watchlist::where('user_id', $user->program_owner_id)->pluck('org_id')->toArray();
                    }
                    else{
                        $query = Program::with('wallet')->withCount(['liked', 'applications'])
                            ->where('user_id',$user->id);
                        $watchlistProgramIds = Watchlist::where('user_id', $user->id)->pluck('org_id')->toArray();
                    }

                    // The person's like and the owner (read for owner_website, and sent as before) in two queries, not two per program
                    $query->with('owner')
                        ->withExists(['liked as liked_by_me' => fn ($q) => $q->where('user_id', $user_id)]);

                    $list = OwnerProgramList::for($query, request());
                    [$programs, $page] = $list->fetch();
                    $this->attachApplicantPreviews($programs);

                    foreach ($programs as $program){
                        $program->pitch_count = $program->applications_count ?? 0;
                        $program->in_my_watchlist = in_array($program->id, $watchlistProgramIds);
                        $program->liked = (bool) $program->liked_by_me;
                        $program->owner_website = $program->owner->website ?? null;
                        $program->makeHidden('liked_by_me');

                        $program->status = ApiContract::statusMeta('program', $program->status);
                    }

                    if ($list->listMode()) {
                        return $list->respond('Programs fetched successfully.', $programs, $page);
                    }

                    return ApiContract::listResponse('Programs fetched successfully.', $programs, $page, ['programs']);
                }
            }
            else{
                $user_id = null;
            }

            // Public programs
            [$programs, $page] = ApiContract::fetch(
                Program::withCount(['liked', 'applications'])->where('status', '!=', 'draft'),
                request()
            );
            Program::annotateApplicationState($programs);
            foreach ($programs as $program){
                $program->pitch_count = $program->applications_count ?? 0;

                $program->liked = $user_id ? $program->liked()->where('user_id', $user_id)->exists() : false;
                $program->owner_website = $program->owner->website ?? null;
                $program->organization_name = $program->owner?->display_name;

                $program->status = ApiContract::statusMeta('program', $program->status);
            }
            return ApiContract::listResponse('Programs fetched successfully.', $programs, $page, ['programs']);
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

    public function public_programs()
    {
        try{
            $user_id = Auth::id();
            // Discover Programs asks for a page / search / filters / sort / export: the server list (same listed programs)
            if (\App\Support\ListShapes::wanted(request())) {
                return (new \App\Service\Program\ProgramDiscoverList($user_id))->respond(request());
            }
            // Applicants only ever see programs that are published or open and visible: drafts never leave the server
            [$programs, $page] = ApiContract::fetch(Program::listedForApplicants()->withCount(['liked', 'applications']), request());
            Program::annotateApplicationState($programs);
            foreach ($programs as $program){
                $program->pitch_count = $program->applications_count ?? 0;
                $program->liked = $user_id ? $program->liked()->where('user_id', $user_id)->exists() : false;
                $program->owner_website = $program->owner->website ?? null;
                $program->organization_name = $program->owner?->display_name;
                $program->status = ApiContract::statusMeta('program', $program->status);
            }
            return ApiContract::listResponse('Programs fetched successfully.', $programs, $page, ['programs']);
        }
        catch (\Exception $e) {
        ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
        return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    /**
     * Store a newly created program in storage.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try{
            // Opening and closing moments (with their time) are read in the owner's zone; their dates fill the date fields
            $timezone = ProgramSchedule::forRequest($request);
            $moments = ProgramSchedule::readMoments($request, self::PROGRAM_MOMENTS, $timezone);

            $validated = $request->validate([
                'program_title' => 'required|string|max:255',
                'program_description' => 'nullable|string|max:500',
                'program_industry_id' => 'required|exists:program_industries,id',
                'organization_id' => 'sometimes|exists:organizations,id',
                'total_program_amount' => 'required|numeric|min:0',
                'available_amount' => 'nullable|numeric|min:0',
                'funding_per_business' => 'required|numeric|min:0',
                'disbursement_type' => 'nullable|in:supplier,hybrid,beneficiary',
                'program_type' => 'required|in:single_round,multi_round',
                'total_rounds' => 'nullable|integer|min:1|max:10',
                'max_awardees' => 'nullable|integer|min:1',
                'currency' => 'nullable|string|max:3',
                'application_start_date' => 'required|date|after_or_equal:today',
                // With times, a program may open and close on the same day (the moments are compared below)
                'application_deadline' => 'required|date|' . (isset($moments['application_closes_at']) ? 'after_or_equal' : 'after') . ':application_start_date',
                'application_opens_at' => 'nullable|date',
                'application_closes_at' => 'nullable|date',
                'timezone' => 'nullable|string|max:64',
                'require_kyc_verification_to_apply' => 'nullable|boolean',
                'use_match_score' => 'nullable|boolean',
                'match_score_limit' => 'nullable|integer|min:1|max:100',
                
                'application_round' => 'nullable|array',
                'application_round.round_name' => 'nullable|string|max:100',
                'application_round.open_date' => 'nullable|date',
                'application_round.close_date' => 'nullable|date',
                'application_round.review_period_end' => 'nullable|date',
                'application_round.announcement_date' => 'nullable|date',
                'application_round.rubric_mode' => 'nullable|in:weighted,simple_total,pass_fail',
                'application_round.scoring_criteria' => 'nullable|array',
                'application_round.advancement_mode' => 'nullable|in:manual,score_threshold,fixed_quota',
                'application_round.score_threshold' => 'nullable|numeric|min:0|max:100',
                'application_round.max_advancing' => 'nullable|integer|min:1',
                'application_round.tie_breaker_rule' => 'nullable|in:allow_over_cap,secondary_metric,manual',
                'application_round.assignment_type' => 'nullable|in:owner_only,internal,external,both',
                'application_round.assignment_method' => 'nullable|in:manual,round_robin,load_balanced',
                'application_round.min_reviewers_required' => 'nullable|integer|min:1',
                'application_round.required_documents' => 'nullable|array',
                'application_round.knockout_questions' => 'nullable|array',
                'status' => 'nullable|in:draft,published,open,closed,under_review,awarded,cancelled,completed',
                'visible' => 'nullable|boolean',
                'cover_image' => array_merge(['nullable'], \App\Service\Program\ProgramMedia::COVER_RULES),
                'intro_video_url' => array_merge(['nullable'], \App\Service\Program\ProgramMedia::videoRules()),
            ]);
            // The cover is a file: it is saved after the program exists (below), never written as the column value
            unset($validated['cover_image']);

            $orgUser = User::with('organization')->find(Auth::id());
            if(!$orgUser->organization){
                return response()->json(['message' => 'Organization not found, Forbidden.'], 403);
            }

            if (($validated['program_type'] ?? 'single_round') === 'multi_round') {
                $validated['total_rounds'] = $validated['total_rounds'] ?? 1;
                if ((int) $validated['total_rounds'] < 2) {
                    throw ValidationException::withMessages([
                        'total_rounds' => ['Total rounds must be greater than 1 for multi-round programs']
                    ]);
                }
            } else {
                $validated['total_rounds'] = 1;
            }

            if ($validated['funding_per_business'] > $validated['total_program_amount']) {
                throw ValidationException::withMessages([
                    'funding_per_business' => ['Funding per business cannot exceed total program amount']
                ]);
            }

            $validated['user_id'] = Auth::id();
            $validated['organization_id'] = $orgUser->organization->id;
            $validated['status'] = $validated['status'] ?? 'draft';
            $validated['visible'] = $validated['visible'] ?? true;
            $validated['currency'] = $validated['currency'] ?? 'KES';
            $validated['use_match_score'] = $request->has('use_match_score')
                ? $request->boolean('use_match_score')
                : true;
            if (! $validated['use_match_score']) {
                $validated['match_score_limit'] = null;
            }
            $validated['available_amount'] = $validated['available_amount'] ?? $validated['total_program_amount'];
            $validated['max_awardees'] = $validated['max_awardees'] ?? (int) floor($validated['total_program_amount'] / max($validated['funding_per_business'], 1));
            $validated['program_type'] = $validated['program_type'] ?? 'single_round';

            $validated = $this->withMoments($validated, $moments, $timezone, null);

            $programAttributes = Arr::except($validated, ['application_round']);
            $program = Program::create($programAttributes);
            $roundConfig = $validated['application_round'] ?? [];
            // The first round is the application window: without its own dates it opens and closes with the program
            $roundWindow = isset($roundConfig['open_date']) || isset($roundConfig['close_date'])
                ? ['open_date' => $roundConfig['open_date'] ?? $program->application_start_date, 'close_date' => $roundConfig['close_date'] ?? $program->application_deadline]
                : ['opens_at' => $program->application_opens_at, 'closes_at' => $program->application_closes_at];
            $firstRound = $program->rounds()->create($roundWindow + [
                'round_name' => $roundConfig['round_name'] ?? 'Round 1',
                'round_number' => 1,
                'review_period_end' => $roundConfig['review_period_end'] ?? null,
                'announcement_date' => $roundConfig['announcement_date'] ?? null,
                'rubric_mode' => $roundConfig['rubric_mode'] ?? 'weighted',
                'scoring_criteria' => $roundConfig['scoring_criteria'] ?? null,
                'required_documents' => $roundConfig['required_documents'] ?? null,
                'knockout_questions' => $roundConfig['knockout_questions'] ?? null,
                'assignment_type' => $roundConfig['assignment_type'] ?? 'owner_only',
                'assignment_method' => $roundConfig['assignment_method'] ?? 'manual',
                'min_reviewers_required' => $roundConfig['min_reviewers_required'] ?? 1,
                'advancement_mode' => $roundConfig['advancement_mode'] ?? 'manual',
                'score_threshold' => $roundConfig['score_threshold'] ?? null,
                'max_advancing' => $roundConfig['max_advancing'] ?? null,
                'tie_breaker_rule' => $roundConfig['tie_breaker_rule'] ?? null,
            ]);

            $wallet['total_deposited'] = 0.00; $wallet['total_disbursed'] = 0.00;
            $wallet['total_reserved'] = 0.00;  $wallet['balance'] = 0.00;
            $wallet['status'] = 'inactive';
            $wallet['currency'] = $validated['currency'] ?? config('app.currency', 'KES');
            $wallet['program_id'] = $program->id;

            ProgramWallet::create($wallet);

            if ($request->hasFile('cover_image')) {
                app(\App\Service\Program\ProgramMedia::class)->storeCover($program, $request->file('cover_image'));
            }

            DB::commit();

            return response()->json([
                'message' => 'Program created successfully',
                'program' => $program,
                'firstRound' => $firstRound,
                'rounds' => [$firstRound],
            ], 200);
        }
        catch ( ValidationException $e) {
            return response()->json([ 'message' => 'Validation failed.', 'errors'  => $e->errors() ], 422);
        }
        catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    public function duplicate(Program $program)
    {
        DB::beginTransaction();

        try {
            $user = Auth::user();

            // 1. Authorization (VERY IMPORTANT)
            if (!\App\Service\Program\ProgramAccess::owns($program)) {
                return response()->json([
                    'message' => 'Unauthorized action.'
                ], 403);
            }

            // 2. Load required relations
            $program->load(['rounds.questions']);

            // 3. Replicate base program
            $newProgram = $program->replicate([
                'program_title', 'status', 'available_amount', 'application_start_date', 'application_deadline',
                'application_opens_at', 'application_closes_at', 'public_id', 'created_at', 'updated_at'
            ]);

            // 4. Reset important fields
            $newProgram->status = 'draft';
            $newProgram->program_title = $program->program_title . ' Copy';
            $newProgram->available_amount = $program->total_program_amount;
            $newProgram->visible = 0; // safer default
            $newProgram->application_start_date = null;
            $newProgram->application_deadline = null;
            $newProgram->push();

            // 5. Duplicate rounds + questions.
            foreach ($program->rounds as $round) {

                // A copy starts fresh: every round is a draft and none of its setup steps count as confirmed
                $excluded = ['program_id', 'open_date', 'close_date', 'opens_at', 'closes_at', 'status', 'created_at', 'updated_at'];
                if (\Illuminate\Support\Facades\Schema::hasColumn('program_rounds', 'confirmed_steps')) {
                    $excluded[] = 'confirmed_steps';
                }
                $newRound = $round->replicate($excluded);

                $newRound->program_id = $newProgram->id;
                $newRound->status = 'draft';
                $newRound->open_date = null;
                $newRound->close_date = null;
                $newRound->push();

                foreach ($round->questions as $question) {
                    $newQuestion = $question->replicate([
                        'round_id', 'created_at', 'updated_at'
                    ]);

                    $newQuestion->round_id = $newRound->id;
                    if ((int) $newRound->round_number !== 1) {
                        $newQuestion->use_in_match_score = false;
                        $newQuestion->match_points = null;
                        $newQuestion->match_answers = null;
                    }
                    $newQuestion->save();
                }
            }

            // 7. Create fresh wallet (DO NOT duplicate old financials)
            ProgramWallet::create([
                'total_deposited' => 0, 'total_disbursed' => 0, 'total_reserved' => 0, 'balance' => 0, 'status' => 'inactive',
                'currency' => $newProgram->currency, 'program_id' => $newProgram->id,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Program duplicated successfully',
                'program' => $newProgram->load('rounds.questions')
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            ErrorLogService::report($e, [
                'program_id' => $program->id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    // Display the specified program.

    public function show($id)
    {
        $program = Program::findOrFail($id);
        return response()->json($program);
    }


    /**
     * Update the specified program in storage.
     */

    public function store_watchlist($pitch_id)
    {
        try {
            $program_owner_id = Auth::id(); // renamed
            $exist = ProgramWatchlist::where('pitch_id', $pitch_id)
                ->where('program_owner_id', $program_owner_id)
                ->first();

            if ($exist) {
                ProgramWatchlist::where('pitch_id', $pitch_id)
                    ->where('program_owner_id', $program_owner_id)
                    ->delete();

                return response()->json(['message' => 'Removed from watchlist'], 200);
            } else {
                ProgramWatchlist::create([
                    'pitch_id' => $pitch_id,
                    'program_owner_id' => $program_owner_id
                ]);

                return response()->json(['message' => 'Added to watchlist'], 200);
            }
        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function get_watchlist()
    {
        try {
            $program_owner_id = Auth::id(); // renamed
            $watchlists = ProgramWatchlist::with('pitch')
                ->where('program_owner_id', $program_owner_id)
                ->latest()
                ->get();

            $pitches = $watchlists->pluck('pitch')->filter()->values();

            return response()->json(['pitches' => $pitches], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    // UPDATE PROGRAM
    public function update(Request $request, Program $program)
    {
        $userId = auth()->id();

        // Authorization
        if (!\App\Service\Program\ProgramAccess::owns($program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // The start date is only checked when it is being CHANGED. Editing another field of a program whose applications
        // already opened sends the same (past) start date back; that must not fail with "after or equal to today".
        // Moments sent with their time are read in the owner's zone and fill the date fields they belong to
        $timezone = ProgramSchedule::forRequest($request);
        $moments = ProgramSchedule::readMoments($request, self::PROGRAM_MOMENTS, $timezone);
        $storedStart = $program->application_start_date;
        $storedOpens = $program->opensAt();
        $sentStart = $request->input('application_start_date');
        $startUnchanged = isset($moments['application_opens_at'])
            ? $storedOpens && $moments['application_opens_at']->format('Y-m-d H:i') === $storedOpens->format('Y-m-d H:i')
            : $storedStart && $sentStart && strtotime((string) $sentStart) !== false
                && \Illuminate\Support\Carbon::parse($sentStart)->isSameDay($storedStart);
        $startRule = $startUnchanged ? 'sometimes|required|date' : 'sometimes|required|date|after_or_equal:today';
        // A deadline sent on its own is compared with the saved start date (not with a missing field).
        // With times, the same day is allowed: the moments themselves are compared below.
        $after = isset($moments['application_closes_at']) ? 'after_or_equal' : 'after';
        $deadlineRule = $request->has('application_start_date') || ! $storedStart
            ? 'sometimes|required|date|'.$after.':application_start_date'
            : 'sometimes|required|date|'.$after.':'.$storedStart->toDateString();

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'program_title' => 'sometimes|required|string|max:255',
                'program_description' => 'sometimes|nullable|string|max:500',
                'program_industry_id' => 'sometimes|required|exists:program_industries,id',
                'total_program_amount' => 'sometimes|required|numeric|min:0',
                'available_amount' => 'sometimes|nullable|numeric|min:0',
                'funding_per_business' => 'sometimes|required|numeric|min:0',
                'disbursement_type' => 'sometimes|nullable|in:supplier,hybrid,beneficiary',
                'program_type' => 'sometimes|required|in:single_round,multi_round',
                'total_rounds' => 'sometimes|required|integer|min:1|max:10',
                'max_awardees' => 'sometimes|nullable|integer|min:1',
                'currency' => 'sometimes|nullable|string|max:3',
                'application_start_date' => $startRule,
                'application_deadline' => $deadlineRule,
                'application_opens_at' => 'sometimes|nullable|date',
                'application_closes_at' => 'sometimes|nullable|date',
                'timezone' => 'sometimes|nullable|string|max:64',
                'require_kyc_verification_to_apply' => 'sometimes|nullable|boolean',
                'use_match_score' => 'sometimes|required|boolean',
                'match_score_limit' => 'sometimes|nullable|integer|min:1|max:100',
                'status' => 'sometimes|required|in:draft,published,open,closed,under_review,awarded,cancelled,completed',
                'visible' => 'sometimes|nullable|boolean',
                'cover_image' => array_merge(['sometimes', 'nullable'], \App\Service\Program\ProgramMedia::COVER_RULES),
                'remove_cover_image' => 'sometimes|boolean',
                'intro_video_url' => array_merge(['sometimes', 'nullable'], \App\Service\Program\ProgramMedia::videoRules()),
            ]);
            // The cover is a file (or a request to remove it), saved through ProgramMedia below, not as a column value
            unset($validated['cover_image'], $validated['remove_cover_image']);
            if (array_key_exists('intro_video_url', $validated)) {
                $validated['intro_video_url'] = trim((string) $validated['intro_video_url']) ?: null;
            }

            // Once applications have opened the start date is history: it cannot be moved, and saying so beats silently ignoring it
            $opened = $storedOpens ? $storedOpens->lte(now()) : ($storedStart && $storedStart->lte(now()->endOfDay()));
            if (array_key_exists('application_start_date', $validated) && ! $startUnchanged && $storedStart && $opened && $program->status !== 'draft') {
                throw ValidationException::withMessages([
                    'application_start_date' => ['The start date can no longer be changed because applications have already opened.'],
                ]);
            }
            if ($startUnchanged) {
                unset($validated['application_start_date']);
                unset($moments['application_opens_at']);
            }
            $validated = $this->withMoments($validated, $moments, $timezone, $program);

            if (array_key_exists('use_match_score', $validated)) {
                $validated['use_match_score'] = $request->boolean('use_match_score');
            }
            if (array_key_exists('use_match_score', $validated) && ! $validated['use_match_score']) {
                $validated['match_score_limit'] = null;
            }
            if (($validated['use_match_score'] ?? $program->use_match_score) === false) {
                $validated['match_score_limit'] = null;
            }

            if (($validated['program_type'] ?? $program->program_type) === 'multi_round' && (($validated['total_rounds'] ?? $program->total_rounds) < 2)) {
                throw ValidationException::withMessages([
                    'total_rounds' => ['Total rounds must be greater than 1 for multi-round programs']
                ]);
            }

            if (($validated['funding_per_business'] ?? $program->funding_per_business) > ($validated['total_program_amount'] ?? $program->total_program_amount)) {
                throw ValidationException::withMessages([
                    'funding_per_business' => ['Funding per business cannot exceed total program amount']
                ]);
            }

            if (array_key_exists('total_program_amount', $validated)) {
                $validated['available_amount'] = $validated['total_program_amount'];
            }

            $program->update($validated);
            if ($request->hasFile('cover_image')) {
                app(\App\Service\Program\ProgramMedia::class)->storeCover($program, $request->file('cover_image'));
            } elseif ($request->boolean('remove_cover_image')) {
                app(\App\Service\Program\ProgramMedia::class)->removeCover($program);
            }
            if (array_key_exists('use_match_score', $validated)) {
                app(\App\Service\Program\RoundMatchScore::class)->recalculateProgram($program->fresh());
            }

            DB::commit();

            if ( ($validated['status'] ?? null) === 'published' && $program->getOriginal('status') !== 'published') {
                $this->programNotification->send('program.published', [$program->user], [
                    'program_title' => $program->program_title, 'program_id' => $program->id,
                ]);
            }

            if ( ($validated['status'] ?? null) === 'finalized' && $program->getOriginal('status') !== 'finalized') {
                $awardedCount = $program->applications()->whereIn('status', ['awarded', 'in_monitoring', 'completed'])->count();
                $this->programNotification->send('program.finalized', [$program->user], [
                    'program_title' => $program->program_title, 'awarded_count' => $awardedCount, 'program_id' => $program->id,
                ]);
            }

            return response()->json([
                'message' => 'Program updated successfully',
                'data' => $program,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong.'], 500);
        }
    }

    /**
     * Remove the specified program from storage.
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $program = Program::findOrFail($id);

            // Authorization check (if needed)
            $userId = auth()->id();
            if (!\App\Service\Program\ProgramAccess::owns($program)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Any application, whatever its status (draft, submitted, approved...), belongs to a business that applied
            $applicationCount = $program->applications()->count();
            if ($applicationCount > 0) {
                DB::rollBack();
                return response()->json([
                    'message' => $applicationCount === 1
                        ? 'This program cannot be deleted because 1 applicant has applied to it. Close the program instead to stop new applications.'
                        : "This program cannot be deleted because {$applicationCount} applicants have applied to it. Close the program instead to stop new applications.",
                ], 422);
            }

            // Check if wallet has transactions
            $wallet = ProgramWallet::where('program_id', $program->id)->first();
            if ($wallet) {
                if ($wallet->total_deposited > 0 || $wallet->total_disbursed > 0 || $wallet->total_reserved > 0) {
                    return response()->json([
                        'message' => 'Program cannot be deleted because wallet has transaction history.'
                    ], 422);
                }

                // Delete wallet first (since it has RESTRICT foreign key)
                $wallet->delete();
            }

            // Delete rounds (cascade will handle round_reviewers, round_custom_questions, etc.)
            // One by one so each removed round is in the organization's audit log (a query delete skips model events)
            $program->rounds()->get()->each->delete();

            // Delete program
            $program->delete();

            DB::commit();

            return response()->json(['message' => 'Program deleted successfully'], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Program not found'], 404);

        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            // Something else still points at this program (a deal room, payment, reviewer...), so it is kept
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1451, 1217], true)) {
                return response()->json([
                    'message' => 'This program cannot be deleted because it already has activity linked to it. Close the program instead.',
                ], 422);
            }
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.',
            ], 500);
        }
    }

    // Sub Methods

    public function get_program($id)
    {
        try{
            $program = Program::with([
                'rounds.questions',
                'wallet'
            ])
                ->withCount('liked')
                ->find($id);

            //  Handle not found. Anyone outside the owner's team only sees a program that is published or open and
            //  visible; a draft (or closed one) answers exactly like a missing program, so it never leaks.
            if (!$program || (!\App\Service\Program\ProgramAccess::owns($program) && !$program->isListedForApplicants())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Program not found'
                ], 404);
            }

            //  No extra query (use loaded relation)
            $round1 = $program->rounds->first();
            if ($round1 && $round1->relationLoaded('questions')) {
                $round1->setRelation('questions', $round1->questions->map(
                    fn (\App\Models\Programs\Rounds\RoundCustomQuestion $question) => $question->applicantView()
                ));
            }
            //  Better naming (no overwrite)
            if (Auth::check()) {
                $program->liked = $program->liked()
                    ->where('user_id', Auth::id())
                    ->exists();
            }

            // The owner also sees how ready each round is (what is missing, published / waiting for its open date / live)
            if (Auth::check() && \App\Service\Program\ProgramAccess::owns($program)) {
                $configuration = app(\App\Service\Program\RoundConfiguration::class);
                $program->rounds->each(fn ($round) => $round->setAttribute('readiness', $configuration->readiness($round)));
                if ($round1) {
                    $round1->setAttribute('readiness', $configuration->readiness($round1));
                }
            }

            return ApiContract::itemResponse(
                'Program fetched successfully.',
                'program',
                $program,
                ['application_round' => $round1],
                ['program_data' => $program, 'application_round' => $round1],
            );
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

    public function visibility($program_id)
    {
        try {
            $program = Program::where('id',$program_id)->latest()->first();
            if ($program->visible == 1) {
                Program::where('id', $program_id)->update([
                    'visible' => 0
                ]);
            }
            else{
                Program::where('id', $program_id)->update([
                    'visible' => 1
                ]);
            }
            return response()->json(['message' => 'Visibility Changed.'], 200);
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




    public function update_user(Request $request)
    {
        try{
            // A role change sends only the role; the name is optional and only changed when sent
            $request->validate([
                'id'          => 'required',
                'first_name'  => 'sometimes|required|string|max:255',
                'last_name'   => 'sometimes|nullable|string|max:255',
                'role_id'     => 'required|numeric|exists:roles,id',
            ]);

            $user = User::findOrFail($request->id);
            $me = Auth::user();

            // Only the owner and super admins manage the team, and only people in their own organization
            if (! $me || ! $me->organization_id || (int) $me->organization_id !== (int) $user->organization_id) {
                return response()->json(['message' => 'You can only update members of your own organization.'], 403);
            }
            if (! \App\Service\Organization\OrganizationPermission::isAdmin($me)) {
                return \App\Service\Organization\OrganizationPermission::denied('Only the organization owner or a super admin can change team members.');
            }
            // Nobody changes their own role, and the owner's access is never changed here
            $ownerId = \App\Models\Organizations\Organization::whereKey($user->organization_id)->value('owner_user_id');
            if ((int) $user->id === (int) $me->id || (int) $user->id === (int) $ownerId) {
                return response()->json(['message' => "The organization owner's and your own role can't be changed here."], 422);
            }
            if (! in_array((int) $request->role_id, [10001, 10002, 10003, 10004], true)) {
                return response()->json(['message' => 'Choose a team role.', 'errors' => ['role_id' => ['Choose a team role.']]], 422);
            }

            // Only the name can be edited here (never type, email, organization or other account fields)
            $data = $request->only(['first_name', 'last_name']);
            if (! empty($data)) {
                $user->update($data);
            }
            $previousRoleId = $user->organizationRole?->role_id;
            $user->organizationRole()->update(['role_id' => $request->role_id]);

            if ((int) $previousRoleId !== (int) $request->role_id && (int) $user->user_type_id === 4) {
                $newRole = \App\Models\Auth\Role::find($request->role_id);
                TeamMemberNotifier::notify($user, Auth::user(), 'role_changed', $newRole?->name ?? 'Team member');
            }

            return response()->json(['message' => 'User updated.'], 200);
        }
        catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422);
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

    public function delete_user(Request $request)
    {
        DB::beginTransaction();
        try{
            $user = User::findOrFail($request->id);
            //Balance logs
            BalanceLog::where('changed_by', $user->id)->delete();

            if (Auth::id() !== $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            if($user->balance?->balance > 0){
                return response(['message' => 'Account deletion not allowed. Please withdraw your balance first.'],400);
            }

            //deletion eligibility checks
            $checker = new AccountDeletionEligibilityService($user);

            if( !$checker->isDeletable() ){
                return response()->json([
                    'message' => 'Account deletion is not allowed',
                    'reasons' => $checker->preventingReason(),
                ], 400);

            }


            ProgramApplication::where('user_id',$request->id)->delete();

            if($user->user_type_id == 4){ // Program organization
                $user->delete();

                //Delete Programs/files/pitches/bookings/Messages
                $programs = Program::where('user_id', $user->id)->get();
                foreach ($programs as $program) {
                    $program->delete();
                }

                ServiceBooking::where('booker_id', $user->id)->delete();
                Messages::where('to_id', $user->id)->orWhere('from_id', $user->id)->delete();
            }
            DB::commit();
            return response()->json([ 'message' => 'Account deleted.'], 200);
        }
        catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /**
     * Why the signed-in person may not remove this account from the team, or null when they may: only the owner and
     * super admins, only people in their own organization, never themselves or the owner.
     */
    private function teamRemovalRefusal(?User $user)
    {
        $me = Auth::user();
        if (! $user || ! $me || ! $me->organization_id || (int) $user->organization_id !== (int) $me->organization_id) {
            return response()->json(['message' => 'Team member not found in your organization.'], 404);
        }
        if (! \App\Service\Organization\OrganizationPermission::isAdmin($me)) {
            return \App\Service\Organization\OrganizationPermission::denied('Only the organization owner or a super admin can remove team members.');
        }
        $ownerId = \App\Models\Organizations\Organization::whereKey($user->organization_id)->value('owner_user_id');
        if ((int) $user->id === (int) $me->id || (int) $user->id === (int) $ownerId) {
            return response()->json(['message' => "You can't remove yourself or the organization owner."], 422);
        }

        return null;
    }

    public function delete_roleUser(Request $request)
    {
        DB::beginTransaction();
        try{
            $user = User::find($request->id);
            if ($refusal = $this->teamRemovalRefusal($user)) {
                DB::rollBack();
                return $refusal;
            }
            $roleName = $user->organizationRole?->role?->name ?? 'Team member';
            $memberSnapshot = $user->replicate(['password']);
            User::where('id',$request->id)->delete();

            if($user->user_type_id == 4) // Program organization
                $user->organizationRole()->delete();
            else
                CapitalProfile::where('user_id',$request->id)->delete();

            DB::commit();

            // Email only: the account is gone, so there is no inbox for an in-app notification
            if ((int) $user->user_type_id === 4) {
                TeamMemberNotifier::notify($memberSnapshot, Auth::user(), 'removed', $roleName, notify: false);
            }
            return response()->json([ 'message' => 'User deleted'], 200);
        }
        catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }


    public function reject_invitation(Request $request, $email)
    {
        try{
            if (! $request->hasValidSignature()) {
                return response()->json(['message' => 'Invalid or expired link'], 403);
            }

            $user = User::with('organizationRole')->where('email',$email)->first();
            $user?->organizationRole?->delete();
            $user?->delete();

            // The invitation is gone with the account, so this person is a guest now: the main site's sign-up
            return redirect()->away(\App\Service\Notification\RecipientUrl::url('?registerModal=open', null));
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


    public function update_profile(Request $request)
    {
        try{
            $user = Auth::user()->load('organization');
            $request->validate([
                'first_name' => 'required|string|max:255', // Org name
                'interested_cats' => 'array', // Focus Sectors
                'org_type' => 'required|string',
                'phone' => 'string|max:20',
                'mission' => 'string',
                'regions' => 'array', // Target Regions
                'website' => 'nullable|url',
            ]);

            $user->update([
                'first_name' => $request->first_name,
                'interested_cats' => $request->interested_cats,
                'phone' => $request->phone,
                'website' => $request->website,
            ]);

            $user->organization?->update([
                'organization_type' => $request->org_type,
                'description' => $request->mission,
                'target_regions' => $request->regions,
            ]);

            //Update Image
            $image=$request->file('image');
            $path ='images/users';
            if($image) {
//                $ext=strtolower($image->getClientOriginalExtension());
//                $create_name = hexdec(uniqid()).'.'.$ext;
//                $loc='images/users/';
//                $final_img=$this->site_url.$loc.$create_name;
//                $compressedImage = $obj->compressImage($image, $loc.$create_name, 60);

                $path = $this->imageUpload->save($image, $path);

                // Delete old image if exists
                if($user->image && file_exists($user->image))
                {
                    unlink($user->image);
                }

                $user->update([
                    'image' => $path,
                ]);
            }

            return response()->json(['message' => 'Profile updated successfully'],200);
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

    public function release_fst_milestone(Request $request)
    {
        try{
            //$sme_emails = array(); $program_owner_emails = array();
            $pitch_ids = $request->pitch_ids;
            $total_amount_db = 0; $curr='USD';
            $total_amount_db = ProgramMilestone::whereIn('app_id', $pitch_ids)
                ->orderBy('id')->get()->groupBy('app_id')
                ->map(fn($group) => $group->first()->amount)
                ->sum();

            //C h a r g e  Setup
            $amount= $request->amount;
            $amountOriginal= $request->amountOriginal;
            //$transferAmount= round($amount-($amountOriginal*.05),2);
            $this->validate($request, [
                'stripeToken' => ['required', 'string']
            ]);
            if( $total_amount_db !== $amountOriginal){
                return response()->json(['message' => 'Amount does not match the requested amount.'], 400);
            }

            //Crate Bulk Charge
            $charge = $this->Client->charges->create ([
                //"billing_address_collection": null,
                "amount" => (int) round($total_amount_db*100), //100 * 100,
                "currency" => $curr,
                "source" => $request->stripeToken,
                "description" => "Bulk Release Program Milestone Funds"
            ]);
            //C h a r g e  Setup Ends

            foreach($pitch_ids as $pitch_id)
            {
                $pitch = ProgramApplication::with('program')->find($pitch_id);
                $milestone = ProgramMilestone::select('id','title','app_id','amount','status')
                ->where('app_id',$pitch->id)->orderBy('id', 'asc')->first();

                if($milestone->status === 1)
                {
                    continue;
                }

                $emails = User::whereIn('id', [$pitch->user_id, $pitch->program_owner_id])
                    ->pluck('email', 'id');

                $connectIds = User::whereIn('id', [$pitch->user_id, $pitch->program_owner_id])
                    ->pluck('connect_id', 'id');

                //Getting Credentials
                $sme_email = $emails[$pitch->user_id];
                $program_owner_email = $emails[$pitch->program_owner_id];
                $sme_connect_id = $connectIds[$pitch->user_id];

                //T r a n s f e r to each SME and DB Amount Calculation
                $amountToTransfer = $this->checkoutCalculator->stripeGC($milestone->amount); // $(91.30 of 100)
                if ($charge && $charge->id) {
                    $transfer = $this->Client->transfers->create([
                        //"billing_address_collection": null,
                        "amount" =>  $amountToTransfer * 100, //100 * 100,
                        "currency" => $curr,
                        "source_transaction" => $charge->id,
                        'destination' => $sme_connect_id
                    ]);

                    //D a t a b a s e
                    $milestone->update([
                        'status' => 1,
                        'fund_released' => 1,
                    ]);
                    $programUp = Program::where('id', $pitch->program_id)->update([
                        'available_amount' => DB::raw("available_amount - {$milestone->amount}")
                    ]);

                    //Update User Wallet
                    $balanceService = new BalanceService();
                    $newBalance = $balanceService
                        ->updateBalance($pitch->user_id, (float)$milestone->amount, 'Release Milestone');
                    //Update User Wallet

                    $text = $milestone->title . ' fund for ' . $pitch->program->program_title . ' has been released.';
                    $notification = new NotificationService();
                    $notification->create($pitch->user_id, $pitch->program->user_id, $text
                        , 'dashboard.entrepreneur.programsDealroom.detail::'.$pitch_id, ' program');

                    // E M A I L
                    $info=[
                        'program'=>$pitch->program->program_title,
                        'amount'=>$milestone->amount,
                        'milestone_title' => $milestone->title
                    ];
                    $user['to'] = [$program_owner_email, $sme_email]; //'tottenham266@gmail.com';
                    Mail::send('opportunities.program_milestone', $info, function($msg) use ($user){
                        $msg->to($user['to']);
                        $msg->subject(' Program Milestone');
                    });
                    // E M A I L
                }
                else
                {
                    return response()->json(['message' => 'Payment Failed.'], 400);
                }

            }

            return response()->json(['message' => 'Fund Release Success.', 'status' =>200], 200);
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

    // H E L P E R S

    /** Program moment fields => the date field each one fills. */
    private const PROGRAM_MOMENTS = ['application_opens_at' => 'application_start_date', 'application_closes_at' => 'application_deadline'];

    /**
     * Puts the parsed moments (UTC) in place of the raw ones, records the zone they were set in, and refuses a
     * closing moment that is not after the opening one.
     */
    private function withMoments(array $validated, array $moments, string $timezone, ?Program $program): array
    {
        unset($validated['application_opens_at'], $validated['application_closes_at'], $validated['timezone']);
        if ($moments === []) {
            return $validated;
        }

        $opens = $moments['application_opens_at'] ?? $program?->opensAt();
        $closes = $moments['application_closes_at'] ?? $program?->closesAt();
        if ($opens && $closes && $closes->lte($opens)) {
            throw ValidationException::withMessages([
                'application_closes_at' => ['Applications must close after they open.'],
            ]);
        }

        return array_merge($validated, $moments, ['timezone' => $timezone]);
    }
    public function CreateApplicationRound($roundData, $questions, $program){
        // CREATE FIRST ROUND AUTOMATICALLY
        $round = ProgramRound::create([
            'program_id' => $program->id,
            'round_name' => $roundData['round_name'] ?? 'Public Application Round',
            'round_number' => 1,
            'opens_at' => $program->application_opens_at,
            'closes_at' => $program->application_closes_at,
            'review_period_end' => $roundData['review_period_end'] ?? null,
            'announcement_date' => $roundData['announcement_date'] ?? null,

            // Evaluation Settings (use defaults)
            'rubric_mode' => $roundData['rubric_mode'] ?? 'weighted',
            'scoring_criteria' => $roundData['scoring_criteria'] ?? null,
            'knockout_questions' => $roundData['knockout_questions'] ?? null,
            'required_documents' => $roundData['required_documents'] ?? null,

            // Reviewer Assignment (defaults)
            'assignment_type' => $roundData['assignment_type'] ?? 'owner_only',
            'assignment_method' => $roundData['assignment_method'] ?? 'manual',
            'min_reviewers_required' => $roundData['min_reviewers_required'] ?? 1,

            // Advancement Settings (defaults)
            'advancement_mode' => $roundData['advancement_mode'] ?? 'manual',
            'score_threshold' => $roundData['score_threshold'] ?? null,
            'max_advancing' => $roundData['max_advancing'] ?? null,
            'tie_breaker_rule' => $roundData['tie_breaker_rule'] ?? null,

            'status' => 'draft',
        ]);

        $program->status = 'published';
        $program->save();

        if($questions){
            foreach ($questions as $index => $question) {
                $question = RoundCustomQuestion::create([
                    'round_id' => $round->id,
                    'question_text' => $question['question_text'] ?? null,
                    'question_type' => $question['question_type'] ?? null,
                    'options' => $question['options'] ?? null,
                    'is_required' => $question['is_required'] ?? false,
                    'display_order' => $question['display_order'] ?? ($index + 1),
                    'knockout_fail_value' => $question['knockout_fail_value'] ?? null,
                ]);
            }
        }


        return $round;

    }


}
