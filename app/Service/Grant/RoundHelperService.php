<?php

namespace App\Service\Grant;

use App\Models\Grants\GrantApplication;
use App\Models\Grants\Rounds\ApplicationRoundResponse;
use App\Models\Grants\Rounds\RoundCustomQuestion;
use App\Models\Grants\Rounds\RoundRequiredDocument;
use App\Service\File\FileUploadService;

class RoundHelperService
{
    public function buildRoundData($round, $application, $historyRecord = null)
    {
        $applicationId = $application->id;
        $roundId = $round->id;

        // =============================
        // QUESTIONS + ANSWERS
        // =============================
        $questions = RoundCustomQuestion::where('round_id', $roundId)
            ->orderBy('display_order')
            ->get();

        $responses = ApplicationRoundResponse::where('application_id', $applicationId)
            ->where('round_id', $roundId)
            ->get()
            ->keyBy('question_id');

        $regular = [];
        $knockout = [];

        foreach ($questions as $q) {
            $res = $responses->get($q->id);

            $data = [
                'question_id' => $q->id,
                'question_text' => $q->question_text,
                'question_type' => $q->question_type,
                'is_required' => $q->is_required,
                'options' => $q->options,
                'display_order' => $q->display_order,
                'response' => $res?->response,
                'response_file' => $res?->file_path,
                'answered' => $res !== null,
                'answered_at' => $res?->created_at,
            ];

            if ($q->question_type === 'knockout') {
                $data['knockout_fail_value'] = $q->knockout_fail_value;
                $knockout[] = $data;
            } else {
                $regular[] = $data;
            }
        }

        // =============================
        // DOCUMENTS
        // =============================
        $requiredDocs = array_map(function ($doc) {
            return $doc['label'] ?? null;
        }, $round->required_documents ?? []);

        $requiredDocs = array_filter($requiredDocs); // remove nulls
        $requiredDocs = array_values($requiredDocs); // reset keys

        $uploadedDocs = RoundRequiredDocument::where('application_id', $applicationId)
            ->where('round_id', $roundId)
            ->get();


        $uploadedTypes = $uploadedDocs->pluck('document_type')->toArray();
        $missingDocs = array_diff($requiredDocs, $uploadedTypes);

        // =============================
        // COMPLETION
        // =============================
        $requiredQuestions = $questions->where('is_required', true)->count();

        $answeredRequired = $questions->where('is_required', true)
            ->filter(fn($q) => $responses->has($q->id))
            ->count();

        // =============================
        // FINAL STRUCTURE
        // =============================
        return [
            'round_id' => $roundId,
            'round_name' => $round->round_name,
            'round_number' => $round->round_number,
            'round_status' => $round->status,
            'open_date' => $round->open_date,
            'close_date' => $round->close_date,

            'is_current' => $application->current_round_id === $roundId,
            'is_past' => $historyRecord && in_array($historyRecord->outcome, ['advanced', 'not_selected', 'withdrawn', 'awarded']),
            'is_upcoming' => !$historyRecord && $application->current_round_id !== $roundId,

            'history' => $historyRecord ? [
                'entered_at' => $historyRecord->entered_at,
                'submitted_at' => $historyRecord->submitted_at,
                'exited_at' => $historyRecord->exited_at,
                'outcome' => $historyRecord->outcome,
                'outcome_label' => $historyRecord->outcome_label,
                'average_score' => $historyRecord->average_score,
                'rank_in_round' => $historyRecord->rank_in_round,
                'total_applicants_in_round' => $historyRecord->total_applicants_in_round,
            ] : null,

            'questions' => [
                'regular' => $regular,
                'knockout' => $knockout,
                'total' => count($regular) + count($knockout),
                'answered' => $responses->count(),
            ],

            'documents' => [
                'required' => $requiredDocs,
                'uploaded' => $uploadedDocs,
                'missing' => array_values($missingDocs),
                'all_uploaded' => empty($missingDocs),
            ],

            'completion' => [
                'questions' => [
                    'required' => $requiredQuestions,
                    'answered_required' => $answeredRequired,
                    'all_required_answered' => $answeredRequired === $requiredQuestions,
                ],
                'documents' => [
                    'required' => count($requiredDocs),
                    'uploaded' => count($uploadedTypes),
                    'all_uploaded' => empty($missingDocs),
                ],
                'ready_to_submit' => $answeredRequired === $requiredQuestions && empty($missingDocs),
            ],
        ];
    }

    public function handleRoundSubmission($request, GrantApplication $application)
    {
        $fileUpload = new FileUploadService();
        $knockoutEvaluator = new KnockoutEvaluator();

        $round = $application->currentRound;
        $roundId = $round->id;

        if ($request->filled('round_answers')) {
            $answers = collect($request->round_answers)->map(function ($a) use ($application, $roundId) {
                return [
                    'application_id' => $application->id,
                    'round_id' => $roundId,
                    'question_id' => $a['question_id'],
                    'response' => $a['response'] ?? null,
                    'created_at' => now(),
                ];
            })->toArray();

            ApplicationRoundResponse::upsert(
                $answers,
                ['application_id', 'round_id', 'question_id'],
                ['response', 'updated_at']
            );
        }

        if ($request->filled('round_documents')) {
            foreach ($request->round_documents as $doc) {
                $path = 'files/roundDocuments/' . $application->id . '/' . $roundId;

                $filePath = $fileUpload->saveFile($doc['file'], $path);

                RoundRequiredDocument::updateOrCreate(
                    [
                        'application_id' => $application->id,
                        'round_id' => $roundId,
                        'document_type' => $doc['document_type'],
                    ],
                    [
                        'file_path' => $filePath,
                    ]
                );
            }
        }

        $knockoutEvaluator->evaluate($application);
        $errors = [];

        // 🔹 Required questions
        $questions = RoundCustomQuestion::where('round_id', $roundId)
            ->where('is_required', true)->get();

        $answers = ApplicationRoundResponse::where('application_id', $application->id)
            ->where('round_id', $roundId)
            ->get()
            ->keyBy('question_id');

        foreach ($questions as $q) {
            $answer = $answers->get($q->id);

            if (!$answer || (!$answer->response && !$answer->file_path)) {
                $errors[] = [
                    'field' => 'question_' . $q->id,
                    'type' => 'required_question',
                    'message' => "Required question not answered: {$q->field_label}"
                ];
            }
        }

        // 🔹 Required documents
        $requiredDocs = is_array($round->required_documents) ? $round->required_documents : [];

        $requiredDocTypes = collect($requiredDocs)
            ->map(fn($doc) => is_array($doc) ? ($doc['type'] ?? null) : $doc)
            ->filter()
            ->toArray();

        $uploadedDocs = RoundRequiredDocument::where('application_id', $application->id)
            ->where('round_id', $roundId)
            ->get();

        $uploadedTypes = $uploadedDocs->pluck('document_type')->toArray();

        $missingDocs = array_diff($requiredDocTypes, $uploadedTypes);

        foreach ($missingDocs as $doc) {
            $errors[] = [
                'field' => 'document_' . $doc,
                'type' => 'required_document',
                'message' => "Required document not uploaded: {$doc}"
            ];
        }

        // 🔹 Knockout fail check
        if ($application->knockout_status === 'failed') {
            $errors[] = [
                'field' => 'knockout',
                'type' => 'knockout_failed',
                'message' => 'Application failed knockout criteria'
            ];
        }

        return [
            'errors' => $errors
        ];
    }

}
