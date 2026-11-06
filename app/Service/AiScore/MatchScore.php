<?php

namespace App\Service\AiScore;

use App\Models\Programs\Program;
use App\Service\Program\RoundMatchScore;
use Illuminate\Http\Request;

class MatchScore
{
    public function program(Request|Program $request, ?int $programId = null): array
    {
        $program = $request instanceof Program
            ? $request
            : Program::findOrFail($programId ?? $request->input('program_id'));
        $answers = $request instanceof Program ? [] : $request->input('round_answers', []);

        return app(RoundMatchScore::class)->scoreRequest($program, is_array($answers) ? $answers : []);
    }

    public function compute(Program $program, array $answers): array
    {
        return app(RoundMatchScore::class)->scoreRequest($program, $answers);
    }
}
