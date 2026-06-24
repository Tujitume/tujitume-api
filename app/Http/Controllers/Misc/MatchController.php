<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Service\AiScore\MatchScore;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function score(MatchScore $match, Request $request, $grant_id)
    {
        $score = $match->grant($request, $grant_id);
        return response()->json($score,200);
    }

    public function score_capital(MatchScore $match, Request $request, $capital_id)
    {
        $score = $match->capital($request, $capital_id);
        return response()->json($score,200);
    }
}
