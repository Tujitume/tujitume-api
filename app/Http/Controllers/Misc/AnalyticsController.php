<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Models\Capital\StartupPitches;
use App\Models\Programs\ProgramApplication;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\NotificationService;
use DateTime;
use Hash;
use Illuminate\Support\Facades\Auth;
use Mail;
use Response;
use Session;

class AnalyticsController extends Controller
{
    public $notification;
    public function __construct()
    {
        $this->notification = new NotificationService();
    }
    public function index()
    {
        try {
            $user_id = Auth::id();

            $user = $this->get_role();
            if($user->role == 'editor' || $user->role == 'viewer' || $user->role == 'admin'){
                $user_id = $user->program_owner_id;
            }

            $pitches = ProgramApplication::where('program_owner_id',$user_id)->get();
            $pitches_funded = $pitches->where('status', 1)->count();
            $pitches_count = $pitches->count();
            //Top Matching StartUps (10)
            $topPitches  = $pitches->sortByDesc('match_score')->take(10)->values();

            $score = 0;$avg_score = 0;
            $break = [
                'sector' => 0,
                'geo' => 0,
                'stage' => 0,
                'revenue' => 0,
                'team' => 0,
                'impact' => 0,
                'milestone' => 0,
            ];
            $dist = [
                '90-100' => 0,
                '80-89' => 0,
                '70-79' => 0,
                '60-69' => 0,
                '<60' => 0
            ];
            $currentMonth = now()->month;$i=0;
            $monthData = [];

            //Performance Last 6 Months
            $applicationsByMonth = ProgramApplication::
            selectRaw('
                MONTH(created_at) as month,
                COUNT(*) as total,
                SUM(CASE WHEN match_score >= 60 THEN 1 ELSE 0 END) as passed
            ')
                ->where('program_owner_id', $user_id)
                ->groupBy('month')->get();

            foreach ($applicationsByMonth as $row) {
                $monthName = DateTime::createFromFormat('!m', $row->month)->format('M'); // e.g., "Apr"
                $monthData[$monthName] = [
                'applications' => $row->total,
                'match' => $row->passed,
                'conversion' => round(($row->passed/$row->total) * 100)
                ];
                //$matches = ProgramApplication::select('score')->where('score', '>=', 60)->get();
            }

            foreach ($pitches as $pitch){
                $score = $pitch->match_score+$score;
                //Matching Condition
                if($pitch->match_score >= 60){
                    $created = $pitch->created_at;
                    $createdMonth = (new DateTime($created))->format('M');

                }

                //Distribution
                if($pitch->match_score >= 90)
                    $dist['90-100'] += 1;
                else if ($pitch->match_score >= 80 && $pitch->match_score < 90)
                    $dist['80-89'] += 1;
                else if ($pitch->match_score >= 70 && $pitch->match_score < 80)
                    $dist['70-79'] += 1;
                else if ($pitch->match_score >= 60 && $pitch->match_score < 70)
                    $dist['60-69'] += 1;
                else
                    $dist['<60'] += 1;

                //Score Breakdown
                if ($pitch->score_breakdown) {

                    $breakdown = collect($pitch->score_breakdown)
                        ->keyBy('label');

                    $break['sector'] += (float) ($breakdown['Sector Alignment']['value'] ?? 0);

                    $break['geo'] += (float) ($breakdown['Geographic Fit']['value'] ?? 0);

                    $break['stage'] += (float) ($breakdown['Startup Stage Compatibility']['value'] ?? 0);

                    $break['revenue'] += (float) ($breakdown['Revenue Traction']['value'] ?? 0);

                    $break['team'] += (float) ($breakdown['Team']['value'] ?? 0);

                    $break['impact'] += (float) ($breakdown['Impact Focus']['value'] ?? 0);

                    $break['milestone'] += (float) ($breakdown['Milestone Success']['value'] ?? 0);
                }

            }

            if($pitches_count > 0)
            $avg_score = round($score/$pitches_count,2);

            $break_avg = collect($break)->map(function ($value, $key) use ($pitches_count) {
                if($pitches_count > 0)
                    return $value = round($value/$pitches_count,2);
                else
                    return $value = 0;
            })->toArray();

            return response()->json([
                'avg_score' => $avg_score,
                'funded' => $pitches_funded,
                'total_match' => $pitches_count,
                'distribution' => $dist,
                'breakdown' => $break_avg,
                'performance_month' => $monthData,
                'top_startups' => $topPitches
            ],200);
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

    public function index_capital(){
        try {
            $user_id = Auth::id();

            $user = $this->get_role2();
            if($user->role == 'editor' || $user->role == 'viewer' || $user->role == 'admin'){
                $user_id = $user->capital_owner_id;
            }

            $pitches = StartupPitches::where('capital_owner_id',$user_id)->get();
            $pitches_funded = $pitches->where('status',1)->count();
            $pitches_count = $pitches->count();
            //Top Matching StartUps (10)
            $topPitches  = $pitches->sortByDesc('score')->take(10)->values();

            $score = 0;$avg_score = 0;
            $break = [
            'sector' => 0,
            'geo' => 0,
            'stage' => 0,
            'revenue' => 0,
            'team' => 0,
            'impact' => 0
            ];
            $dist = [
            '90-100' => 0,
            '80-89' => 0,
            '70-79' => 0,
            '60-69' => 0,
            '<60' => 0
            ];
            $currentMonth = now()->month;$i=0;
            $monthData = [];

            //Performance Last 6 Months
            $applicationsByMonth = StartupPitches::
            selectRaw('
                            MONTH(created_at) as month,
                            COUNT(*) as total,
                            SUM(CASE WHEN score >= 60 THEN 1 ELSE 0 END) as passed
                        ')
            ->where('user_id', $user_id)
            ->groupBy('month')->get();

            foreach ($applicationsByMonth as $row) {
            $monthName = DateTime::createFromFormat('!m', $row->month)->format('M'); // e.g., "Apr"
            $monthData[$monthName] = [
            'applications' => $row->total,
            'match' => $row->passed,
            'conversion' => round(($row->passed/$row->total) * 100)
            ];
            //$matches = ProgramApplication::select('score')->where('score', '>=', 60)->get();
            }

            foreach ($pitches as $pitch){
                $score = $pitch->score+$score;

                //Matching Condition
                if($pitch->score >= 60){
                    $created = $pitch->created_at;
                    $createdMonth = (new DateTime($created))->format('M');

                }

                //Distribution
                if($pitch->score >= 90)
                    $dist['90-100'] += 1;
                else if ($pitch->score >= 80 && $pitch->score < 90)
                    $dist['80-89'] += 1;
                else if ($pitch->score >= 70 && $pitch->score < 80)
                    $dist['70-79'] += 1;
                else if ($pitch->score >= 60 && $pitch->score < 70)
                    $dist['60-69'] += 1;
                else
                    $dist['<60'] += 1;

                //Score Breakdown
                if($pitch->score_breakdown && $pitch->score_breakdown != ''){
                    $breakdown = $pitch->score_breakdown;
                    $break['sector'] = (float) $breakdown[0] + $break['sector'];
                    $break['geo'] = (float) $breakdown[1] + $break['geo'];
                    $break['stage'] = (float) $breakdown[2] + $break['stage'];
                    $break['revenue'] = (float) $breakdown[3] + $break['revenue'];
                    $break['team'] = (float) $breakdown[4] + $break['team'];
                    $break['impact'] = (float) $breakdown[5] + $break['impact'];
                }

            }

            if($pitches_count > 0)
                $avg_score = round($score/$pitches_count,2);

            $break_avg = collect($break)->map(function ($value, $key) use ($pitches_count) {
                if($pitches_count > 0)
                    return $value = round($value/$pitches_count,2);
                else
                    return $value = 0;
            })->toArray();

            return response()->json([
                'avg_score' => $avg_score,
                'funded' => $pitches_funded,
                'total_match' => $pitches_count,
                'distribution' => $dist,
                'breakdown' => $break_avg,
                'performance_month' => $monthData,
                'top_startups' => $topPitches
            ],200);
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
