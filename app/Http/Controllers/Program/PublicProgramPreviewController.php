<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Programs\Program;

class PublicProgramPreviewController extends Controller
{
    public function show(string $publicId)
    {
        $program = Program::query()
            ->where('public_id', $publicId)
            ->where('status', 'published')
            ->first();

        abort_unless($program, 404);

        $url = 'https://tujitume.com/programs/' . rawurlencode($program->public_id);
        $title = $program->program_title ?: 'Program opportunity';
        $description = $program->description ?: ($program->message ?: 'View this program opportunity on Tujitume.');
        $image = $program->organization_logo ?: 'https://tujitume.com/pwa-light-512x512.png';

        return response()->view('program-preview', compact('program', 'url', 'title', 'description', 'image'))
            ->header('Cache-Control', 'public, max-age=300');
    }
}
