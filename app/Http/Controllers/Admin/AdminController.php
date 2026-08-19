<?php

namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalProfile;
use App\Models\Communication\Messages;
use App\Models\Finance\BalanceLog;
use App\Models\Programs\ProgramProfile;
use App\Models\Services\ServiceMessages;
use App\Models\Services\Services;
use App\Models\Shared\ErrorLog;

use App\Models\Capital\CapitalOffer;
use App\Models\Finance\Transactions;
use App\Models\Programs\Program;
use App\Models\Milestones\Dispute;
use App\Models\Misc\Event;
use App\Models\Misc\Prospects;
use App\Models\Misc\Reports;
use App\Models\Services\ServiceBooking;
use App\Service\Account\AccountDeletionEligibilityService;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\BulkEmailService;
use DB;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Mail;
use Response;
use stdClass;

class AdminController extends Controller
{
    // ==================== Login / Logout ====================
    public function login()
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }
        return view('admin.login');
    }

    public function adminLogin(Request $request)
    {
        try {
            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            if (Auth::guard('admin')->attempt($credentials)) {
                $request->session()->regenerate();
                return redirect()->route('admin.dashboard');
            }

            return redirect()->back()->with('auth_failed', 'Invalid credentials!');
        } catch (\Exception $e) {
            Session::put('auth_failed', $e->getMessage());
            return redirect()->back();
        }
    }

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('admin.login');
    }

    // ==================== Dashboard ====================
    public function index()
    {
        $artists = User::all();
        $users = [];
        return view('admin.index_admin', compact('artists', 'users'));
    }

    // ==================== Users ====================
    public function users()
    {
        $users = User::all();
        foreach ($users as $user) {
            $user->investedBusiness = DB::table('business_bids')
                ->where('investor_id', $user->id)
                ->join('listings', 'business_bids.business_id', '=', 'listings.id')
                ->select('business_bids.*', 'listings.name')
                ->get();

            $user->activeBusiness = DB::table('business_bids')
                ->where('owner_id', $user->id)
                ->join('listings', 'business_bids.business_id', '=', 'listings.id')
                ->groupBy('business_bids.business_id')
                ->select('business_bids.*', 'listings.name', 'listings.investment_needed', 'listings.category')
                ->get();

            $user->bookedServices = DB::table('service_bookings')
                ->where('booker_id', $user->id)
                ->join('services', 'service_bookings.service_id', '=', 'services.id')
                ->select('service_bookings.*', 'services.name', 'services.price')
                ->get();
        }

        return view('admin.users', compact('users'));
    }

    public function listings_active()
    {
        $acceptedBids = AcceptedBids::groupBy('business_id')->latest()->get();

        $businesses = new stdClass; $i=0;$j=0;
        foreach($acceptedBids as $aBid){
            $row = DB::table('listings')
                ->where('listings.id',$aBid->business_id)
                ->join('users', 'listings.user_id', '=', 'users.id')
                ->select('listings.*', 'users.*')
                ->get();
            // ->join('milestones', 'listings.id', '=', 'milestones.listings_id')

            if(isset($row[0])){
//                $investors = $acceptedBids->groupBy('investor_id')
//                    ->where('business_id',$aBid->business_id)->unique()->count();
//                $row->count =$investors;
                $businesses->$i = $row;
                $i++;
                $j++;
            }
        }
        $count = $j;
        //return $businesses;

        return view('admin.listings_active',compact('businesses','count'));
    }


    public function services_active()
    {
        $acceptedBids = ServiceBooking::groupBy('service_id')->latest()->get();

        $businesses = new stdClass; $i=0;$j=0;
        foreach($acceptedBids as $booking){
            $row = DB::table('services')
                ->where('services.id',$booking->service_id)
                ->join('users', 'services.user_id', '=', 'users.id')
                ->select('services.*', 'users.*')
                ->get();


            //return $row[0];
            if(isset($row[0])){
                $businesses->$i = $row;$i++;
                $j++;
            }

        }
        $count = $j;
        //return $count;
        //return $businesses;

        return view('admin.services_active',compact('businesses', 'count'));
    }


    public function deleteUser(Request $id)
    {
        $user = User::with('balance')->find($id);
        $type = $user->user_type_id;

//        if($id != Auth::id()){
//            return response(['message' => 'Unauthorized.'],401);
//        }

        if($user->balance?->balance > 0){
            return response(['message' => 'Account deletion not allowed. Account has available balance.'],400);
        }

        //deletion eligibility checks
        $checker = new AccountDeletionEligibilityService($user);

        if( !$checker->isDeletable() ){
            return response()->json([
                'message' => 'Account deletion is not allowed',
                'reasons' => $checker->preventingReason(),
            ], 400);

        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try{

            //Balance logs
            BalanceLog::where('changed_by', $user->id)->delete();

            if($type == 1){
                // Delete all investor files & active investment
                BusinessBids::where('investor_id', $id)->delete();
                AcceptedBids::where('investor_id', $id)->delete();

                ServiceBooking::where('booker_id', $id)->delete();
                ServiceMessages::where('to_id', $id)->orWhere('from_id', $id)->delete();

                if ($user->id_passport) {
                    $filePath = public_path($user->id_passport);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }

                if ($user->pin) {
                    $filePath = public_path($user->pin);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }

            }
            else if($type == 2){
                // Delete all Program owner files & Program profile
                $program_pro = ProgramProfile::where('user_id', $id)->first();
                if($program_pro && $program_pro->document){
                    $filePath = public_path($program_pro->document);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
                $program_pro->delete();

                $programs = Program::where('user_id', $id)->get();
                foreach ($programs as $program) {
                    $filePath = public_path($program->program_brief_pdf);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                    $program->delete();
                }

                ServiceBooking::where('booker_id', $id)->delete();
                ServiceMessages::where('to_id', $id)->orWhere('from_id', $id)->delete();
            }
            else if($type == 3){
                // Delete all Capital owner files & Capital profile
                $cap = CapitalProfile::where('user_id', $id)->first();
                if($cap && $cap->document){
                    $filePath = public_path($cap->document);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
                $cap->delete();
                $capitals = CapitalOffer::where('user_id', $id)->get();
                foreach ($capitals as $capital) {
                    $filePath = public_path($capital->offer_brief_pdf);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                    $capital->delete();
                }

                ServiceBooking::where('booker_id', $id)->delete();
                ServiceMessages::where('to_id', $id)->orWhere('from_id', $id)->delete();
            }
            else if($type == 4 || $type == 5){
                //Delete Business owner documents
                ServiceBooking::where('booker_id', $id)->delete();
                ServiceMessages::where('to_id', $id)->orWhere('from_id', $id)->delete();
                Messages::where('to_id', $id)->orWhere('from_id', $id)->delete();

                $listings = Listing::where('user_id', $id)->get();
                $services = Services::where('user_id', $id)->get();
                foreach ($listings as $listing) {
                    $pin = public_path($listing->pin);
                    $identification = public_path($listing->identification);
                    $document = public_path($listing->document);
                    $video = public_path($listing->video);

                    if (file_exists($pin)) { unlink($pin); }
                    if (file_exists($video)) { unlink($video); }
                    if (file_exists($document)) { unlink($document); }
                    if (file_exists($identification)) { unlink($identification); }

                    $listing->delete();
                }
                BusinessBids::where('owner_id', $id)->delete();
                AcceptedBids::where('owner_id', $id)
                    ->whereNotIn('status', ['Confirmed', 'awaiting_payment', 'under_verification'])
                    ->delete();

                foreach ($services as $service) {
                    $pin = public_path($service->pin);
                    $identification = public_path($service->identification);
                    $document = public_path($service->document);
                    $video = public_path($service->video);

                    if (file_exists($pin)) { unlink($pin); }
                    if (file_exists($video)) { unlink($video); }
                    if (file_exists($document)) { unlink($document); }
                    if (file_exists($identification)) { unlink($identification); }

                    $service->delete();
                }
                ServiceBooking::where('service_owner_id', $id)->delete();
            }

            User::where('id',$id)->delete();
            DB::commit();
            return response(['message' => 'Account removed. All documents deleted.'],200);
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

    // ==================== Disputes ====================
    public function disputes()
    {
        $disputes = Dispute::latest()->get();
        foreach ($disputes as $disp) {
             $opened_by = User::select('fname', 'lname', 'email')->find($disp->user_id);
             if($opened_by){
                $disp->user = $opened_by;
             }
        }
        return view('admin.disputes', compact('disputes'));
    }

    public function removeDispute(Dispute $dispute)
    {
        try {
            $dispute->delete();
            return back()->with('success', "Deleted!");
        } catch (\Exception $e) {
            return back()->with('error', 'Something went wrong, please try again later.');
        }
    }

    // ==================== Events ====================
    public function events()
    {
        $events = Event::latest()->get();
        return view('admin.events.index', compact('events'));
    }

    public function toggleEvent(Event $event)
    {
        try {
            $event->update(['active' => !$event->active]);
            return back()->with('success', "Event Updated.");
        } catch (\Exception $e) {
            return back()->with('error', 'Something went wrong, please try again later.');
        }
    }

    public function milestones()
    {
        return view('admin.milestones.index');
    }

    // ==================== Transactions ====================
    public function transactions()
    {
        $transactions = Transactions::with('user')->latest()->paginate(15);
        return view('admin.transactions', compact('transactions'));
    }

    // ==================== Programs / Capitals / Prospects ====================
    public function programs()
    {
        $programs = Program::latest()->get();
        return view('admin.program.index', compact('programs'));
    }

    public function capitals()
    {
        $capitals = CapitalOffer::latest()->get();
        return view('admin.capital.index', compact('capitals'));
    }

    public function prospects()
    {
        $prospects = Prospects::latest()->get();
        return view('admin.prospects', compact('prospects'));
    }

    // ==================== Reports ====================
    public function reports()
    {
        $reports = DB::table('reports')
            ->select('*', DB::raw('count(*) as total'))
            ->groupBy('listing_id')
            ->orderBy('total', 'DESC')
            ->get();
        return view('admin.reports', compact('reports'));
    }

    public function otherReports(Reports $report)
    {
        try {
            $reports = Reports::where('listing_id', $report->listing_id)->get();
            return response()->json(['reports' => $reports, 'status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function reportDownload(Reports $report)
    {
        try {
            $file = $report->document;
            if (!$file || !file_exists(public_path($file))) {
                return response('404');
            }
            return response()->download(public_path($file));
        } catch (\Exception $e) {
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // ==================== Password Reset ====================
    public function forgot($remail)
    {
        return view('admin.forgot_password', compact('remail'));
    }

    public function sendResetEmail(Request $request)
    {
        try {
            $validated = $request->validate(['email' => 'required|email']);
            $info = ['Name' => 'Dele', 'email' => $validated['email']];
            Mail::send('admin.mail', $info, function ($msg) use ($validated) {
                $msg->to($validated['email']);
                $msg->subject('Test Mail');
            });
            return back()->with('success', 'Check your email');
        } catch (\Exception $e) {
            Session::put('exception', $e->getMessage());
            return back();
        }
    }

    public function reset(Request $request, $remail)
    {
        try {
            $validated = $request->validate([
                'password' => 'required|string|confirmed|min:6'
            ]);

            $passwordHash = Hash::make($validated['password']);
            $update = DB::table('admin')->where('email', $remail)->limit(1)->update(['password' => $passwordHash]);

            if ($update) {
                Session::put('reset', 'Password reset success!');
                return redirect()->route('admin.login');
            }

            return back()->with('wrong_pwd', 'Password reset failed, try again');
        } catch (\Exception $e) {
            Session::put('exception', $e->getMessage());
            return back();
        }
    }

    // ==================== Bulk Email ====================
    public function bulkEmails()
    {
        return view('admin.bulk_email_import');
    }

    public function bulkRegisterEmails(Request $request, BulkEmailService $bulkEmails)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,txt,xls|max:10240',
            'subject' => 'nullable|string|max:255',
        ]);

        $subject = $validated['subject'] ?? 'Onboarding request to Tujitume';
        $view = 'admin.email_templates.bulk_register_request';

        $fileType = $request->file('file')->getClientOriginalExtension() === 'csv' ? 'csv' : 'excel';
        $result = $bulkEmails->extractEmails($request->file('file')->getRealPath(), $fileType);
        $validEmails = $result['valid_emails'];

        if (!empty($validEmails)) {
            $bulkEmails->send($subject, $view, [], $validEmails);
        }

        $message = count($validEmails) > 0
            ? count($validEmails) . " emails sent successfully."
            : "No valid emails found to send.";

        return back()->with(['success' => $message, 'importErrors' => $result['errors']]);
    }

    // ==================== Utilities ====================
    public function clean($string)
    {
        $string = str_replace(' ', '', $string);
        return preg_replace('/[^A-Za-z0-9\-]/', '', $string);
    }


    public function searchInAdmin(Request $request)
    {
        try {
            $searchText = trim($request->text);
            $users = User::where('fname', 'like', "%{$searchText}%")
                ->orWhere('lname', 'like', "%{$searchText}%")
                ->orWhere('email', 'like', "%{$searchText}%")
                ->orWhere('website', 'like', "%{$searchText}%")
                ->get();

            foreach ($users as $user) {
                $user->investedBusiness = DB::table('business_bids')
                    ->where('investor_id', $user->id)
                    ->join('listings', 'business_bids.business_id', '=', 'listings.id')
                    ->select('business_bids.*', 'listings.name')
                    ->get();

                $user->activeBusiness = DB::table('business_bids')
                    ->where('owner_id', $user->id)
                    ->join('listings', 'business_bids.business_id', '=', 'listings.id')
                    ->groupBy('business_bids.business_id')
                    ->select('business_bids.*', 'listings.name', 'listings.investment_needed', 'listings.category')
                    ->get();

                $user->bookedServices = DB::table('service_bookings')
                    ->where('booker_id', $user->id)
                    ->join('services', 'service_bookings.service_id', '=', 'services.id')
                    ->select('service_bookings.*', 'services.name', 'services.price')
                    ->get();
            }

            return view('admin.users', compact('users'));
        } catch (\Exception $e) {
            return back()->with('error', 'Something went wrong, please try again later.');
        }
    }

    // ==================== Error Logs ====================
    public function errorLogs(Request $request)
    {
        $query = ErrorLog::query()->latest();

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('message', 'LIKE', "%{$request->search}%")
                    ->orWhere('type', 'LIKE', "%{$request->search}%")
                    ->orWhere('file', 'LIKE', "%{$request->search}%");
            });
        }

        if ($request->type) {
            $query->where('type', $request->type);
        }

        $logs = $query->paginate(20);
        return view('admin.error_logs', compact('logs'));
    }
}
