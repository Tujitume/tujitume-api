<?php

namespace App\Http\Controllers;

use App\Models\AiUsageLog;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Service\Program\ProgramAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiUsageController extends Controller
{
    public function me(Request $request)
    {
        $base = AiUsageLog::where('user_id', $request->user()->id);
        $month = (clone $base)->whereBetween('created_at', [now()->startOfMonth(), now()]);
        $day = (clone $base)->whereDate('created_at', now(config('app.timezone'))->toDateString());
        return response()->json(['success' => true, 'data' => [
            'currency' => 'USD', 'lifetime' => $this->aggregate($base), 'current_month' => $this->aggregate($month), 'current_day' => $this->aggregate($day),
        ]]);
    }

    public function history(Request $request)
    {
        $filters = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'provider' => 'nullable|string|max:40', 'model' => 'nullable|string|max:160', 'application_id' => 'nullable|integer', 'operation' => 'nullable|string|max:50', 'per_page' => 'nullable|integer|min:1|max:100']);
        $q = AiUsageLog::where('user_id', $request->user()->id)->latest();
        foreach (['provider','model','application_id','operation'] as $field) if (isset($filters[$field])) $q->where($field, $filters[$field]);
        if (isset($filters['from'])) $q->where('created_at', '>=', $filters['from']);
        if (isset($filters['to'])) $q->where('created_at', '<=', $filters['to']);
        return response()->json(['success' => true, 'data' => $q->paginate($filters['per_page'] ?? 25)]);
    }

    public function application(ProgramApplication $application)
    {
        $round = $application->currentRound;
        if (! $round || (! ProgramAccess::owns($round->program) && ! ReviewerApplicationAssignment::where(['round_id' => $round->id, 'application_id' => $application->id, 'reviewer_id' => auth()->id()])->exists())) {
            return response()->json(['message' => 'You do not have access to this application.'], 403);
        }
        $q = AiUsageLog::where('application_id', $application->id);
        return response()->json(['success' => true, 'data' => [
            'application_id' => $application->id, 'currency' => 'USD', 'usage' => $this->aggregate($q),
            'providers' => (clone $q)->select('provider','model',DB::raw('COUNT(*) as requests'),DB::raw('COALESCE(SUM(total_tokens),0) as total_tokens'),DB::raw('COALESCE(SUM(total_cost),0) as total_cost'))->groupBy('provider','model')->get(),
            'requests' => (clone $q)->latest()->paginate(25),
        ]]);
    }

    private function aggregate($query): array
    {
        $row = $query->selectRaw("COUNT(*) total_requests, SUM(status = 'success') successful_requests, SUM(status = 'failed') failed_requests, COUNT(DISTINCT application_id) applications_reviewed, COALESCE(SUM(input_tokens),0) input_tokens, COALESCE(SUM(output_tokens),0) output_tokens, COALESCE(SUM(cached_tokens),0) cached_tokens, COALESCE(SUM(total_tokens),0) total_tokens, COALESCE(SUM(total_cost),0) total_cost")->first();
        return $row->toArray();
    }
}
