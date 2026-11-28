<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Programs\Program;
use App\Service\Misc\ErrorLogService;
use App\Service\Program\ProgramAccess;
use App\Service\Program\ProgramMedia;
use Illuminate\Http\Request;

/**
 * The program's cover image and intro video, set from the program page. The owner and the organization's program team
 * may use these (ProgramAccess::owns); ProgramMiddleware keeps Viewers read only. Removing is a POST so an Editor,
 * who may not send DELETE, can still take a cover or video off.
 */
class ProgramMediaController extends Controller
{
    public function __construct(private ProgramMedia $media)
    {
        parent::__construct();
    }

    // POST /programs/programs/{program}/cover   (multipart: cover_image)
    public function uploadCover(Request $request, Program $program)
    {
        abort_unless(ProgramAccess::owns($program), 403, ProgramAccess::DENIED);

        $request->validate(['cover_image' => array_merge(['required'], ProgramMedia::COVER_RULES)]);

        try {
            $this->media->storeCover($program, $request->file('cover_image'));
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['program_id' => $program->id]);

            return response()->json(['success' => false, 'message' => 'The cover image could not be saved. Please try again.'], 500);
        }

        return $this->respond('Cover image saved.', $program);
    }

    // POST /programs/programs/{program}/cover/remove
    public function removeCover(Program $program)
    {
        abort_unless(ProgramAccess::owns($program), 403, ProgramAccess::DENIED);

        $this->media->removeCover($program);

        return $this->respond('Cover image removed.', $program);
    }

    // POST /programs/programs/{program}/intro-video   (intro_video_url; empty clears it)
    public function setVideo(Request $request, Program $program)
    {
        abort_unless(ProgramAccess::owns($program), 403, ProgramAccess::DENIED);

        $validated = $request->validate(['intro_video_url' => array_merge(['present', 'nullable'], ProgramMedia::videoRules())]);

        $url = isset($validated['intro_video_url']) ? trim($validated['intro_video_url']) : null;
        $program->forceFill(['intro_video_url' => $url ?: null])->save();

        return $this->respond($url ? 'Intro video saved.' : 'Intro video removed.', $program);
    }

    // POST /programs/programs/{program}/intro-video/remove
    public function removeVideo(Program $program)
    {
        abort_unless(ProgramAccess::owns($program), 403, ProgramAccess::DENIED);

        $program->forceFill(['intro_video_url' => null])->save();

        return $this->respond('Intro video removed.', $program);
    }

    private function respond(string $message, Program $program)
    {
        $program->refresh();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'id' => $program->id,
                'cover_image_url' => $program->cover_image_url,
                'intro_video_url' => $program->intro_video_url,
            ],
        ]);
    }
}
