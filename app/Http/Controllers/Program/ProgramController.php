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
use App\Models\Programs\ProgramProfile;
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
        $user = Auth::user()->load('program_profile.role');
        $user->role = $user->program_profile?->role?->name ?? 'super-admin';
        $user->program_owner_id = $user->program_profile?->program_owner_id;
        return $user;
    }
    // C O R E  Methods
    public function index()
    {
        try{
            if(Auth::check()){
                $user_id = Auth::id();
                $user = User::select('user_type_id','id')->where('id',$user_id)->first();
                if($user->user_type_id == 2){  //Program
                    $user = $this->get_role();

                    if (in_array($user->role, ['editor', 'viewer', 'admin'])) {
                        $programs = Program::withCount(['liked', 'applications'])->where('user_id',$user->program_owner_id)
                            ->latest()->get();
                        $watchlistProgramIds = Watchlist::where('user_id', $user->program_owner_id)->pluck('org_id')->toArray();
                    }
                    else{
                        $programs = Program::with('wallet')->withCount(['liked', 'applications'])
                            ->where('user_id',$user->id)
                            ->latest()->get();
                        $watchlistProgramIds = Watchlist::where('user_id', $user->id)->pluck('org_id')->toArray();
                    }


                    foreach ($programs as $program){
                        $program->pitch_count = $program->applications_count ?? 0;
                        $program->in_my_watchlist = in_array($program->id, $watchlistProgramIds);
                        $program->liked = $program->liked()->where('user_id', $user_id)->exists();
                        $program->owner_website = $program->owner->website ?? null;

                        $status = $program->status; // âœ… store original value first

                        $program->status = [
                            'value' => $status,
                            'color' => config('status.program.' . $status, 'gray'), // fallback
                        ];
                    }

                    return response()->json(['programs' => $programs]);
                }
            }
            else{
                $user_id = null;
            }

            // Public programs
            $programs = Program::withCount(['liked', 'applications'])->where('status', '!=', 'draft')->get();
            foreach ($programs as $program){
                $program->pitch_count = $program->applications_count ?? 0;

                $program->liked = $user_id ? $program->liked()->where('user_id', $user_id)->exists() : false;
                $program->owner_website = $program->owner->website ?? null;

                $status = $program->status; // âœ… store original value first

                $program->status = [
                    'value' => $status,
                    'color' => config('status.program.' . $status, 'gray'), // fallback
                ];
            }
            return response()->json(['programs' => $programs]);
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
            $programs = Program::withCount(['liked', 'applications'])->get();
            foreach ($programs as $program){
                $program->pitch_count = $program->applications_count ?? 0;
                $program->liked = $user_id ? $program->liked()->where('user_id', $user_id)->exists() : false;
                $program->owner_website = $program->owner->website ?? null;

                $status = $program->status; // store original value first

                $program->status = [
                    'value' => $status,
                    'color' => config('status.program.' . $status, 'gray'), // fallback
                ];
            }
            return response()->json(['programs' => $programs]);
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
        $uploadedFile = null;
        DB::beginTransaction();

        try{
            $validated = $request->validate([
                // Required core fields
                'program_title' => 'required|string|max:255',
                'total_program_amount' => 'required|numeric|min:0',
                'funding_per_business' => 'required|numeric|min:0',

                'program_type' => 'required|in:single_round,multi_round',
                'total_rounds' => 'nullable|integer|min:1|max:10',

                // Important optional fields
                'funder_name' => 'nullable|string|max:200',
                'eligibility_criteria' => 'nullable|string',
                'required_documents' => 'nullable|array',
                'program_focus' => 'required|array',
                "startup_stage_focus" => "required|array",
                'regions' => 'nullable|array',
                'bonus_points' => 'nullable|array',

                "impact_objectives" => "nullable|string",
                "social_impact_areas" => "nullable|array",
                //"program_brief_pdf" => "required|file|mimes:pdf,docx|max:2048",
                'start_date' => 'required|date',
                'application_deadline' => 'required|date|after:today',
                'currency' => 'nullable|string',
                'mid_milestone_required' => 'nullable|boolean',

                // round 1
                'application_round' => 'required|array',  // âœ… Now it's an array
                'application_round.round_name' => 'nullable|string|max:100',
                'application_round.rubric_mode' => 'nullable|in:weighted,simple_total,pass_fail',
                'application_round.advancement_mode' => 'nullable|in:manual,score_threshold,fixed_quota',
                'application_round.required_documents' => 'nullable|array',
                'application_round.knockout_questions' => 'nullable|array',


                // Question
                'round_questions' => 'nullable|array',
                'round_questions.*.question_text' => 'nullable|string|max:255',
                'round_questions.*.question_type' => 'nullable|in:short_answer,long_text',


                'disbursement_type' => 'nullable|in:supplier,hybrid,beneficiary',
                //'end_date' => 'nullable|date|after:start_date',
            ]);

            $orgUser = User::with('organization')->find(Auth::id());
            if(!$orgUser->organization){
                return response()->json(['message' => 'Organization profile not found, Forbidden.'], 403);
            }

            if($validated['program_type'] == 'multi_round' && $validated['total_rounds'] == 1){
                throw ValidationException::withMessages([
                    'total_rounds' => ['Total rounds must be greater than 1 for multi-round programs']
                ]);
            }

            // NEW: Validate funding per business cannot exceed total
            if ($validated['funding_per_business'] > $validated['total_program_amount']) {
                throw ValidationException::withMessages([
                    'funding_per_business' => ['Funding per business cannot exceed total program amount']
                ]);
            }
            $maxAwardees = floor($validated['total_program_amount'] / $validated['funding_per_business']);


            // Auto-set fields
            $validated['user_id'] = Auth::id();
            $validated['status'] = 'draft';
            $validated['funder_type'] = $orgUser->organization->organization_type; //draft
            $validated['visible'] = 1;
            $validated['currency'] = $validated['currency'] ?? 'KES';
            $validated['available_amount'] = $validated['total_program_amount'];
            $validated['max_awardees'] = $maxAwardees;
            $validated['program_type'] = $validated['program_type'] ?? 'single_round';
            $validated['total_rounds'] = $validated['total_rounds'] ?? 1;

            $program = Program::create(
                Arr::except($validated, ['application_round', 'round_questions'])
            );

            // Create Application Round with helper fund
            $firstRound = $this->CreateApplicationRound(
                $validated['application_round'] ?? null,
                $validated['round_questions'] ?? null,
                $program
            );

            //Upload file & update
            $path='files/programs/'.$program->id;
            $program_brief_pdf = $request->file('program_brief_pdf');
            $program_brief_pdf_path = $this->fileUpload->saveFile($program_brief_pdf, $path);

            $uploadedFile = $program_brief_pdf_path;
            $program->update([ 'program_brief_pdf' => $program_brief_pdf_path ]);

            # Create dedicated wallet
            $wallet['total_deposited'] = 0.00; $wallet['total_disbursed'] = 0.00;
            $wallet['total_reserved'] = 0.00;  $wallet['balance'] = 0.00;
            $wallet['status'] = 'inactive';  // not active until funded
            $wallet['currency'] = $validated['currency'] ?? 'KES';
            $wallet['program_id'] = $program->id;

            ProgramWallet::create($wallet);

            DB::commit();

            return response()->json([
                'message' => 'Program created successfully',
                'program' => $program,
                'first_round' => $firstRound,
            ], 200);
        }
        catch ( ValidationException $e) {
            return response()->json([ 'message' => 'Validation failed.', 'errors'  => $e->errors() ], 422);
        }
        catch (\Exception $e) {
            DB::rollBack();
            if ($uploadedFile && file_exists($uploadedFile)) {
                unlink($uploadedFile);
            }
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
            if ($program->user_id !== $user->id) {
                return response()->json([
                    'message' => 'Unauthorized action.'
                ], 403);
            }

            // 2. Load required relations
            $program->load(['rounds.questions']);

            // 3. Replicate base program
            $newProgram = $program->replicate([
                'program_title', 'status', 'available_amount', 'program_brief_pdf', 'start_date', 'application_deadline',
                'created_at', 'updated_at'
            ]);

            // 4. Reset important fields
            $newProgram->status = 'draft';
            $newProgram->program_title = $program->program_title . ' Copy';
            $newProgram->available_amount = $program->total_program_amount;
            $newProgram->visible = 0; // safer default
            $newProgram->start_date = null;
            $newProgram->application_deadline = null;
            $newProgram->push();

            // 5. Duplicate file (optional but recommended)
            /* if ($program->program_brief_pdf && file_exists($program->program_brief_pdf)) {
                $newPath = 'files/programs/' . $newProgram->id;

                $newFile = $this->fileUpload->copyFile(
                    $program->program_brief_pdf, $newPath
                );
                $newProgram->update([ 'program_brief_pdf' => $newFile ]);
            } */

            // 6. Duplicate rounds + questions
            foreach ($program->rounds as $round) {

                $newRound = $round->replicate([
                    'program_id', 'open_date', 'close_date', 'created_at', 'updated_at'
                ]);

                $newRound->program_id = $newProgram->id;
                $newRound->open_date = null;
                $newRound->close_date = null;
                $newRound->push();

                foreach ($round->questions as $question) {
                    $newQuestion = $question->replicate([
                        'round_id', 'created_at', 'updated_at'
                    ]);

                    $newQuestion->round_id = $newRound->id;
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
        if ($program->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                // Use 'sometimes' - only validate if present in request, if not present, it won't be updated
                'program_title' => 'sometimes|required|string|max:255',
                'total_program_amount' => 'sometimes|required|numeric|min:0',
                'funding_per_business' => 'sometimes|required|numeric|min:0',

                'funder_name' => 'nullable|string|max:200',
                'eligibility_criteria' => 'sometimes|required|string',
                'required_documents' => 'sometimes|required||array',
                'program_focus' => 'sometimes|required|array',
                'startup_stage_focus' => 'sometimes|required|array',
                'regions' => 'sometimes|required|array',
                'impact_objectives' => 'nullable|string',

                'program_brief_pdf' => 'sometimes|file|mimes:pdf|max:2048',
                'start_date' => 'sometimes|required|date',
                'application_deadline' => 'sometimes|required|date|after:today',
                'currency' => 'nullable|string',
                'status' => 'sometimes|required|string',
                'total_rounds' => 'sometimes|required|numeric|min:1',

                'disbursement_type' => 'nullable|in:supplier,hybrid,beneficiary',
            ]);

            if($program->program_type == 'single_round' && $validated['total_rounds'] > 1) {
                throw new ValidationException([
                    'rounds' => 'Single rounds cannot be greater than 1'
                ]);
            }


            // Handle file upload if provided
            if ($request->hasFile('program_brief_pdf')) {
                $path = 'files/programs/' . $program->id;
                $program_brief_pdf = $request->file('program_brief_pdf');
                $validated['program_brief_pdf'] = $this->fileUpload->saveFile($program_brief_pdf, $path);
            }

            $program->update($validated);

            DB::commit();

            if ( ($validated['status'] ?? null) === 'published' && $program->getOriginal('status') !== 'published') {
                $this->programNotification->send('program.published', [$program->user], [
                    'program_title' => $program->program_title, 'program_id' => $program->id,
                ]);
            }

            if ( ($validated['status'] ?? null) === 'finalized' && $program->getOriginal('status') !== 'finalized') {
                $awardedCount = $program->applications()->where('status', 'awarded')->count();
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
            if ($program->user_id !== $userId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Check for active applications
            if ($program->activeApplications()->count() > 0) {
                return response()->json([
                    'message' => 'Program cannot be deleted due to associated applications.'
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

            // Delete program brief file if exists
            if ($program->program_brief_pdf != null && file_exists($program->program_brief_pdf)) {
                unlink($program->program_brief_pdf);
            }

            // Delete rounds (cascade will handle round_reviewers, round_custom_questions, etc.)
            $program->rounds()->delete();

            // Delete program
            $program->delete();

            DB::commit();

            return response()->json(['message' => 'Program deleted successfully'], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Program not found'], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.',
                'error' => $e->getMessage() // Remove in production
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

            //  Handle not found
            if (!$program) {
                return response()->json([
                    'message' => 'Program not found'
                ], 404);
            }

            //  No extra query (use loaded relation)
            $round1 = $program->rounds->first();

            //  Better naming (no overwrite)
            if (Auth::check()) {
                $program->liked = $program->liked()
                    ->where('user_id', Auth::id())
                    ->exists();
            }

            return response()->json([
                'program_data' => $program,
                'application_round' => $round1,
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
            $request->validate([
                'id'    => 'required',
                'fname'  => 'required|string|max:255',
                //'email' => 'required|string|max:255',
                'role_id'  => 'required|numeric',
            ]);

            $data = $request->except(['_token', 'id', 'role_id']);
            $user = User::findOrFail($request->id);
            $user->update($data); // performs mass update
            $program_profile = ProgramProfile::where('user_id', $user->id)->update([
                'role_id' => $request->role_id
            ]);
            return response()->json(['message' => 'User updated.'], 200);
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

            $programProfile = $user->program_profile;
            if (!$programProfile || Auth::id() !== $programProfile->user_id || $programProfile->role_id) {
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


            $program_profiles = ProgramProfile::where('user_id',$request->id)
                ->orWhere('program_owner_id',$request->id)->get();

            ProgramApplication::where('user_id',$request->id)->delete();

            if($user->user_type_id == 2){ //Program
                foreach ($program_profiles as $profile) {
                    if ($profile->user_id === $user->id) {
                        continue; // skip owner
                    }
                    $profile->user?->delete();
                    $profile->delete();
                }
                $user->delete();

                //Delete Programs/files/pitches/bookings/Messages
                $programs = Program::where('user_id', $user->id)->get();
                foreach ($programs as $program) {
                    $filePath = public_path($program->program_brief_pdf);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
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

    public function delete_roleUser(Request $request)
    {
        DB::beginTransaction();
        try{
            $user = User::find($request->id);
            User::where('id',$request->id)->delete();

            if($user->user_type_id == 2) //Program
                ProgramProfile::where('user_id',$request->id)->delete();
            else
                CapitalProfile::where('user_id',$request->id)->delete();

            DB::commit();
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

            $user = User::with('program_profile')->where('email',$email)->first();
            $user?->program_profile?->delete();
            $user?->delete();

            return redirect()->away('https://beta.tujitume.com?registerModal=open');
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
            $user = Auth::user()->load('program_profile');
            $request->validate([
                'fname' => 'required|string|max:255', // Org name
                'interested_cats' => 'array', // Focus Sectors
                'org_type' => 'required|string',
                'phone' => 'string|max:20',
                'mission' => 'string',
                'regions' => 'array', // Target Regions
                'website' => 'nullable|url',
            ]);

            $user->update([
                'fname' => $request->fname,
                'interested_cats' => $request->interested_cats,
                'phone' => $request->phone,
                'website' => $request->website,
            ]);

            // Prepare Program data
            $programProfileData = [
                'org_type' => $request->org_type,
                'mission'  => $request->mission,
                'regions'  => $request->regions,
            ];

            // Update or create CapitalProfile
            $user->program_profile()->updateOrCreate(
                ['user_id' => $user->id],
                $programProfileData
            );

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
    public function CreateApplicationRound($roundData, $questions, $program){
        // CREATE FIRST ROUND AUTOMATICALLY
        $round = ProgramRound::create([
            'program_id' => $program->id,
            'round_name' => $roundData['round_name'] ?? 'Public Application Round',
            'round_number' => 1,
            'open_date' => $program->start_date ,
            'close_date' => $program->application_deadline,
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

            'status' => 'published',
        ]);

        $program->status = 'published';
        $program->save();

        if($questions){
            foreach ($questions as $question) {
                $question = RoundCustomQuestion::create([
                    'round_id' => $round->id,
                    'question_text' => $question['question_text'] ?? null,
                    'question_type' => $question['question_type'] ?? null,
                ]);
            }
        }


        return $round;

    }


}
