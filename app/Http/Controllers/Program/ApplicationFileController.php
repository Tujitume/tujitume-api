<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Service\Program\ProgramAccess;
use Illuminate\Support\Facades\Storage;

/**
 * Streams a file an applicant uploaded for a round question, so the review page can read it in the browser (Amari's
 * document reading) without the storage bucket having to allow cross-site reads. Only the program's owner side and
 * the Business Coaches assigned to this application can open it.
 */
class ApplicationFileController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    /** GET applications/{application}/answers/{answer}/file?index=0 */
    public function answerFile(ProgramApplication $application, ApplicationRoundResponse $answer)
    {
        if ((int) $answer->application_id !== (int) $application->id) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $round = $application->currentRound ?? $answer->round;
        $user = auth()->user();
        $allowed = $round && (
            ProgramAccess::owns($round->program)
            || ReviewerApplicationAssignment::where(['round_id' => $round->id, 'application_id' => $application->id, 'reviewer_id' => $user->id])->exists()
        );
        if (! $allowed) {
            return response()->json(['message' => 'You do not have access to this application.'], 403);
        }

        $path = $answer->allFilePaths()[(int) request('index', 0)] ?? null;
        $disk = Storage::disk('s3');
        if (! $path || ! $disk->exists($path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return $disk->response($path, basename($path), [
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
