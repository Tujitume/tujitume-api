<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Account\Account\Account\Account\testController;
use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Capital\StartupPitches;
use App\Models\Communication\Meeting;
use App\Models\Communication\Schedule;
use App\Models\Programs\ProgramApplication;
use App\Models\Services\ServiceBooking;
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
            $client = User::select('first_name','last_name','email')->where('id',$request->client_id)->first();
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
                'host_name'    => $host->first_name.' '.$host->last_name,
                'client_name'  => $client->first_name.' '.$client->last_name,
                'date'         => $request->date,
                'time'         => $request->time,
                'title'        => $request->title,
                'description'  => $request->description,
                'link'         => $request->link,
                'status'       => 'active',
            ]);

            //Notifications
            $dateTime = Carbon::createFromFormat('Y-m-d H:i', $request->date.' '. $request->time);
            $formatted = $dateTime->format('F j, g:i A'); // â†’ July 1, 2:00 PM

            if($host->user_type_id == 4 || $host->user_type_id == 3){
                $linkForSme = 'meeting';
                $linkForOrg = 'overview/settings/security';
            }
            else{
                $linkForOrg = 'meeting';
                $linkForSme = 'overview/settings/security';
            }

            $text = 'You have a new meeting from '.$host->first_name.' '.$host->last_name. ' at '.$formatted ;
            $this->notification->create($request->client_id, $host->id, $text
                ,$linkForSme,'meeting');

            $text2 = 'You have a new meeting with '.$client->first_name.' '.$client->last_name. ' at '.$formatted ;
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
                    $user_id = $type == 2 ? $user->program_owner_id : $user->capital_owner_id;
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

    /**
     * People you can schedule a meeting with.
     * GET /meeting-people?search=&page=&per_page=
     * No search: the people you have already met with come first (suggestions). With a search: anyone on the
     * platform whose name, username or email matches, so you can find someone you have not worked with yet.
     */
    public function people(Request $request)
    {
        try {
            $me = Auth::user();
            $search = trim((string) $request->query('search', ''));
            $perPage = min(max((int) $request->query('per_page', 10), 1), 50);
            $page = max((int) $request->query('page', 1), 1);

            $query = User::where('id', '!=', $me->id)->whereBetween('user_type_id', [1, 6]);

            if ($search !== '') {
                $like = '%' . $search . '%';
                $startsWith = $search . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('display_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
                })
                // People whose name starts with what was typed come first, like a typeahead
                ->orderByRaw("CASE WHEN first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ? THEN 0 ELSE 1 END", [$startsWith, $startsWith, $startsWith]);
            } else {
                $met = Meeting::where('host_id', $me->id)->pluck('client_id')
                    ->merge(Meeting::where('client_id', $me->id)->pluck('host_id'))
                    ->unique()->values();

                if ($met->isNotEmpty()) {
                    $query->orderByRaw('CASE WHEN id IN (' . $met->map(fn ($id) => (int) $id)->implode(',') . ') THEN 0 ELSE 1 END');
                }
            }

            $users = $query->orderBy('first_name')->orderBy('id')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage + 1)
                ->get(['id', 'first_name', 'last_name', 'display_name', 'email', 'image', 'city', 'country', 'user_type_id']);

            $hasMore = $users->count() > $perPage;

            $roles = [
                1 => 'Entrepreneur', 2 => 'Investor', 3 => 'Service Provider',
                4 => 'Program Organization', 5 => 'Capital Organization', 6 => 'Reviewer',
            ];

            return response()->json([
                'data' => $users->take($perPage)->values()->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => trim($user->first_name . ' ' . $user->last_name) ?: ($user->display_name ?: $user->email),
                    'email' => $user->email,
                    'image' => $user->image,
                    'role' => $roles[$user->user_type_id] ?? 'Member',
                    'location' => collect([$user->city, $user->country])->filter()->implode(', '),
                ]),
                'meta' => ['page' => $page, 'per_page' => $perPage, 'has_more' => $hasMore],
            ], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['search' => $request->query('search')]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
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
                $ids = ServiceBooking::where('service_owner_id', $user_id)->pluck('booker_id')
                    ->unique();

                $ids2 = AcceptedBids::where('owner_id', $user_id)->pluck('investor_id')
                    ->unique();

                $client_ids = $ids->merge($ids2)->unique()->values()->toArray();
            }

            else if($type == 2) //Program
            {
                $user = $this->get_role();
                if (in_array($user->role, ['editor', 'viewer', 'admin', 'super_admin'])) {
                    $user_id = $user->program_owner_id;
                }
                $client_ids = ProgramApplication::where('program_owner_id', $user_id)->pluck('user_id')
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
                $ids = ProgramApplication::where('user_id', $user_id)->pluck('program_owner_id')
                    ->unique();
                $ids2 = StartupPitches::where('user_id', $user_id)->pluck('capital_owner_id')
                    ->unique();
                $ids3 = ServiceBooking::where('booker_id', $user_id)->pluck('service_owner_id')
                    ->unique();
                $ids4 = AcceptedBids::where('owner_id', $user_id)->pluck('investor_id')
                    ->unique();
                $client_ids = $ids->merge($ids2)->merge($ids3)->merge($ids4)->unique()
                    ->values()->toArray();
            }
            else if($type == 5) //Service
            {
                $ids = ServiceBooking::where('service_owner_id', $user_id)->pluck('booker_id')
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
                    $user_id = $type == 2 ? $user->program_owner_id : $user->capital_owner_id;
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
        $user = Auth::user()->load('organizationRole.role');
        $user->role = $user->organizationRole?->role?->name ?? 'super-admin';
        $user->program_owner_id = $user->organizationOwnerId();
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
