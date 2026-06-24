<?php

namespace App\Http\Controllers\Grant;

use App\Http\Controllers\Controller;
use App\Models\GrantEmailTemplate;
use App\Models\Grants\Grant;
use Illuminate\Http\Request;

class GrantEmailTemplateController extends Controller
{
    /**
     * GET /api/grants/{grant}/email-templates
     * Returns all email templates for a grant
     */
    public function index(Grant $grant)
    {
        $this->authorizeGrantOwner($grant);

        $templates = GrantEmailTemplate::where('grant_id', $grant->id)->get();

        // Return all customizable events with their templates (or null if not customized)
        $result = collect(GrantEmailTemplate::CUSTOMISABLE_EVENTS)->map(function ($event) use ($templates) {
            $template = $templates->firstWhere('event', $event);
            return [
                'event' => $event,
                'body_html' => $template?->body_html,
            ];
        });

        return response()->json(['templates' => $result]);
    }

    /**
     * GET /api/grants/{grant}/email-templates/{event}
     * Returns the saved custom body (or null if using the default).
     */
    public function show(Grant $grant, string $event)
    {
        $this->authorizeGrantOwner($grant);

        $template = GrantEmailTemplate::where('grant_id', $grant->id)
            ->where('event', $event)
            ->first();

        return response()->json([
            'event'     => $event,
            'body_html' => $template?->body_html,   // null = no customisation yet
        ]);
    }

    /**
     * PUT /api/grants/{grant}/email-templates/{event}
     * Upserts the custom body for a grant + event pair.
     */
    public function upsert(Request $request, Grant $grant, string $event)
    {
        $this->authorizeGrantOwner($grant);

        $request->validate([
            'event'     => ['required', 'string', 'in:' . implode(',', GrantEmailTemplate::CUSTOMISABLE_EVENTS)],
            'body_html' => ['required', 'string', 'max:10000'],
        ]);

        $template = GrantEmailTemplate::updateOrCreate(
            ['grant_id' => $grant->id, 'event' => $event],
            ['body_html' => $request->body_html],
        );

        return response()->json(['message' => 'Template saved.', 'template' => $template]);
    }

    /**
     * DELETE /api/grants/{grant}/email-templates/{event}
     * Reverts a single event back to the system default.
     */
    public function destroy(Grant $grant, string $event)
    {
        $this->authorizeGrantOwner($grant);

        GrantEmailTemplate::where('grant_id', $grant->id)
            ->where('event', $event)
            ->delete();

        return response()->json(['message' => 'Template reset to default.']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function authorizeGrantOwner(Grant $grant): void
    {
        // Swap for your actual ownership check / policy
        abort_unless(auth()->id() === $grant->user_id, 403);
    }
}

