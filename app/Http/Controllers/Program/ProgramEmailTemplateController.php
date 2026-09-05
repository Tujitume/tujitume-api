<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\ProgramEmailTemplate;
use App\Models\Programs\Program;
use Illuminate\Http\Request;

class ProgramEmailTemplateController extends Controller
{
    /**
     * GET /api/programs/{program}/email-templates
     * Returns all email templates for a program
     */
    public function index(Program $program)
    {
        $this->authorizeProgramOwner($program);

        $templates = ProgramEmailTemplate::where('program_id', $program->id)->get();

        // Return all customizable events with their templates (or null if not customized)
        $result = collect(ProgramEmailTemplate::CUSTOMISABLE_EVENTS)->map(function ($event) use ($templates) {
            $template = $templates->firstWhere('event', $event);
            return [
                'id' => $template?->id,
                'event' => $event,
                'body_html' => $template?->body_html,
            ];
        });

        return response()->json(['templates' => $result]);
    }

    /**
     * GET /api/programs/{program}/email-templates/{event}
     * Returns the saved custom body (or null if using the default).
     */
    public function show(Program $program, string $event)
    {
        $this->authorizeProgramOwner($program);

        $template = ProgramEmailTemplate::where('program_id', $program->id)
            ->where('event', $event)
            ->first();

        return response()->json([
            'event'     => $event,
            'body_html' => $template?->body_html,   // null = no customisation yet
        ]);
    }

    /**
     * PUT /api/programs/{program}/email-templates/{event}
     * Upserts the custom body for a program + event pair.
     */
    public function upsert(Request $request, Program $program, string $event)
    {
        $this->authorizeProgramOwner($program);

        $request->validate([
            'event'     => ['required', 'string', 'in:' . implode(',', ProgramEmailTemplate::CUSTOMISABLE_EVENTS)],
            'body_html' => ['required', 'string', 'max:10000'],
        ]);

        $template = ProgramEmailTemplate::updateOrCreate(
            ['program_id' => $program->id, 'event' => $event],
            ['body_html' => $request->body_html],
        );

        return response()->json(['message' => 'Template saved.', 'template' => $template]);
    }

    /**
     * DELETE /api/programs/{program}/email-templates/{event}
     * Reverts a single event back to the system default.
     */
    public function destroy(Program $program, string $event)
    {
        $this->authorizeProgramOwner($program);

        ProgramEmailTemplate::where('program_id', $program->id)
            ->where('event', $event)
            ->delete();

        return response()->json(['message' => 'Template reset to default.']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function authorizeProgramOwner(Program $program): void
    {
        // Swap for your actual ownership check / policy
        abort_unless(auth()->id() === $program->user_id, 403);
    }
}

