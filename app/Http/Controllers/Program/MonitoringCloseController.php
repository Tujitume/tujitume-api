<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\ProgramApplication;
use App\Service\Program\ApplicationCompletion;
use App\Service\Program\ProgramAccess;
use Illuminate\Http\Request;

/**
 * Owner decision 27 Nov: a funded application stays "in monitoring" until its M&E checkpoints are verified. When the
 * owner wants to finish without (more) checkpoints, they close monitoring here: recorded with who and when, and the
 * application is completed. Only once every milestone is finished; checkpoints still waiting are left as they are.
 */
class MonitoringCloseController extends Controller
{
    /** POST programs/applications/{application}/close-monitoring {note?} */
    public function close(Request $request, ProgramApplication $application)
    {
        if (! ProgramAccess::owns($application->program)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        $request->validate(['note' => 'nullable|string|max:1000']);

        if (! in_array($application->status, ApplicationCompletion::FUNDED, true)) {
            return response()->json(['message' => 'Only a funded application has monitoring to close.'], 422);
        }
        if ($application->status === 'completed') {
            return response()->json(['message' => 'This application is already completed.'], 422);
        }
        if (! ApplicationCompletion::milestonesFinished($application)) {
            return response()->json(['message' => 'Every milestone must be paid and approved before monitoring can be closed.'], 422);
        }

        $open = MECheckpoint::where('app_id', $application->id)->where('status', '!=', 'verified')->count();
        $application->update(['monitoring_closed_at' => now(), 'monitoring_closed_by' => $request->user()->id]);
        ApplicationCompletion::refresh($application);

        return response()->json([
            'message' => $open > 0
                ? "Monitoring closed with {$open} checkpoint(s) not verified. The application is completed."
                : 'Monitoring closed. The application is completed.',
            'data' => ['status' => $application->fresh()->status, 'open_checkpoints' => $open],
        ]);
    }
}
