<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Programs\Program;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicProgramPreviewController extends Controller
{
    /** Return only fields intended for a public program detail page. */
    public function details(string $publicId)
    {
        $program = Program::query()
            ->where('public_id', $publicId)
            ->where('status', 'published')
            ->with(['owner', 'rounds' => fn ($query) => $query->orderBy('round_number')])
            ->first();

        abort_unless($program, 404);

        return response()->json(['data' => $program ])->header('Cache-Control', 'public, max-age=300'); 

        return response()->json(['data' => ['program' => [
            'public_id' => $program->public_id,
            'program_title' => $program->program_title,
            'description' => $program->description ?: ($program->message ?? null),
            'organization_logo' => $program->organization_logo,
            'organization_name' => $program->owner?->display_name,
            'owner_website' => $program->owner->website ?? null,
            'total_program_amount' => $program->total_program_amount,
            'funding_per_business' => $program->funding_per_business,
            'currency' => $program->currency,
            'max_awardees' => $program->max_awardees,
            'disbursement_type' => $program->disbursement_type,
            'status' => $program->status,
            'application_start_date' => $program->application_start_date,
            'application_deadline' => $program->application_deadline,
            'rounds' => $program->rounds->map(fn ($round) => [
                'round_number' => $round->round_number,
                'round_name' => $round->round_name,
                'open_date' => $round->open_date,
                'close_date' => $round->close_date,
                'review_period_end' => $round->review_period_end,
                'announcement_date' => $round->announcement_date,
                'required_documents' => $round->required_documents,
            ])->values(),
        ]]])->header('Cache-Control', 'public, max-age=300');
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
        $title = $program->program_title ?: 'Program opportunity';
        $description = $program->description ?: ($program->message ?: 'View this program opportunity on Tujitume.');
        $requestMessage = $request->query('share_message', '');
        $shareMessage = Str::of(is_string($requestMessage) ? $requestMessage : '')
            ->stripTags()
            ->squish()
            ->limit(1000, '')
            ->toString();
        $ogDescription = $shareMessage !== '' ? Str::limit($shareMessage, 300, '') : $description;
        $previewUrl = $shareMessage !== '' ? $url . '?share_message=' . rawurlencode($shareMessage) : $url;
        $image = $program->organization_logo ?: 'https://tujitume.com/pwa-light-512x512.png';
        $firstRound = $program->rounds->first();
        $isOpen = in_array($program->status, ['published', 'open'], true)
            && (! $program->application_deadline || \Illuminate\Support\Carbon::parse($program->application_deadline)->endOfDay()->isFuture());

        return response()->view('program-preview', compact('program', 'url', 'previewUrl', 'title', 'description', 'ogDescription', 'image', 'firstRound', 'isOpen'))
            ->header('Cache-Control', 'public, max-age=300');
    }
}
