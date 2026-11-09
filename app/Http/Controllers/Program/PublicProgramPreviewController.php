<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Programs\Program;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Service\Misc\ErrorLogService;
use App\Support\ApiContract;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicProgramPreviewController extends Controller
{
    /** Return only fields intended for a public program detail page. */
    public function details(string $publicId)
    {
        try {
            $program = Program::with([
                'rounds.questions',
            ])
                ->withCount('liked')
                ->where('public_id', $publicId)
                ->where('status', 'published')
                ->first();

            //  Handle not found
            if (!$program) {
                return response()->json([
                    'success' => false,
                    'message' => 'Program not found'
                ], 404);
            }

            //  No extra query (use loaded relation)
            $round1 = $program->rounds->first();
            if ($round1 && $round1->relationLoaded('questions')) {
                $round1->setRelation('questions', $round1->questions->map(
                    fn (RoundCustomQuestion $question) => $question->applicantView()
                ));
            }
            //  Better naming (no overwrite)
            if (Auth::check()) {
                $program->liked = $program->liked()
                    ->where('user_id', Auth::id())
                    ->exists();
            }

            return ApiContract::itemResponse(
                'Program fetched successfully.',
                'program',
                $program,
                ['application_round' => $round1],
                ['program_data' => $program, 'application_round' => $round1],
            );
        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function show(Request $request, string $publicId)
    {
        $program = Program::query()
            ->where('public_id', $publicId)
            ->where('status', 'published')
            ->first();

        abort_unless($program, 404);

        // The public page only needs application dates and required documents. Keep
        // reviewer questions, scoring rules, assignments, and wallet data private.
        $program->load(['owner', 'rounds' => fn ($query) => $query->orderBy('round_number')]);
        $program->owner_website = $program->owner->website ?? null;

        $url = 'https://tujitume.com/programs/' . rawurlencode($program->public_id);
        // The share link carries a version that changes whenever the program is edited. LinkedIn keeps what it read
        // for a link for days; a new version makes it a new link, so it reads the current name and description.
        $version = preg_replace('/\D/', '', (string) $request->query('v', ''));
        if ($version !== '') {
            $url .= '?v=' . substr($version, 0, 20);
        }
        $title = $program->program_title ?: 'Program opportunity';
        $description = $program->description ?: ($program->message ?: 'View this program opportunity on Tujitume.');
        $requestMessage = $request->query('share_message', '');
        $shareMessage = Str::of(is_string($requestMessage) ? $requestMessage : '')
            ->stripTags()
            ->squish()
            ->limit(1000, '')
            ->toString();
        $ogDescription = $shareMessage !== '' ? Str::limit($shareMessage, 300, '') : $description;
        $previewUrl = $shareMessage !== '' ? $url . ($version !== '' ? '&' : '?') . 'share_message=' . rawurlencode($shareMessage) : $url;
        $image = $program->organization_logo ?: 'https://tujitume.com/pwa-light-512x512.png';
        $firstRound = $program->rounds->first();
        $isOpen = in_array($program->status, ['published', 'open'], true)
            && (! $program->application_deadline || \Illuminate\Support\Carbon::parse($program->application_deadline)->endOfDay()->isFuture());

        return response()->view('program-preview', compact('program', 'url', 'previewUrl', 'title', 'description', 'ogDescription', 'image', 'firstRound', 'isOpen'))
            ->header('Cache-Control', 'public, max-age=300');
    }
}
