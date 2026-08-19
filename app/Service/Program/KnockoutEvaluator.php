<?php

namespace App\Service\Program;

use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\RoundCustomQuestion;

class KnockoutEvaluator
{
    public function evaluate($application)
    {
        $round = $application->currentRound;

        if (!$round) {
            return;
        }

        // Get knockout questions
        $knockoutQuestions = RoundCustomQuestion::where('round_id', $round->id)
            ->where('question_type', 'knockout')
            ->get();

        if ($knockoutQuestions->isEmpty()) {
            $application->update(['knockout_status' => 'passed']);
            return;
        }

        // Get responses
        $responses = ApplicationRoundResponse::where('application_id', $application->id)
            ->where('round_id', $round->id)
            ->get()
            ->keyBy('question_id');

        $hasFailed = false;
        $allAnswered = true;

        foreach ($knockoutQuestions as $question) {
            $response = $responses->get($question->id);

            if (!$response) {
                $allAnswered = false;
                continue;
            }

            // FAIL LOGIC HERE
            if (
                $question->knockout_fail_value !== null &&
                strtolower(trim($response->response)) === strtolower(trim($question->knockout_fail_value))
            ) {
                $hasFailed = true;
                break;
            }
        }

        if ($hasFailed) {
            $status = 'failed';
        } elseif (!$allAnswered) {
            $status = 'pending';
        } else {
            $status = 'passed';
        }

        $application->update([
            'knockout_status' => $status
        ]);
    }

}
