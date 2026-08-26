<?php

namespace App\Http\Controllers\Program\Rounds;

use App\Http\Controllers\Controller;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Auth\User;
use App\Models\Programs\Rounds\RoundReviewer;
use App\Service\Account\RegisterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Service\Misc\ErrorLogService;
use PhpOffice\PhpSpreadsheet\Calculation\MathTrig\Round;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class RoundReviewerController extends Controller
{
    //added by owen
    /**
     * List reviewers for a round
     * GET /api/v1/program/rounds/{round}/reviewers
     */
    public function index(ProgramRound $round)
    {
        $userId = auth()->id();

        if ($round->program->user_id !== $userId) {
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

            $rounds = ProgramRound::with([
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
     * POST /api/v1/program/rounds/{round}/reviewers
     */
    public function store(Request $request, ProgramRound $round)
    {
        $regService = new RegisterService();

        $userId = auth()->id();

        if ($round->program->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'reviewer_type' => 'required|in:internal,external',
                'user_id' => 'required_if:reviewer_type,internal|exists:users,id',
                'name' => 'required_if:reviewer_type,external|string|max:255',
                'email' => 'required_if:reviewer_type,external|email',
                'max_apps_assigned' => 'nullable|integer|min:1',
                'expertise_tags' => 'nullable|array',
            ]);

            $userId = null; $randomPassword = null;
            if($request->reviewer_type == 'internal') {

                $userId = $validated['user_id'];
                $user = User::with('organizationRole')->find($userId);

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

                //$user->update(['user_type_id' => 6, // internal reviewer]);
                $user->organizationRole()->updateOrCreate(
                    ['user_id' => $userId],
                    ['organization_id' => $round->program->user->organization_id, 'role_id' => 10004]
                );

            }
            elseif ($request->reviewer_type == 'external') {
                
                $randomPassword = substr(bin2hex(random_bytes(5)), 0, rand(8, 10));
                $user = User::create([
                    'fname' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => $randomPassword,
                    'user_type_id' => 7, // external reviewer
                ]);

                $round->reviewers()->attach($user->id, [
                    'reviewer_type' => 'external',
                    'max_apps_assigned' => $validated['max_apps_assigned'] ?? null,
                    'expertise_tags' => isset($validated['expertise_tags']) ? json_encode($validated['expertise_tags']) : null,
                ]);

            }

            DB::commit();

            // Send notification to the reviewer
            if ($request->reviewer_type == 'external') {
                $this->programNotification->send('round.reviewer_invited_external', [$user], [
                    'program_title'   => $round->program->program_title,
                    'round_name'    => $round->round_name,
                    'email'         => $validated['email'],
                    'temp_password' => $randomPassword,
                    'max_apps'      => $validated['max_apps_assigned'] ?? null,
                    'expertise_tags' => $validated['expertise_tags'] ?? [],
                    'program_id'      => $round->program_id,
                ]);
            }

            $this->programNotification->send('round.scoring_assigned',
                [User::find($userId) ?? null],
                [
                'program_title' => $round->program->program_title, 'count' => 1, 'program_id' => $round->program_id,
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
     * DELETE /api/v1/program/rounds/{round}/reviewers/{user}
     */
    public function destroy(ProgramRound $round, User $user)
    {
        $userId = auth()->id();

        if ($round->program->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $round->reviewers()->detach($user->id);

        return response()->json(['message' => 'Reviewer removed successfully']);
    }
}
