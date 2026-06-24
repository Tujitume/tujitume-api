<?php

namespace App\Http\Controllers\Grant\Rounds;

use App\Http\Controllers\Controller;
use App\Models\Grants\GrantApplication;
use App\Models\Grants\Rounds\ApplicationRoundResponse;
use App\Models\Grants\Rounds\GrantRound;
use App\Models\Grants\Rounds\RoundCustomQuestion;
use App\Service\Grant\KnockoutEvaluator;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoundQuestionController extends Controller
{
    //added by owen
    /**
     * List questions for a round
     * GET /api/v1/grant/rounds/{round}/questions
     */
    public function index(GrantRound $round)
    {
        $questions = RoundCustomQuestion::where('round_id', $round->id)
            ->orderBy('display_order')
            ->get();

        return response()->json(['data' => $questions], 200);
    }

    //added by owen
    /**
     * Update an existing question
     * PATCH /api/v1/grant/questions/{question}
     */
    public function update(Request $request, RoundCustomQuestion $question)
    {
        $userId = auth()->id();

        if ($question->round->grant->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'question_text'  => 'sometimes|required|string|max:500',
                'question_type'  => 'sometimes|required|in:short_answer,long_text,multiple_choice,file_upload,budget_breakdown',
                'options'        => 'nullable|array',
                'is_required'    => 'nullable|boolean',
                'display_order'  => 'sometimes|required|integer|min:1',
            ]);

            $question->update($validated);

            DB::commit();
            return response()->json([
                'message' => 'Question updated successfully',
                'data'    => $question->fresh(),
            ], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong.'], 500);
        }
    }

    /**
     * Add custom question to round
     * POST /api/v1/grant/rounds/{round}/questions
     */
    public function store(Request $request, GrantRound $round)
    {
        $userId = auth()->id();

        if ($round->grant->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'question_text' => 'required|string|max:500',
                'question_type' => 'required|in:short_answer,long_text,multiple_choice,file_upload,budget_breakdown',
                'options' => 'nullable|array', // For multiple choice
                'is_required' => 'nullable|boolean',
                'display_order' => 'required|integer|min:1',
            ]);

            $validated['round_id'] = $round->id;
            $validated['is_required'] = $validated['is_required'] ?? false;

            $question = RoundCustomQuestion::create($validated);

            DB::commit();
            return response()->json([
                'message' => 'Question added successfully',
                'data' => $question,
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong.'], 500);
        }
    }

    /**
     * Delete question
     * DELETE /api/v1/grant/questions/{question}
     */
    public function destroy(RoundCustomQuestion $question)
    {
        $userId = auth()->id();

        if ($question->round->grant->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $question->delete();

        return response()->json(['message' => 'Question deleted successfully']);
    }


    /**
     * Submit answer to a question
     * POST /grant/applications/{application_id}/rounds/{round_id}/answer
     */
    public function submitAnswer(Request $request, $applicationId, $roundId)
    {
        $userId = auth()->id();
        $knockoutService = new KnockoutEvaluator();

        DB::beginTransaction();
        try {
            $application = GrantApplication::findOrFail($applicationId);

            // Authorization: Only applicant can submit answers
            if ($application->user_id !== $userId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Verify application is in correct round
            if ($application->current_round_id != $roundId) {
                return response()->json([
                    'error' => 'Application is not in this round'
                ], 400);
            }

            $validated = $request->validate([
                'question_id' => 'required|exists:round_custom_questions,id',
                'response' => 'required_without:file|nullable|string',
                'file' => 'nullable|file|max:10240', // 10MB max
            ]);

            // Verify question belongs to this round
            $question = RoundCustomQuestion::where('id', $validated['question_id'])
                ->where('round_id', $roundId)
                ->firstOrFail();

            // Handle file upload for file_upload question type
            $filePath = null;
            if ($question->question_type === 'file_upload' && $request->hasFile('file')) {
                $file = $request->file('file');
                $path = "RoundAnswers/{$roundId}";
                $filePath = $this->fileUpload->saveFile($file, $path);
            }

            // Create or update answer
            $answer = ApplicationRoundResponse::updateOrCreate(
                [
                    'application_id' => $applicationId,
                    'round_id' => $roundId,
                    'question_id' => $validated['question_id'],
                ],
                [
                    'response' => $validated['response'] ?? null,
                    'file_path' => $filePath,
                ]
            );

            $knockoutService->evaluate($application);

            DB::commit();

            return response()->json([
                'message' => 'Answer submitted successfully',
                'data' => $answer
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Get applicant's answers for a round
     * GET /grant/applications/{application_id}/rounds/{round_id}/answers
     */
    public function getAnswers($applicationId, $roundId)
    {
        $userId = auth()->id();

        try {
            $application = GrantApplication::with('grant')->findOrFail($applicationId);

            // Authorization: Applicant or grant owner can view
            $isApplicant = $application->user_id === $userId;
            $isGrantOwner = $application->grant->user_id === $userId;

            if (!$isApplicant && !$isGrantOwner) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $answers = ApplicationRoundResponse::with('question')
                ->where('application_id', $applicationId)
                ->where('round_id', $roundId)
                ->get();

            return response()->json([
                'data' => $answers,
                'meta' => [
                    'total_answers' => $answers->count(),
                    'application_id' => $applicationId,
                    'round_id' => $roundId
                ]
            ], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Delete an answer
     * DELETE /grant/applications/{application_id}/rounds/{round_id}/answers/{question_id}
     */
    public function deleteAnswer($applicationId, $roundId, $questionId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $application = GrantApplication::findOrFail($applicationId);

            // Authorization: Only applicant can delete their answer
            if ($application->user_id !== $userId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $answer = ApplicationRoundResponse::where('application_id', $applicationId)
                ->where('round_id', $roundId)
                ->where('question_id', $questionId)
                ->firstOrFail();

            // Delete file if exists
            if ($answer->file_path && file_exists($answer->file_path)) {
                unlink($answer->file_path);
            }

            $answer->delete();

            DB::commit();

            return response()->json([
                'message' => 'Answer deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

}
