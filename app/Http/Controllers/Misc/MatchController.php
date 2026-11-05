<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Service\AiScore\MatchScore;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function score(MatchScore $match, Request $request, $program_id)
    {
        $computed = $match->program($request, $program_id);

        $applicantBreakdown = collect($computed['breakdown'] ?? [])
            ->map(function ($item) {
                $status = ($item['match'] ?? 0) >= 1 ? 'matched' : 'not matched';
                return [
                    'field_id' => $item['field_id'] ?? null,
                    'key' => $item['key'] ?? null,
                    'label' => $item['label'] ?? null,
                    'status' => $status,
                ];
            })->values()->all();

        $payload = [
            'match_score' => $computed['match_score'] ?? $computed['score'] ?? null,
            'breakdown_for_applicant' => $applicantBreakdown,
        ];

        return response()->json($payload, 200);
    }

    public function score_capital(MatchScore $match, Request $request, $capital_id)
    {
        $score = $match->capital($request, $capital_id);
        return response()->json($score,200);
    }
}
