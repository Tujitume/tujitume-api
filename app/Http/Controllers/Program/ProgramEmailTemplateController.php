<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\ProgramEmailTemplate;
use App\Models\Programs\Program;
use App\Service\Notification\ProgramEmailDefaults;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The applicant emails a program owner may write (ProgramEmailTemplate::CUSTOMISABLE_EVENTS). Every one is
 * optional: an email the owner does not write goes out as the system default (ProgramEmailDefaults), in the
 * program's email language.
 */
class ProgramEmailTemplateController extends Controller
{
    /**
     * GET /programs/{program}/email-templates
     * The saved templates (null body = the system default is sent) and the program's email language.
     */
    public function index(Program $program)
    {
        $this->authorizeProgramOwner($program);

        $templates = ProgramEmailTemplate::where('program_id', $program->id)->get();

        $result = collect(ProgramEmailTemplate::CUSTOMISABLE_EVENTS)->map(function ($event) use ($templates) {
            $template = $templates->firstWhere('event', $event);
            return [
                'id' => $template?->id,
                'event' => $event,
                'subject' => $template?->subject,
                'body_html' => $template?->body_html,
                'updated_at' => $template?->updated_at,
            ];
        })->values();

        return response()->json([
            'locale' => ProgramEmailDefaults::locale($program->email_locale),
            'templates' => $result,
        ]);
    }

    /**
     * GET /programs/{program}/email-templates/defaults?locale=sw
     * The system default of every email, exactly as it is sent when the owner writes nothing:
     * subject, heading, body and button, with the {{tokens}} each one can use. Without ?locale the
     * program's email language is used.
     */
    public function defaults(Request $request, Program $program)
    {
        $this->authorizeProgramOwner($program);

        $request->validate(['locale' => ['nullable', Rule::in(ProgramEmailDefaults::LOCALES)]]);
        $locale = ProgramEmailDefaults::locale($request->query('locale', $program->email_locale));

        $defaults = collect(ProgramEmailTemplate::CUSTOMISABLE_EVENTS)->map(fn ($event) => [
            'event' => $event,
            ...ProgramEmailDefaults::forEvent($event, $locale),
            'variables' => array_map(fn ($token) => '{{' . $token . '}}', ProgramEmailDefaults::VARIABLES[$event] ?? []),
        ])->values();

        return response()->json([
            'locale' => $locale,
            'locales' => ProgramEmailDefaults::LOCALES,
            'defaults' => $defaults,
        ]);
    }

    /**
     * PATCH /programs/{program}/email-templates/language  { locale: en|sw }
     * The language this program's applicant emails go out in: the system defaults, and the heading,
     * button and footer around an email the owner wrote.
     */
    public function language(Request $request, Program $program)
    {
        $this->authorizeProgramOwner($program);

        $data = $request->validate(['locale' => ['required', Rule::in(ProgramEmailDefaults::LOCALES)]]);
        $program->forceFill(['email_locale' => $data['locale']])->save();

        return response()->json(['message' => 'Email language saved.', 'locale' => $data['locale']]);
    }

    /**
     * GET /programs/{program}/email-templates/{event}
     * The saved subject and body (null = the system default is sent).
     */
    public function show(Program $program, string $event)
    {
        $this->authorizeProgramOwner($program);
        $this->assertEvent($event);

        $template = ProgramEmailTemplate::where('program_id', $program->id)
            ->where('event', $event)
            ->first();

        return response()->json([
            'event' => $event,
            'subject' => $template?->subject,
            'body_html' => $template?->body_html,
        ]);
    }

    /**
     * PATCH /programs/{program}/email-templates/{event}  { body_html, subject? }
     * Saves the owner's own version of one email. An empty subject keeps the default subject.
     */
    public function upsert(Request $request, Program $program, string $event)
    {
        $this->authorizeProgramOwner($program);

        $request->merge(['event' => $event]);
        $data = $request->validate([
            'event' => ['required', 'string', Rule::in(ProgramEmailTemplate::CUSTOMISABLE_EVENTS)],
            'subject' => ['nullable', 'string', 'max:200'],
            'body_html' => ['required', 'string', 'max:10000'],
        ]);

        $body = ProgramEmailDefaults::sanitize($data['body_html']);
        if (trim(strip_tags($body)) === '') {
            return response()->json(['message' => 'The email cannot be empty.', 'errors' => ['body_html' => ['The email cannot be empty.']]], 422);
        }
        $subject = trim(strip_tags((string) ($data['subject'] ?? '')));

        $template = ProgramEmailTemplate::updateOrCreate(
            ['program_id' => $program->id, 'event' => $event],
            ['body_html' => $body, 'subject' => $subject !== '' ? $subject : null],
        );

        return response()->json(['message' => 'Template saved.', 'template' => $template]);
    }

    /**
     * DELETE /programs/{program}/email-templates/{event}
     * Goes back to the system default for one email.
     */
    public function destroy(Program $program, string $event)
    {
        $this->authorizeProgramOwner($program);
        $this->assertEvent($event);

        ProgramEmailTemplate::where('program_id', $program->id)
            ->where('event', $event)
            ->delete();

        return response()->json(['message' => 'Template reset to default.']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function authorizeProgramOwner(Program $program): void
    {
        abort_unless(auth()->id() === $program->user_id, 403);
    }

    private function assertEvent(string $event): void
    {
        abort_unless(in_array($event, ProgramEmailTemplate::CUSTOMISABLE_EVENTS, true), 404);
    }
}
