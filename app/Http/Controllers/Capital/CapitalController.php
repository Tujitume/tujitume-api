<?php

namespace App\Http\Controllers\Capital;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Capital\CapitalMilestone;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\CapitalProfile;
use App\Models\Capital\CapitalTermsAgreement;
use App\Models\Capital\CapitalWatchlist;
use App\Models\Capital\StartupPitches;
use App\Models\Communication\Messages;
use App\Models\Finance\BalanceLog;
use App\Models\Grants\GrantProfile;
use App\Models\Services\serviceBook;
use App\Models\Shared\Watchlist;
use App\Service\Account\AccountDeletionEligibilityService;
use App\Service\Balance\BalanceService;
use App\Service\File\ImageCompressor;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\NotificationService;
use Dotenv\Exception\ValidationException;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Mail;
use Response;
use Session;
use Stripe\StripeClient;

class CapitalController extends Controller
{
    /**
     * Display a listing of capital.
     */
    protected $Client;

    public function __construct(StripeClient $client)
    {
        parent::__construct();
        $this->Client = $client;
        $this->balance = new BalanceService();

    }

    public function index()
    {
        if(Auth::check()){
            $user_id = Auth::id();
            $user = User::select('user_type_id','id')->where('id',$user_id)->first();
            if($user->user_type_id == 3){
                $user = $this->get_role();

                if (in_array($user->role, ['editor', 'viewer', 'admin'])) {
                    $capitals = CapitalOffer::withCount('liked')->where('user_id',$user->capital_owner_id)->latest()->get();
                    $watchlistCapitalIds = Watchlist::where('user_id', $user->capital_owner_id)->pluck('org_id')->toArray();
                }
                else{
                    $capitals = CapitalOffer::withCount('liked')->where('user_id',$user->id)->latest()->get();
                    $watchlistCapitalIds = Watchlist::where('user_id', $user->id)->pluck('org_id')->toArray();
                }

                foreach ($capitals as $capital){
                    $pitches = StartupPitches::where('capital_id',$capital->id)->count();
                    $capital->in_my_watchlist = in_array($capital->id, $watchlistCapitalIds);
                    $capital->liked = $capital->liked()->where('user_id', $user_id)->exists();
                    $capital->pitch_count = $pitches;
                    $capital->owner_website = $capital->owner->website;
                }
                return response()->json(['capital' => $capitals]);
            }

        }
        $capitals = CapitalOffer::withCount('liked')->get();
        foreach ($capitals as $capital){
            $pitches = StartupPitches::where('capital_id',$capital->id)->count();
            $capital->pitch_count = $pitches;
            $capital->owner_website = $capital->owner->website;
        }
        return response()->json(['capital' => $capitals]);
    }

    public function get_capital($id)
    {
        try{
            $capital = CapitalOffer::withCount('liked')->find($id);
            if(Auth::check()) {
                $user_id = Auth::id();
                $capital->liked = $capital->liked()
                    ->where('user_id', $user_id)
                    ->exists();
            }
            return response()->json(['capital-data' => $capital],200);
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

    public function visibility($capital_id)
    {
        try {
            $capital = CapitalOffer::where('id',$capital_id)->first();
            if ($capital->visible == 1) {
                CapitalOffer::where('id',$capital_id)->update([
                    'visible' => 0
                ]);
            }
            else{
                CapitalOffer::where('id',$capital_id)->update([
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

    public function pitches($capital_id)
    {
        $user_id = Auth::id();
        if($capital_id == 'latest'){
            $pitches = StartupPitches::with('capital_milestones')->where('user_id',$user_id)->latest()->get();
            return response()->json(['pitches' => $pitches]);
        }

        $pitches = StartupPitches::with('capital_milestones')->where('capital_id',$capital_id)->latest()->get();
        return response()->json(['pitches' => $pitches]);
    }

    #Signle Pitch Details
    public function pitch_details($id)
    {
        try{
            $pitch = StartupPitches::with('capital_milestones')->where('id',$id)->first();
            return response()->json(['pitch' => $pitch]);
        }
        catch ( \Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function mypitches()
    {
        $match_score = 0;
        $user = $this->get_role();
        if (in_array($user->role, ['editor', 'viewer', 'admin'])) {
            $watchlistPitchIds = CapitalWatchlist::where('capital_owner_id', $user->capital_owner_id)->pluck('pitch_id')->toArray();
            $pitches = StartupPitches::with('capital_offer')
                ->with('capital_milestones')->where('capital_owner_id',$user->capital_owner_id)->latest()->get();
        }
        else if ($user->user_type_id == 3){
            $watchlistPitchIds = CapitalWatchlist::where('capital_owner_id', $user->id)->pluck('pitch_id')->toArray();
            $pitches = StartupPitches::with([
                'capital_offer',
                'capital_milestones'
            ])->where('capital_owner_id',$user->id)->latest()->get();
        }
        else {
            $watchlistPitchIds = [];
            $pitches = StartupPitches::with([
                'capital_offer',
                'capital_milestones'
            ])->where('user_id',$user->id)->latest()->get();
        }


        foreach ($pitches as $pitch){
            $pitch->in_my_watchlist = in_array($pitch->id, $watchlistPitchIds);
        }

        $match_score = $pitches->sum('score');
        $pitch_count = $pitches->count();
        $pitch_count_accept = $pitches->where('status', 1)->count();
        $pitch_count_pending = $pitch_count-$pitch_count_accept;
        $avg_match_score = round($pitch_count > 0 ? $match_score/$pitch_count : 0, 2);;
        $accept_rate = $pitch_count > 0
            ? round(($pitch_count_accept / $pitch_count) * 100, 2) : 0;

        return response()->json([
            'pitches' => $pitches,
            'avg_match_score' => $avg_match_score,
            'accept_rate' => $accept_rate,
            'pitch_count' => $pitch_count,
            'pitch_count_pending' => $pitch_count_pending,
        ]);
    }


    /**
     * Store a newly created Capital in storage.
     */
    public function store(Request $request)
    {


        try{
            $request->validate([
            'offer_title' => 'required|string|max:255',
            'total_capital_available' => 'required|numeric',
            'per_startup_allocation' => 'required|numeric',
            'milestone_requirements' => 'nullable|string',
            'startup_stage' => 'required|array',
            'sectors' => 'required|array',
            'regions' => 'required|array',
            'impact_objectives' => 'required|nullable|string',
            'required_docs' => 'nullable|string',
            'offer_brief_file' => 'nullable|file|mimes:pdf|max:2048',
            "end_date" => "nullable|string",
            "start_date" => "nullable|string",
        ]);

            $capital = CapitalOffer::create([
                'user_id' => Auth::id(),
                'offer_title' => $request->offer_title,
                'total_capital_available' => $request->total_capital_available,
                'available_amount' => $request->total_capital_available,
                'per_startup_allocation' => $request->per_startup_allocation,
                'milestone_requirements' => $request->milestone_requirements,
                'startup_stage' => $request->startup_stage,
                'sectors' => $request->sectors,
                'regions' => $request->regions,
                'impact_objectives' => $request->impact_objectives,
                'required_docs' => $request->required_docs,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
            //'offer_brief_file' => $request->offer_brief_file,
        ]);

            //Upload File
            $offer_brief_file = $request->file('offer_brief_file');
            if (!file_exists('files/capitals/'.$capital->id))
                mkdir('files/capitals/'.$capital->id, 0777, true);

            $loc='files/capitals/'.$capital->id.'/';
            if($offer_brief_file) {
                $uniqid=hexdec(uniqid());
                $ext=strtolower($offer_brief_file->getClientOriginalExtension());
                $create_name=$uniqid.'.'.$ext;
                $offer_brief_file->move($loc, $create_name);
                $final_pdf=$loc.$create_name;
            }
            else $final_pdf='';
            CapitalOffer::where('id',$capital->id)->update([
                'offer_brief_file' => $final_pdf
            ]);

            return response()->json(['message' => 'List Capital created successfully'], 200);
        }
        catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.', 'errors'  => $e->errors()
            ], 422);
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

    // Store Capital Application
    public function store_application(Request $request)
    {

        try{

            $request->validate([
                'capital_id' => 'required|integer|exists:capital_offers,id',
                'business_id' => 'required|integer|exists:listings,id',
                'startup_name' => 'required|string|max:255',
                'contact_person_name' => 'required|string|max:100',
                'contact_person_email' => 'required|email|max:100',
                'sector' => 'required|string|max:255',
                'headquarters_location' => 'required|string|max:255',
                'stage' => 'required|string|max:200',
                'revenue_last_12_months' => 'nullable|numeric',
                'team_experience_avg_years' => 'nullable|integer',
                'traction_kpis' => 'nullable|string',
                'pitch_deck_file' => 'nullable|file|mimes:pdf,docx|max:2048',
                'pitch_video_file' => 'nullable|file|mimes:mp4,avi,mkv|max:2048',
                'business_plan' => 'nullable|file|mimes:pdf,docx|max:2048',
                'social_impact_areas' => 'nullable|array',
                'cac_ltv' => 'nullable|string',
                'burn_rate' => 'required|numeric',
                'irr_projection' => 'nullable|numeric',
                'exit_strategy' => 'nullable|string',
                'milestones' => 'nullable|array',
                'score' => 'nullable|numeric',
                'total_amount_requested' => 'nullable|numeric',
                'score_breakdown' => ['required', 'regex:/^(\d{1,3},){5}\d{1,3}$/'],
            ]);

            $this_capital = CapitalOffer::select('id', 'offer_title', 'user_id')->where('id', $request->capital_id)->first();
            $capital_owner_id = $this_capital->user_id;
            $capital_owner_email = User::select('email')->where('id',$capital_owner_id)->first()->email;

            $capital = StartupPitches::create([
                'user_id' => Auth::id(),
                'capital_id' => $request->capital_id,
                'business_id' => $request->business_id,
                'capital_owner_id' => $capital_owner_id,
                'startup_name' => $request->startup_name,
                'contact_person_name' => $request->contact_person_name,
                'contact_person_email' => $request->contact_person_email,
                'sector' => $request->sector,
                'headquarters_location' => $request->headquarters_location,
                'stage' => $request->stage,
                'revenue_last_12_months' => $request->revenue_last_12_months,
                'team_experience_avg_years' => $request->team_experience_avg_years,
                'traction_kpis' => $request->traction_kpis,
                //'pitch_deck_file' => $request->pitch_deck_file,
                //'pitch_video' => $request->pitch_video,
                //'business_plan' => $request->business_plan,
                'social_impact_areas' => $request->social_impact_areas,
                'cac_ltv' => $request->cac_ltv,
                'burn_rate' => $request->burn_rate,
                'irr_projection' => $request->irr_projection,
                'exit_strategy' => $request->exit_strategy,
                'score' => $request->score,
                'score_breakdown' => $request->score_breakdown,
                'total_amount_requested' => $request->total_amount_requested,
            ]);

            //Upload Files
            $pitch_deck_file = $request->file('pitch_deck_file');
            $pitch_video = $request->file('pitch_video_file');
            $business_plan_file = $request->file('business_plan');

            if (!file_exists('files/capitalPitches/'.$capital->id))
                mkdir('files/capitalPitches/'.$capital->id, 0777, true);
            $loc='files/capitalPitches/'.$capital->id.'/';

            if($pitch_deck_file) {
                $uniqid=hexdec(uniqid());
                $ext=strtolower($pitch_deck_file->getClientOriginalExtension());
                $create_name=$uniqid.'.'.$ext;
                $pitch_deck_file->move($loc, $create_name);
                $pitch_deck_path=$loc.$create_name;
            }
            else $pitch_deck_path='';

            if($pitch_video) {
                $uniqid=hexdec(uniqid());
                $ext=strtolower($pitch_video->getClientOriginalExtension());
                $create_name=$uniqid.'.'.$ext;
                $pitch_video->move($loc, $create_name);
                $pitch_video_path=$loc.$create_name;
            }
            else $pitch_video_path='';

            if($business_plan_file) {
                $uniqid=hexdec(uniqid());
                $ext=strtolower($business_plan_file->getClientOriginalExtension());
                $create_name=$uniqid.'.'.$ext;
                $business_plan_file->move($loc, $create_name);
                $business_plan_path=$loc.$create_name;
            }
            else $business_plan_path='';

            StartupPitches::where('id',$capital->id)->update([
                'pitch_deck_file' => $pitch_deck_path,
                'pitch_video' => $pitch_video_path,
                'business_plan' => $business_plan_path
            ]);

            if (!file_exists('files/capitalMiles/'.$capital->id))
                mkdir('files/capitalMiles/'.$capital->id, 0777, true);
            $loc='files/capitalMiles/'.$capital->id.'/';

            // M I L E S T O N E S
            $milestones = $request->milestones; $mile_cnt = 0;
            foreach($milestones as $milestone){
                $document = $request->file('milestones.' .$mile_cnt. '.deliverables.file');
                if($document) {
                    $uniqid=hexdec(uniqid());
                    $ext=strtolower($document->getClientOriginalExtension());
                    $create_name=$uniqid.'.'.$ext;
                    $document->move($loc, $create_name);
                    $document=$loc.$create_name;
                }
                else $document='';
                $mile = CapitalMilestone::create([
                    'app_id' => $capital->id,
                    'title' => $milestone['title'],
                    'amount' => $milestone['amount'],
                    'description' => $milestone['description'],
                    'document' => $document
                ]);
                $mile_cnt++;
            }

            $text = 'You have a new application pitch.';
            $notification = new NotificationService();
            $notification->create($capital_owner_id,$capital->user_id,$text
                ,'overview/capital-pitch?='.$request->pitch_id,' capital');

            // E M A I L
            $info=[ 'capital'=>$this_capital->offer_title, 'SME'=>$request->startup_name ];
            $user['to'] = $capital_owner_email; //'tottenham266@gmail.com'; //
            Mail::send('opportunities.capital_pitch', $info, function($msg) use ($user){
                $msg->to($user['to']);
                $msg->subject('Capital Pitch Received');
            });
            // E M A I L

            return response()->json(['message' => 'Investment Application Successfull.'], 200);
        }
        catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.', 'errors'  => $e->errors()
            ], 422);
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


    // Display the specified capital.

    public function show($id)
    {
        $capital = CapitalOffer::findOrFail($id);
        return response()->json($capital);
    }


    /**
     * Update the specified capital in storage.
     */
    public function update(Request $request)
    {
        try{
            $capital = CapitalOffer::findOrFail($request->id);

            $request->validate([
                "id" => "required|numeric",
                'offer_title' => 'required|string|max:255',
                'per_startup_allocation' => 'required|numeric',
                'milestone_requirements' => 'nullable|string',
                'startup_stage' => 'required|string',
                'sectors' => 'required|string',
                'regions' => 'required|string',
                'required_docs' => 'nullable|string',
                //'offer_brief_file' => 'nullable|file|mimes:pdf|max:2048',
            ]);
            $data = $request->except('offer_brief_file');

            //Upload File
            $offer_brief_file = $request->file('offer_brief_file');
            if (!file_exists('files/capitals/'.$capital->id))
                mkdir('files/capitals/'.$capital->id, 0777, true);

            $loc='files/capitals/'.$capital->id.'/';
            if($offer_brief_file) {
                $uniqid=hexdec(uniqid());
                $ext=strtolower($offer_brief_file->getClientOriginalExtension());
                $create_name=$uniqid.'.'.$ext;
                $offer_brief_file->move($loc, $create_name);
                $final_pdf=$loc.$create_name;
                $data['offer_brief_file'] = $final_pdf;

                if($capital->offer_brief_file !=null && file_exists($capital->offer_brief_file)){
                    unlink($capital->offer_brief_file);
                }
            }

            $capital->update($data);
            return response()->json(['message' => 'Capital updated successfully', 'capital' => $capital],200);
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

    /**
     * Remove the specified capital from storage.
     */
    public function destroy($id)
    {
        $capital = CapitalOffer::findOrFail($id);
        if($capital->offer_brief_file !=null && file_exists($capital->offer_brief_file)){
            unlink($capital->offer_brief_file);
        }
        $capital->delete();

        return response()->json(['message' => 'Capital Offer deleted successfully']);
    }

    public function accept($pitch_id)
    {
        try{
            $pitch = StartupPitches::with('capital_offer')->where('id',$pitch_id)->first();
            $app = StartupPitches::where('id',$pitch_id)
                ->update([
                    'status' => 1
                ]);
            $text = 'Your application to the Capital'.$pitch->capital_offer->offer_title.'
                 has been accepted. You can now connect with the Capital owner';
            $notification = new NotificationService();
            $notification->create($pitch->user_id,$pitch->capital_offer->user_id,$text
                ,'overview/funding/investments',' capital');

            return response()->json(['message' => 'Pitch Accepted.'], 200);
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


    public function reject($pitch_id)
    {
        try{
            $pitch = StartupPitches::with('capital_offer')->where('id',$pitch_id)->first();
            $text = 'Your application to the Capital'.$pitch->capital_offer->offer_title.'
                 has been accepted. You can now connect with the Capital owner';
            $notification = new NotificationService();
            $notification->create($pitch->user_id,$pitch->capital_offer->user_id,$text
                ,'capital-overview/capital/discover',' capital');
            StartupPitches::where('id',$pitch_id)->delete();

            return response()->json(['message' => 'Pitch Rejected.'], 200);
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


    public function store_watchlist($pitch_id)
    {
        try{
            $capital_owner_id = Auth::id();
            $exist = CapitalWatchlist::where('pitch_id',$pitch_id)->where('capital_owner_id',$capital_owner_id)->first();
            if ($exist){
                CapitalWatchlist::where('pitch_id',$pitch_id)->where('capital_owner_id',$capital_owner_id)->delete();
                return response()->json(['message' => 'Removed from watchlist'],200);
            }
            else
            {
                $watchlist = CapitalWatchlist::create([
                    'pitch_id' => $pitch_id,
                    'capital_owner_id' => $capital_owner_id
                ]);
                return response()->json(['message' => 'Added to watchlist'],200);
            }
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

    public function get_watchlist()
    {
        try{
            $capital_owner_id = Auth::id();
            $watchlists = CapitalWatchlist::with('pitch')
                ->where('capital_owner_id', $capital_owner_id)
                ->latest()->get();
            $pitches = $watchlists->pluck('pitch')->filter()->values();
            return response()->json(['pitches' => $pitches],200);
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

    public function update_profile(Request $request,  ImageCompressor $obj)
    {
        try{
            $user = Auth::user();
            $request->validate([
                'fname' => 'required|string|max:255', // Org name
                'interested_cats' => 'array', // Preferred Sectors
                'org_type' => 'required|string',
                'phone' => 'string|max:20',
                'startup_stage' => 'array', //Startup Stage Preferences
                'inv_range' => 'array', //Investment Range
                'eng_prefer' => 'array', //Preferred Engagement Types
                'regions' => 'array', // Geographic Focus
                'website' => 'nullable|url',
            ]);

            $user->update([
                'fname' => $request->fname,
                'interested_cats' => $request->interested_cats,
                'phone' => $request->phone,
                'inv_range' => $request->inv_range,
                'website' => $request->website,
            ]);

            // Prepare CapitalProfile data
            $capitalProfileData = [
                'org_type' => $request->org_type,
                'startup_stage' => $request->startup_stage,
                'eng_prefer' => $request->eng_prefer,
                'regions' => $request->regions,
            ];

            // Update or create CapitalProfile
            $user->capital_profile()->updateOrCreate(
                ['user_id' => $user->id],
                $capitalProfileData
            );

            //Update Image
            $image=$request->file('image');
            if($image) {
                $ext=strtolower($image->getClientOriginalExtension());
                $create_name = hexdec(uniqid()).'.'.$ext;
                $loc='images/users/';
                $final_img=$this->api_base_url.$loc.$create_name;
                $compressedImage = $obj->compressImage($image, $loc.$create_name, 60);

                if($user->image!=null && file_exists($user->image))
                {
                    unlink($user->image);
                }
                $user->update([
                    'image' => $final_img,
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
            $capital_profile = CapitalProfile::where('user_id', $user->id)
                ->update([
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

            $capitalProfile = $user->capital_profile;
            if (!$capitalProfile || Auth::id() !== $capitalProfile->user_id || $capitalProfile->role_id !== null) {
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


            $capital_profiles = CapitalProfile::where('user_id',$request->id)
                ->orWhere('capital_owner_id',$request->id)->get();

            //Capital Pitches/Offers/Bookings/Messages
            StartupPitches::where('user_id',$request->id)->delete();

            if($user->user_type_id == 3){ //Capital
                foreach ($capital_profiles as $profile) {
                    if ($profile->user_id === $user->id) {
                        continue; // skip owner
                    }
                    $profile->user?->delete();
                    $profile->delete();
                }
                $user->delete();

                //Delete Capitals/files/pitches/bookings/Messages
                $capitals = CapitalOffer::where('user_id', $user->id)->get();
                foreach ($capitals as $capital) {
                    $filePath = public_path($capital->offer_brief_pdf);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                    $capital->delete();
                }

                serviceBook::where('booker_id', $user->id)->delete();
                Messages::where('to_id', $user->id)->orWhere('from_id', $user->id)->delete();
            }

            DB::commit();
            return response()->json([ 'message' => 'Account deleted.'], 200);
        }
        catch (\Exception $e){
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

    public function terms_agreements($pitch_id)
    {
        try {
            $agreements = CapitalTermsAgreement::with('capital')->where('pitch_id',$pitch_id)->latest()->get();
            if ($agreements->isNotEmpty()) {
                $agreements->first()->latest = true;
            }
            return response()->json(['agreements' => $agreements], 200);
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

    public function terms_agreements_store(Request $request, NotificationService $alert)
    {
        try {
            $request->validate([
                'capital_id' => ['required', 'exists:capital_offers,id', 'integer'],
                'pitch_id' => ['required', 'exists:startup_pitches,id', 'integer'],
                'document' => ['required', 'file', 'mimes:pdf,doc,docx'],
                'amount' => ['nullable', 'numeric', 'min:0'],
                'status' => ['nullable', 'in:submitted,accepted,rejected,counter_submitted'],
            ]);
            $pitch = StartupPitches::findOrFail($request->pitch_id);
            $emails = User::whereIn('id', [$pitch->user_id, $pitch->capital_owner_id])
                ->pluck('email', 'id');

            $business_owner_id = $pitch->user_id;
            $capital_owner_id = $pitch->capital_owner_id;

            $businessOwner = User::find($pitch->user_id);
            $capitalOwner  = User::find($pitch->capital_owner_id);

            if (!$businessOwner || !$capitalOwner) {
                return response()->json(['One or more users linked to this pitch no longer exist.'], 400);
            }

            $business_owner_email = $emails[$pitch->user_id];
            $capital_owner_email = $emails[$pitch->capital_owner_id];



            //Upload Document
            $terms_document = $request->file('document');
            if($terms_document) {
                $folder = public_path('files/capitalAgreements/'.$request->pitch_id);
                if (!file_exists($folder))
                    mkdir($folder, 0777, true);

                $file_name=hexdec(uniqid()). '.' .strtolower($terms_document->getClientOriginalExtension());
                $terms_document->move($folder, $file_name);
                $dir_name='files/capitalAgreements/'. $request->pitch_id. '/' .$file_name;
            }

            $agreement = CapitalTermsAgreement::create([
                'capital_id' => $request->capital_id,
                'pitch_id' => $request->pitch_id,
                'business_owner_id' => $business_owner_id,
                'capital_owner_id' => $capital_owner_id,
                'document' => $dir_name ?? null,
                'amount' => $request->amount,
                'status' => $request->status ?? 'submitted', // default
            ]);

            //NotificationService
            if($request->status == 'submitted')
            {
                $text = 'The capital owner submitted terms document for your application of '.$pitch->startup_name;
                $alert->create(
                    $business_owner_id, $capital_owner_id,
                    $text, 'overview/pitch-agreements', 'capital'
                );
            }
            else if($request->status == 'counter_submitted')
            {
                $text = 'The business owner counter submitted an offer for capital '.$pitch->capital_offer->offer_title;
                $alert->create(
                    $capital_owner_id,$business_owner_id,
                    $text, 'overview/pitch-agreements?='.$request->pitch_id, 'capital'
                );
            }

            //E M A I L
            $info=[
                'capital'=>$pitch->capital_offer->offer_title,
                'startup'=>$pitch->startup_name,
                'status'=>$request->status,
            ];
            if($request->status === 'submitted')
                $user['to'] = $business_owner_email; //'tottenham266@gmail.com';
            else if($request->status === 'counter_submitted')
                $user['to'] = $capital_owner_email;

            Mail::send('opportunities.agreement_mail', $info, function($msg) use ($user){
                $msg->to($user['to']);
                $msg->subject('Terms Agreement Offer');
            });
            return response()->json(['message' => 'Offer submitted success.'], 200);
        }
        catch(\Exception $e){
            if (!empty($dir_name) && file_exists(public_path($dir_name))) {
                unlink(public_path($dir_name));
            }
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }

    }

    public function terms_agreements_update(Request $request, NotificationService $alert)
    {
        try {
            $pitch = StartupPitches::findOrFail($request->pitch_id);

            if($request->status == 'accepted')
            {
                $request->validate([
                    'agreement_id' => ['required', 'exists:capital_terms_agreements,id', 'integer'],
                    'pitch_id' => ['required', 'exists:startup_pitches,id', 'integer'],
                    'document' => ['required', 'file', 'mimes:pdf,doc,docx'],
                    'status' => ['required', 'in:accepted,rejected']
                ]);
                $text = 'Your terms and agreement offer was accepted for application '.$pitch->startup_name. ' of '. $pitch->capital_offer->offer_title;
            }
            else if($request->status == 'rejected')
            {
                $request->validate([
                    'agreement_id' => ['required', 'exists:capital_terms_agreements,id', 'integer'],
                    'pitch_id' => ['required', 'exists:startup_pitches,id', 'integer'],
                    'status' => ['required', 'in:accepted,rejected']
                ]);
                $text = 'Your terms and agreement offer was rejected for application '.$pitch->startup_name. ' of '. $pitch->capital_offer->offer_title;
            }

            $emails = User::whereIn('id', [$pitch->user_id, $pitch->capital_owner_id])
                ->pluck('email', 'id');
            $business_owner_id = $pitch->user_id;
            $capital_owner_id = $pitch->capital_owner_id;
            $business_owner_email = $emails[$pitch->user_id];
            $capital_owner_email = $emails[$pitch->capital_owner_id];

            //Upload Document
            $terms_document = $request->file('document');
            if($terms_document) {
                $folder = public_path('files/capitalAgreements/'.$request->pitch_id);
                if (!file_exists($folder))
                    mkdir($folder, 0777, true);

                $file_name=hexdec(uniqid()). '.' .strtolower($terms_document->getClientOriginalExtension());
                $terms_document->move($folder, $file_name);
                $dir_name='files/capitalAgreements/'. $request->pitch_id. '/' .$file_name;
            }

            CapitalTermsAgreement::where('id',$request->agreement_id)->update([
                'status' => $request->status,
                'document' => $dir_name ?? null,
                'reason' => $request->reason ?? null,
            ]);
            //Unlink Old

            $pitch->update([ 'terms_agreed' => 1 ]);

            $receiver_id = $business_owner_id == Auth::id() ? $capital_owner_id: $business_owner_id;
            $user['to'] = $business_owner_id == Auth::id() ? $capital_owner_email: $business_owner_email;

            $alert->create(
                $receiver_id, $capital_owner_id,
                $text, 'overview/pitch-agreements?='.$request->pitch_id, 'capital'
            );

            $info=[
                'capital'=>$pitch->capital_offer->offer_title,
                'startup'=>$pitch->startup_name,
                'status'=>$request->status,
            ];
            Mail::send('opportunities.agreement_mail', $info, function($msg) use ($user){
                $msg->to($user['to']);
                $msg->subject('Terms Agreement Offer');
            });
            return response()->json(['message' => 'Offer accepted.'], 200);
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
    public function get_role()
    {
        $user = Auth::user()->load('capital_profile.role');
        $user->role = $user->capital_profile?->role?->name ?? 'super-admin';
        $user->capital_owner_id = $user->capital_profile?->capital_owner_id;
        return $user;
    }



//Class
}
