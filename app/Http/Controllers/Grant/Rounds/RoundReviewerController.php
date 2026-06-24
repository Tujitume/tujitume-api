<?php

namespace App\Http\Controllers\Grant\Rounds;

use App\Http\Controllers\Controller;
use App\Models\Grants\Rounds\GrantRound;
use App\Models\Auth\User;
use App\Models\Grants\Rounds\RoundReviewer;
use App\Service\Account\RegisterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Service\Misc\ErrorLogService;
use PhpOffice\PhpSpreadsheet\Calculation\MathTrig\Round;

class RoundReviewerController extends Controller
{
    //added by owen
    /**
     * List reviewers for a round
     * GET /api/v1/grant/rounds/{round}/reviewers
     */
    public function index(GrantRound $round)
    {
        $userId = auth()->id();

        if ($round->grant->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $reviewers = $round->reviewers()
            ->withPivot(['reviewer_type', 'max_apps_assigned', 'expertise_tags'])
            ->get()
            ->map(function ($user) {
                return [
                    'user_id'           => $user->id,
                    'name'              => $user->fname. ' '. $user->lname,
                    'email'             => $user->email,
                    'image'             => $user->image,
                    'reviewer_type'     => $user->pivot->reviewer_type,
                    'max_apps_assigned' => $user->pivot->max_apps_assigned,
                    'expertise_tags'    => $user->pivot->expertise_tags
                        ? json_decode($user->pivot->expertise_tags, true)
                        : [],
                ];
            });

        return response()->json(['data' => $reviewers], 200);
    }

    public function rounds()
    {
        try {
            $userId = auth()->id();

            $assignments = RoundReviewer::where('user_id', $userId)
                ->pluck('round_id')
                ->toArray();

            $rounds = GrantRound::with([
                'questions',
                'applications' => function ($query) {
                    $query->whereIn('round_status', ['pending', 'under_review', 'scored']);
                },
                'applications.roundAnswers',
                'applications.roundDocuments',
                'applications.scores' => function ($query) use ($userId) {
                    $query->where('reviewer_id', $userId);
                },
            ])->whereIn('id', $assignments)->get();

            return response()->json(['rounds' => $rounds], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Assign reviewer to round
     * POST /api/v1/grant/rounds/{round}/reviewers
     */
    public function store(Request $request, GrantRound $round)
    {
        $regService = new RegisterService();

        $userId = auth()->id();

        if ($round->grant->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'reviewer_type' => 'required|in:internal,external',
                'user_id' => 'required_if:reviewer_type,internal|exists:users,id',
                'email' => 'required_if:reviewer_type,external|email',
                'max_apps_assigned' => 'nullable|integer|min:1',
                'expertise_tags' => 'nullable|array',
            ]);

            $userId = null;
            if($request->reviewer_type == 'internal') {

                $userId = $validated['user_id'];

                // Check if already assigned
                $exists = $round->reviewers()->where('user_id', $userId)->exists();

                if ($exists) {
                    throw ValidationException::withMessages([
                        'user_id' => ['Reviewer already assigned to this round']
                    ]);
                }

                $round->reviewers()->attach($userId, [
                    'reviewer_type' => $validated['reviewer_type'] ?? 'internal',
                    'max_apps_assigned' => $validated['max_apps_assigned'] ?? null,
                    'expertise_tags' => isset($validated['expertise_tags']) ? json_encode($validated['expertise_tags']) : null,
                ]);
            }
            elseif ($request->reviewer_type == 'external') {
                $data = [
                    'role_id' => 10004, 'investor' => 2,
                    'email' => $request->email,
                    'fname' => $round->round_name . ' ' . 'Reviewer' ?? 'External Reviewer',
                    'grant_owner_id' => $round->grant->user_id,
                ];

                $createReviewer = $regService->grantRoleUserRegister($data);

                $responseData = $createReviewer->getData(true);

                if($responseData['success']) {
                    $userId = $responseData['user']['id'];

                    $round->reviewers()->attach($userId, [
                        'reviewer_type' => 'external',
                        'max_apps_assigned' => $validated['max_apps_assigned'] ?? null,
                        'expertise_tags' => isset($validated['expertise_tags']) ? json_encode($validated['expertise_tags']) : null,
                    ]);
                }
                else{
                    return $createReviewer;
                }
            }

            DB::commit();

            $this->grantNotification->send('round.scoring_assigned',
                [User::find($userId) ?? null],
                [
                'grant_title' => $round->grant->grant_title, 'count' => 1, 'grant_id' => $round->grant_id,
            ]);

            return response()->json([
                'message' => 'Reviewer assigned successfully',
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
     * Remove reviewer from round
     * DELETE /api/v1/grant/rounds/{round}/reviewers/{user}
     */
    public function destroy(GrantRound $round, User $user)
    {
        $userId = auth()->id();

        if ($round->grant->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $round->reviewers()->detach($user->id);

        return response()->json(['message' => 'Reviewer removed successfully']);
    }
}
