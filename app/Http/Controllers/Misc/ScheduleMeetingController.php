<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Account\Account\Account\Account\testController;
use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Capital\StartupPitches;
use App\Models\Communication\Meeting;
use App\Models\Communication\Schedule;
use App\Models\Grants\GrantApplication;
use App\Models\Services\serviceBook;
use App\Service\Misc\ErrorLogService;
use Carbon\Carbon;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Response;
use Session;

class ScheduleMeetingController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function create_meeting(Request $request)
    {
        try{
            $host = Auth::user();
            $client = User::select('fname','lname','email')->where('id',$request->client_id)->first();
            $request->validate([
                'client_id'   => 'required|integer|exists:users,id', // or clients table if applicable
                //'client_name' => 'required|string|max:255',
                'date'        => 'required|date', // format: Y-m-d
                'time'        => 'required|date_format:H:i', // 24-hour format like 14:30
                'title'       => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'link'        => 'required|url|max:100',
            ]);

            $meeting = Meeting::create([
                'host_id'      => Auth::id(),
                'client_id'    => $request->client_id,
                'host_name'    => $host->fname.' '.$host->lname,
                'client_name'  => $client->fname.' '.$client->lname,
                'date'         => $request->date,
                'time'         => $request->time,
                'title'        => $request->title,
                'description'  => $request->description,
                'link'         => $request->link,
                'status'       => 'active',
            ]);

            //Notifications
            $dateTime = Carbon::createFromFormat('Y-m-d H:i', $request->date.' '. $request->time);
            $formatted = $dateTime->format('F j, g:i A'); // → July 1, 2:00 PM

            if($host->user_type_id == 2 || $host->user_type_id == 3){
                $linkForSme = 'meeting';
                $linkForOrg = 'overview/settings/security';
            }
            else{
                $linkForOrg = 'meeting';
                $linkForSme = 'overview/settings/security';
            }

            $text = 'You have a new meeting from '.$host->fname.' '.$host->lname. ' at '.$formatted ;
            $this->notification->create($request->client_id, $host->id, $text
                ,$linkForSme,'meeting');

            $text2 = 'You have a new meeting with '.$client->fname.' '.$client->lname. ' at '.$formatted ;
            $this->notification->create($host->id ,$request->client_id , $text2
                ,$linkForOrg,'meeting');
            //Notifications

            return response()->json([
                'message' => 'Meeting created successfully.',
                'data' => $meeting
                ], 201);
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

    public function meetings()
    {
        try{
            $user = Auth::user();
            $user_id = Auth::id();
            $type = $user->user_type_id;

            if($type == 2 || $type == 3){
                $user = $type == 2 ? $this->get_role() : $this->get_role2();
                if (in_array($user->role, ['editor', 'viewer', 'admin'])) {
                    $user_id = $type == 2 ? $user->grant_owner_id : $user->capital_owner_id;
                }
            }

            $today = Carbon::now();
            $meetings = Meeting::where('host_id',$user_id)->orWhere('client_id',$user_id)->latest()->get();
            foreach($meetings as $meeting){
                $meetingDateTime = Carbon::parse("{$meeting->date} {$meeting->time}");

                // check if meeting is in the past
       

                if ($meeting->host_id == $user_id) {
                    $meeting->is_host = true;
                } 
                else $meeting->is_host = false;
            }


            return response()->json(['meetings' => $meetings], 200, [], JSON_UNESCAPED_SLASHES);
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

    public function cancel_meeting(Request $request)
    {
        try{
            $meeting = Meeting::findOrFail($request->id);
                $meeting->status = 'cancelled';
                $meeting->save();
            return response()->json(['message' => 'Meeting cancelled.'], 200);
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


    public function clients_list()
    {
        try{
            $user = Auth::user(); //71;
            $user_id = Auth::id();
            $type = $user->user_type_id;
            $client_ids = [];

            if($type == 1) //Investor
            {
                $ids = serviceBook::where('service_owner_id', $user_id)->pluck('booker_id')
                    ->unique();

                $ids2 = AcceptedBids::where('owner_id', $user_id)->pluck('investor_id')
                    ->unique();

                $client_ids = $ids->merge($ids2)->unique()->values()->toArray();
            }

            else if($type == 2) //Grant
            {
                $user = $this->get_role();
                if (in_array($user->role, ['editor', 'viewer', 'admin'])) {
                    $user_id = $user->grant_owner_id;
                }
                $client_ids = GrantApplication::where('grant_owner_id', $user_id)->pluck('user_id')
                    ->unique()->toArray();
            }
            else if($type == 3) //Capital
            {
                $user = $this->get_role2();
                if (in_array($user->role, ['editor', 'viewer', 'admin'])) {
                    $user_id = $user->capital_owner_id;
                }
                $client_ids = StartupPitches::where('capital_owner_id', $user_id)->pluck('user_id')
                    ->unique()->toArray();
            }
            else if($type == 4) //Business
            {
                $ids = GrantApplication::where('user_id', $user_id)->pluck('grant_owner_id')
                    ->unique();
                $ids2 = StartupPitches::where('user_id', $user_id)->pluck('capital_owner_id')
                    ->unique();
                $ids3 = serviceBook::where('booker_id', $user_id)->pluck('service_owner_id')
                    ->unique();
                $ids4 = AcceptedBids::where('owner_id', $user_id)->pluck('investor_id')
                    ->unique();
                $client_ids = $ids->merge($ids2)->merge($ids3)->merge($ids4)->unique()
                    ->values()->toArray();
            }
            else if($type == 5) //Service
            {
                $ids = serviceBook::where('service_owner_id', $user_id)->pluck('booker_id')
                    ->unique();
                $client_ids = $ids->unique()->values()->toArray();
            }

            $clients = User::whereIn('id',$client_ids)->get();
            return response()->json(['clients' => $clients], 200);
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

    // S C H E D U L E S

    public function create_schedule(Request $request)
    {
        try{
            $hasSchedule = Schedule::where('user_id',Auth::id())->first();
            $request->validate([
                'timezone'    => 'required|string|max:100',
                'day'         => 'required|array|max:200',
                'start_hour'  => 'required|array|max:200',
                'end_hour'    => 'required|array|max:200',
            ]);
            if($hasSchedule)
            {
                $hasSchedule->update([
                    'timezone'    => $request->timezone,
                    'day'         => json_encode($request->day),
                    'start_hour'  => json_encode($request->start_hour),
                    'end_hour'    => json_encode($request->end_hour),
                ]);
                return response()->json(['message' => 'Schedule updated successfully.'], 200);
            }
            $schedule = Schedule::create([
                'user_id'     => Auth::id(),
                'timezone'    => $request->timezone,
                'day'         => json_encode($request->day),
                'start_hour'  => json_encode($request->start_hour),
                'end_hour'    => json_encode($request->end_hour),
            ]);
            return response()->json(['message' => 'Schedule created successfully.'], 200);
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

    public function schedules()
    {
        try{
            $user = Auth::user();
            $user_id = Auth::id();
            $type = $user->user_type_id;

            if($type == 2 || $type == 3){
                $user = $type == 2 ? $this->get_role() : $this->get_role2();
                if (in_array($user->role, ['editor', 'viewer', 'admin'])) {
                    $user_id = $type == 2 ? $user->grant_owner_id : $user->capital_owner_id;
                }
            }
            $schedules = Schedule::where('user_id',$user_id)->latest()->get();

//            $flattened = [];
//            foreach ($schedules as $schedule) {
//                $days = json_decode($schedule->day, true);
//                $starts = json_decode($schedule->start_hour, true);
//                $ends = json_decode($schedule->end_hour, true);
//
//                if (!is_array($days) || !is_array($starts) || !is_array($ends)) {
//                    continue; // skip invalid data
//                }
//                foreach ($days as $index => $day) {
//                    $flattened[] = [
//                        'day' => $day,
//                        'start' => $starts[$index] ?? null,
//                        'end' => $ends[$index] ?? null,
//                    ];
//                }
//            }

            return response()->json(['schedules' => $schedules], 200, [], JSON_UNESCAPED_SLASHES);
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

    public function delete_schedule($id)
    {
        try{
            $schedules = Schedule::where('id',$id)->delete();
            return response()->json(['message' => 'Deleted!'], 200);
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


    public function client_schedules($client_id)
    {
        try{
            $user = Auth::user();
            $type = $user->user_type_id;
            $schedules = Schedule::where('user_id',$client_id)->latest()->first();
            if($schedules)
                return response()->json(['schedules' => $schedules], 200, [], JSON_UNESCAPED_SLASHES);
            else
                return response()->json(['schedules' => 'N/A"'], 200, [], JSON_UNESCAPED_SLASHES);
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

   #____________________________H e l p e r s________________________
    public function get_role()
    {
        $user = Auth::user()->load('grant_profile.role');
        $user->role = $user->grant_profile?->role?->name ?? 'super-admin';
        $user->grant_owner_id = $user->grant_profile?->grant_owner_id;
        return $user;
    }

    public function get_role2()
    {
        $user = Auth::user()->load('capital_profile.role');
        $user->role = $user->capital_profile?->role?->name ?? 'super-admin';
        $user->capital_owner_id = $user->capital_profile?->capital_owner_id;
        return $user;
    }

}
