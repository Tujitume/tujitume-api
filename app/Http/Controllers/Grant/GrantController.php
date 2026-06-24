<?php

namespace App\Http\Controllers\Grant;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Capital\CapitalProfile;
use App\Models\Communication\Messages;
use App\Models\Finance\BalanceLog;
use App\Models\Grants\Grant;
use App\Models\Grants\GrantApplication;
use App\Models\Grants\GrantMilestone;
use App\Models\Grants\GrantProfile;
use App\Models\Grants\GrantWallet;
use App\Models\Grants\GrantWatchlist;
use App\Models\Grants\Rounds\GrantRound;
use App\Models\Grants\Rounds\RoundCustomQuestion;
use App\Models\Services\serviceBook;
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

class GrantController extends Controller
{
    /**
     * Display a listing of grants.
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
        $user = Auth::user()->load('grant_profile.role');
        $user->role = $user->grant_profile?->role?->name ?? 'super-admin';
        $user->grant_owner_id = $user->grant_profile?->grant_owner_id;
        return $user;
    }
    // C O R E  Methods
    public function index()
    {
        try{
            if(Auth::check()){
                $user_id = Auth::id();
                $user = User::select('user_type_id','id')->where('id',$user_id)->first();
                if($user->user_type_id == 2){  //Grant
                    $user = $this->get_role();

                    if (in_array($user->role, ['editor', 'viewer', 'admin'])) {
                        $grants = Grant::withCount(['liked', 'applications'])->where('user_id',$user->grant_owner_id)
                            ->latest()->get();
                        $watchlistGrantIds = Watchlist::where('user_id', $user->grant_owner_id)->pluck('org_id')->toArray();
                    }
                    else{
                        $grants = Grant::with('wallet')->withCount(['liked', 'applications'])
                            ->where('user_id',$user->id)
                            ->latest()->get();
                        $watchlistGrantIds = Watchlist::where('user_id', $user->id)->pluck('org_id')->toArray();
                    }


                    foreach ($grants as $grant){
                        $grant->pitch_count = $grant->applications_count ?? 0;
                        $grant->in_my_watchlist = in_array($grant->id, $watchlistGrantIds);
                        $grant->liked = $grant->liked()->where('user_id', $user_id)->exists();
                        $grant->owner_website = $grant->owner->website ?? null;

                        $status = $grant->status; // ✅ store original value first

                        $grant->status = [
                            'value' => $status,
                            'color' => config('status.grant.' . $status, 'gray'), // fallback
                        ];
                    }

                    return response()->json(['grants' => $grants]);
                }
            }
            else{
                $user_id = null;
            }

            // Public grants
            $grants = Grant::withCount(['liked', 'applications'])->where('status', '!=', 'draft')->get();
            foreach ($grants as $grant){
                $grant->pitch_count = $grant->applications_count ?? 0;

                $grant->liked = $user_id ? $grant->liked()->where('user_id', $user_id)->exists() : false;
                $grant->owner_website = $grant->owner->website ?? null;

                $status = $grant->status; // ✅ store original value first

                $grant->status = [
                    'value' => $status,
                    'color' => config('status.grant.' . $status, 'gray'), // fallback
                ];
            }
            return response()->json(['grants' => $grants]);
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

    public function public_grants()
    {
        try{
            $user_id = Auth::id();
            $grants = Grant::withCount(['liked', 'applications'])->get();
            foreach ($grants as $grant){
                $grant->pitch_count = $grant->applications_count ?? 0;
                $grant->liked = $user_id ? $grant->liked()->where('user_id', $user_id)->exists() : false;
                $grant->owner_website = $grant->owner->website ?? null;

                $status = $grant->status; // store original value first

                $grant->status = [
                    'value' => $status,
                    'color' => config('status.grant.' . $status, 'gray'), // fallback
                ];
            }
            return response()->json(['grants' => $grants]);
        }
        catch (\Exception $e) {
        ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
        return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    /**
     * Store a newly created grant in storage.
     */
    public function store(Request $request)
    {
        $uploadedFile = null;
        DB::beginTransaction();

        try{
            $validated = $request->validate([
                // Required core fields
                'grant_title' => 'required|string|max:255',
                'total_grant_amount' => 'required|numeric|min:0',
                'funding_per_business' => 'required|numeric|min:0',

                'grant_type' => 'required|in:single_round,multi_round',
                'total_rounds' => 'nullable|integer|min:1|max:10',

                // Important optional fields
                'funder_name' => 'nullable|string|max:200',
                'eligibility_criteria' => 'nullable|string',
                'required_documents' => 'nullable|array',
                'grant_focus' => 'required|array',
                "startup_stage_focus" => "required|array",
                'regions' => 'nullable|array',
                'bonus_points' => 'nullable|array',

                "impact_objectives" => "nullable|string",
                "social_impact_areas" => "nullable|array",
                "grant_brief_pdf" => "required|file|mimes:pdf,docx|max:2048",
                'start_date' => 'required|date',
                'application_deadline' => 'required|date|after:today',
                'currency' => 'nullable|string',
                'mid_milestone_required' => 'nullable|boolean',

                // round 1
                'application_round' => 'required|array',  // ✅ Now it's an array
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

            $grantUser = User::with('grant_profile')->find(Auth::id());
            if(!$grantUser->grant_profile){
                return response()->json(['message' => 'Grant profile not found, Forbidden.'], 403);
            }

            if($validated['grant_type'] == 'multi_round' && $validated['total_rounds'] == 1){
                throw ValidationException::withMessages([
                    'total_rounds' => ['Total rounds must be greater than 1 for multi-round grants']
                ]);
            }

            // NEW: Validate funding per business cannot exceed total
            if ($validated['funding_per_business'] > $validated['total_grant_amount']) {
                throw ValidationException::withMessages([
                    'funding_per_business' => ['Funding per business cannot exceed total grant amount']
                ]);
            }
            $maxAwardees = floor($validated['total_grant_amount'] / $validated['funding_per_business']);


            // Auto-set fields
            $validated['user_id'] = Auth::id();
            $validated['status'] = 'draft';
            $validated['funder_type'] = $grantUser->grant_profile->org_type; //draft
            $validated['visible'] = 1;
            $validated['currency'] = $validated['currency'] ?? 'KES';
            $validated['available_amount'] = $validated['total_grant_amount'];
            $validated['max_awardees'] = $maxAwardees;
            $validated['grant_type'] = $validated['grant_type'] ?? 'single_round';
            $validated['total_rounds'] = $validated['total_rounds'] ?? 1;

            $grant = Grant::create(
                Arr::except($validated, ['application_round', 'round_questions'])
            );

            // Create Application Round with helper fund
            $firstRound = $this->CreateApplicationRound(
                $validated['application_round'] ?? null,
                $validated['round_questions'] ?? null,
                $grant
            );

            //Upload file & update
            $path='files/grants/'.$grant->id;
            $grant_brief_pdf = $request->file('grant_brief_pdf');
            $grant_brief_pdf_path = $this->fileUpload->saveFile($grant_brief_pdf, $path);

            $uploadedFile = $grant_brief_pdf_path;
            $grant->update([ 'grant_brief_pdf' => $grant_brief_pdf_path ]);

            # Create dedicated wallet
            $wallet['total_deposited'] = 0.00; $wallet['total_disbursed'] = 0.00;
            $wallet['total_reserved'] = 0.00;  $wallet['balance'] = 0.00;
            $wallet['status'] = 'inactive';  // not active until funded
            $wallet['currency'] = $validated['currency'] ?? 'KES';
            $wallet['grant_id'] = $grant->id;

            GrantWallet::create($wallet);

            DB::commit();

            return response()->json([
                'message' => 'Grant created successfully',
                'grant' => $grant,
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


    public function duplicate(Grant $grant)
    {
        DB::beginTransaction();

        try {
            $user = Auth::user();

            // 1. Authorization (VERY IMPORTANT)
            if ($grant->user_id !== $user->id) {
                return response()->json([
                    'message' => 'Unauthorized action.'
                ], 403);
            }

            // 2. Load required relations
            $grant->load(['rounds.questions']);

            // 3. Replicate base grant
            $newGrant = $grant->replicate([
                'grant_title', 'status', 'available_amount', 'grant_brief_pdf', 'start_date', 'application_deadline',
                'created_at', 'updated_at'
            ]);

            // 4. Reset important fields
            $newGrant->status = 'draft';
            $newGrant->grant_title = $grant->grant_title . ' Copy';
            $newGrant->available_amount = $grant->total_grant_amount;
            $newGrant->visible = 0; // safer default
            $newGrant->start_date = null;
            $newGrant->application_deadline = null;
            $newGrant->push();

            // 5. Duplicate file (optional but recommended)
            /* if ($grant->grant_brief_pdf && file_exists($grant->grant_brief_pdf)) {
                $newPath = 'files/grants/' . $newGrant->id;

                $newFile = $this->fileUpload->copyFile(
                    $grant->grant_brief_pdf, $newPath
                );
                $newGrant->update([ 'grant_brief_pdf' => $newFile ]);
            } */

            // 6. Duplicate rounds + questions
            foreach ($grant->rounds as $round) {

                $newRound = $round->replicate([
                    'grant_id', 'open_date', 'close_date', 'created_at', 'updated_at'
                ]);

                $newRound->grant_id = $newGrant->id;
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
            GrantWallet::create([
                'total_deposited' => 0, 'total_disbursed' => 0, 'total_reserved' => 0, 'balance' => 0, 'status' => 'inactive',
                'currency' => $newGrant->currency, 'grant_id' => $newGrant->id,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Grant duplicated successfully',
                'grant' => $newGrant->load('rounds.questions')
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            ErrorLogService::report($e, [
                'grant_id' => $grant->id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    // Display the specified grant.

    public function show($id)
    {
        $grant = Grant::findOrFail($id);
        return response()->json($grant);
    }


    /**
     * Update the specified grant in storage.
     */

    public function store_watchlist($pitch_id)
    {
        try {
            $grant_owner_id = Auth::id(); // renamed
            $exist = GrantWatchlist::where('pitch_id', $pitch_id)
                ->where('grant_owner_id', $grant_owner_id)
                ->first();

            if ($exist) {
                GrantWatchlist::where('pitch_id', $pitch_id)
                    ->where('grant_owner_id', $grant_owner_id)
                    ->delete();

                return response()->json(['message' => 'Removed from watchlist'], 200);
            } else {
                GrantWatchlist::create([
                    'pitch_id' => $pitch_id,
                    'grant_owner_id' => $grant_owner_id
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
            $grant_owner_id = Auth::id(); // renamed
            $watchlists = GrantWatchlist::with('pitch')
                ->where('grant_owner_id', $grant_owner_id)
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

    // UPDATE GRANT
    public function update(Request $request, Grant $grant)
    {
        $userId = auth()->id();

        // Authorization
        if ($grant->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                // Use 'sometimes' - only validate if present in request, if not present, it won't be updated
                'grant_title' => 'sometimes|required|string|max:255',
                'total_grant_amount' => 'sometimes|required|numeric|min:0',
                'funding_per_business' => 'sometimes|required|numeric|min:0',

                'funder_name' => 'nullable|string|max:200',
                'eligibility_criteria' => 'sometimes|required|string',
                'required_documents' => 'sometimes|required||array',
                'grant_focus' => 'sometimes|required|array',
                'startup_stage_focus' => 'sometimes|required|array',
                'regions' => 'sometimes|required|array',
                'impact_objectives' => 'nullable|string',

                'grant_brief_pdf' => 'sometimes|file|mimes:pdf|max:2048',
                'start_date' => 'sometimes|required|date',
                'application_deadline' => 'sometimes|required|date|after:today',
                'currency' => 'nullable|string',
                'status' => 'sometimes|required|string',
                'total_rounds' => 'sometimes|required|numeric|min:1',

                'disbursement_type' => 'nullable|in:supplier,hybrid,beneficiary',
            ]);

            if($grant->grant_type == 'single_round' && $validated['total_rounds'] > 1) {
                throw new ValidationException([
                    'rounds' => 'Single rounds cannot be greater than 1'
                ]);
            }


            // Handle file upload if provided
            if ($request->hasFile('grant_brief_pdf')) {
                $path = 'files/grants/' . $grant->id;
                $grant_brief_pdf = $request->file('grant_brief_pdf');
                $validated['grant_brief_pdf'] = $this->fileUpload->saveFile($grant_brief_pdf, $path);
            }

            $grant->update($validated);

            DB::commit();

            if ( ($validated['status'] ?? null) === 'published' && $grant->getOriginal('status') !== 'published') {
                $this->grantNotification->send('grant.published', [$grant->user], [
                    'grant_title' => $grant->grant_title, 'grant_id' => $grant->id,
                ]);
            }

            if ( ($validated['status'] ?? null) === 'finalized' && $grant->getOriginal('status') !== 'finalized') {
                $awardedCount = $grant->applications()->where('status', 'awarded')->count();
                $this->grantNotification->send('grant.finalized', [$grant->user], [
                    'grant_title' => $grant->grant_title, 'awarded_count' => $awardedCount, 'grant_id' => $grant->id,
                ]);
            }

            return response()->json([
                'message' => 'Grant updated successfully',
                'data' => $grant,
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
     * Remove the specified grant from storage.
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $grant = Grant::findOrFail($id);

            // Authorization check (if needed)
            $userId = auth()->id();
            if ($grant->user_id !== $userId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Check for active applications
            if ($grant->activeApplications()->count() > 0) {
                return response()->json([
                    'message' => 'Grant cannot be deleted due to associated applications.'
                ], 422);
            }

            // Check if wallet has transactions
            $wallet = GrantWallet::where('grant_id', $grant->id)->first();
            if ($wallet) {
                if ($wallet->total_deposited > 0 || $wallet->total_disbursed > 0 || $wallet->total_reserved > 0) {
                    return response()->json([
                        'message' => 'Grant cannot be deleted because wallet has transaction history.'
                    ], 422);
                }

                // Delete wallet first (since it has RESTRICT foreign key)
                $wallet->delete();
            }

            // Delete grant brief file if exists
            if ($grant->grant_brief_pdf != null && file_exists($grant->grant_brief_pdf)) {
                unlink($grant->grant_brief_pdf);
            }

            // Delete rounds (cascade will handle round_reviewers, round_custom_questions, etc.)
            $grant->rounds()->delete();

            // Delete grant
            $grant->delete();

            DB::commit();

            return response()->json(['message' => 'Grant deleted successfully'], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Grant not found'], 404);

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

    public function get_grant($id)
    {
        try{
            $grant = Grant::with([
                'rounds.questions',
                'wallet'
            ])
                ->withCount('liked')
                ->find($id);

            //  Handle not found
            if (!$grant) {
                return response()->json([
                    'message' => 'Grant not found'
                ], 404);
            }

            //  No extra query (use loaded relation)
            $round1 = $grant->rounds->first();

            //  Better naming (no overwrite)
            if (Auth::check()) {
                $grant->liked = $grant->liked()
                    ->where('user_id', Auth::id())
                    ->exists();
            }

            return response()->json([
                'grant_data' => $grant,
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

    public function visibility($grant_id)
    {
        try {
            $grant = Grant::where('id',$grant_id)->latest()->first();
            if ($grant->visible == 1) {
                Grant::where('id', $grant_id)->update([
                    'visible' => 0
                ]);
            }
            else{
                Grant::where('id', $grant_id)->update([
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
            $grant_profile = GrantProfile::where('user_id', $user->id)->update([
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

            $grantProfile = $user->grant_profile;
            if (!$grantProfile || Auth::id() !== $grantProfile->user_id || $grantProfile->role_id) {
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


            $grant_profiles = GrantProfile::where('user_id',$request->id)
                ->orWhere('grant_owner_id',$request->id)->get();

            GrantApplication::where('user_id',$request->id)->delete();

            if($user->user_type_id == 2){ //Grant
                foreach ($grant_profiles as $profile) {
                    if ($profile->user_id === $user->id) {
                        continue; // skip owner
                    }
                    $profile->user?->delete();
                    $profile->delete();
                }
                $user->delete();

                //Delete Grants/files/pitches/bookings/Messages
                $grants = Grant::where('user_id', $user->id)->get();
                foreach ($grants as $grant) {
                    $filePath = public_path($grant->grant_brief_pdf);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                    $grant->delete();
                }

                serviceBook::where('booker_id', $user->id)->delete();
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

            if($user->user_type_id == 2) //Grant
                GrantProfile::where('user_id',$request->id)->delete();
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

            $user = User::with('grant_profile')->where('email',$email)->first();
            $user?->grant_profile?->delete();
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
            $user = Auth::user();
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

            // Prepare Grant data
            $grantProfileData = [
                'org_type' => $request->org_type,
                'mission'  => $request->mission,
                'regions'  => $request->regions,
            ];

            // Update or create CapitalProfile
            $user->grant_profile()->updateOrCreate(
                ['user_id' => $user->id],
                $grantProfileData
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
            //$sme_emails = array(); $grant_owner_emails = array();
            $pitch_ids = $request->pitch_ids;
            $total_amount_db = 0; $curr='USD';
            $total_amount_db = GrantMilestone::whereIn('app_id', $pitch_ids)
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
                "description" => "Bulk Release Grant Milestone Funds"
            ]);
            //C h a r g e  Setup Ends

            foreach($pitch_ids as $pitch_id)
            {
                $pitch = GrantApplication::with('grant')->find($pitch_id);
                $milestone = GrantMilestone::select('id','title','app_id','amount','status')
                ->where('app_id',$pitch->id)->orderBy('id', 'asc')->first();

                if($milestone->status === 1)
                {
                    continue;
                }

                $emails = User::whereIn('id', [$pitch->user_id, $pitch->grant_owner_id])
                    ->pluck('email', 'id');

                $connectIds = User::whereIn('id', [$pitch->user_id, $pitch->grant_owner_id])
                    ->pluck('connect_id', 'id');

                //Getting Credentials
                $sme_email = $emails[$pitch->user_id];
                $grant_owner_email = $emails[$pitch->grant_owner_id];
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
                    $grantUp = Grant::where('id', $pitch->grant_id)->update([
                        'available_amount' => DB::raw("available_amount - {$milestone->amount}")
                    ]);

                    //Update User Wallet
                    $balanceService = new BalanceService();
                    $newBalance = $balanceService
                        ->updateBalance($pitch->user_id, (float)$milestone->amount, 'Release Milestone');
                    //Update User Wallet

                    $text = $milestone->title . ' fund for ' . $pitch->grant->grant_title . ' has been released.';
                    $notification = new NotificationService();
                    $notification->create($pitch->user_id, $pitch->grant->user_id, $text
                        , 'dashboard.entrepreneur.grantsDealroom.detail::'.$pitch_id, ' grant');

                    // E M A I L
                    $info=[
                        'grant'=>$pitch->grant->grant_title,
                        'amount'=>$milestone->amount,
                        'milestone_title' => $milestone->title
                    ];
                    $user['to'] = [$grant_owner_email, $sme_email]; //'tottenham266@gmail.com';
                    Mail::send('opportunities.grant_milestone', $info, function($msg) use ($user){
                        $msg->to($user['to']);
                        $msg->subject(' Grant Milestone');
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
    public function CreateApplicationRound($roundData, $questions, $grant){
        // CREATE FIRST ROUND AUTOMATICALLY
        $round = GrantRound::create([
            'grant_id' => $grant->id,
            'round_name' => $roundData['round_name'] ?? 'Public Application Round',
            'round_number' => 1,
            'open_date' => $grant->start_date ,
            'close_date' => $grant->application_deadline,
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

        $grant->status = 'published';
        $grant->save();

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
