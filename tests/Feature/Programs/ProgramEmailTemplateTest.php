<?php

namespace Tests\Feature\Programs;

use App\Models\ProgramEmailTemplate;
use App\Service\Notification\ProgramNotificationService;

/**
 * The four applicant emails an owner may write: all optional, the system default is served by the API,
 * and the program's email language decides how a default (and the words around a custom email) go out.
 */
class ProgramEmailTemplateTest extends ProgramTestCase
{
    private function sentEmails(): array
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($m) => $m->getOriginalMessage())
            ->map(fn ($e) => ['subject' => $e->getSubject(), 'html' => html_entity_decode($e->getHtmlBody(), ENT_QUOTES)])
            ->all();
    }

    public function test_the_defaults_are_served_in_the_language_asked_for(): void
    {
        $sw = $this->actingAsOrgOwner()->getJson("/api/v1/programs/{$this->program->id}/email-templates/defaults?locale=sw")->assertOk();
        $advanced = collect($sw->json('defaults') ?? $sw->json('data.defaults'))->firstWhere('event', 'round.advanced');

        $this->assertStringContainsString('Habari {{applicant_name}}', $advanced['body_html']);
        $this->assertSame('Fungua ombi langu', $advanced['button_label']);
        $this->assertContains('{{round_name}}', $advanced['variables']);
    }

    public function test_only_the_owner_reads_or_changes_the_emails(): void
    {
        $this->actingAsApplicant()->getJson("/api/v1/programs/{$this->program->id}/email-templates/defaults")->assertForbidden();
        $this->actingAsApplicant()->patchJson("/api/v1/programs/{$this->program->id}/email-templates/language", ['locale' => 'sw'])->assertForbidden();
    }

    public function test_a_saved_body_is_cleaned_and_keeps_its_own_subject(): void
    {
        $this->actingAsOrgOwner()->patchJson("/api/v1/programs/{$this->program->id}/email-templates/application.rejected", [
            'subject' => 'Samahani {{applicant_name}}',
            'body_html' => '<p onclick="steal()">Habari</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
        ])->assertOk();

        $saved = ProgramEmailTemplate::where('program_id', $this->program->id)->where('event', 'application.rejected')->first();
        $this->assertSame('Samahani {{applicant_name}}', $saved->subject);
        $this->assertStringNotContainsString('script', $saved->body_html);
        $this->assertStringNotContainsString('onclick', $saved->body_html);
        $this->assertStringNotContainsString('javascript:', $saved->body_html);
    }

    public function test_a_program_in_kiswahili_sends_the_default_and_a_custom_email_in_kiswahili(): void
    {
        config(['mail.default' => 'array']);
        $this->actingAsOrgOwner()->patchJson("/api/v1/programs/{$this->program->id}/email-templates/language", ['locale' => 'sw'])->assertOk();
        ProgramEmailTemplate::create([
            'program_id' => $this->program->id, 'event' => 'application.rejected',
            'subject' => 'Samahani {{applicant_name}}', 'body_html' => '<p>Habari {{applicant_name}}, ujumbe wangu.</p>',
        ]);

        $service = new ProgramNotificationService();
        // Only application_id is passed, as some callers do: the program is found from the application
        $service->send('round.not_selected', [$this->applicantUser], ['program_title' => 'Ruzuku', 'round_name' => 'Raundi 1', 'application_id' => $this->application->id]);
        $service->send('application.rejected', [$this->applicantUser], ['program_title' => 'Ruzuku', 'program_id' => $this->program->id]);

        [$default, $custom] = $this->sentEmails();
        $name = $this->applicantUser->first_name;

        $this->assertSame('Taarifa kuhusu ombi lako kwa Ruzuku', $default['subject']);
        $this->assertStringContainsString("Habari {$name},", $default['html']);
        $this->assertStringContainsString('Tazama programu', $default['html']);

        $this->assertSame("Samahani {$name}", $custom['subject']);
        $this->assertStringContainsString("Habari {$name}, ujumbe wangu.", $custom['html']);
        $this->assertStringContainsString('Wako,', $custom['html']);
        $this->assertStringNotContainsString('Best regards', $custom['html']);
    }

    public function test_going_back_to_the_default_removes_the_owners_version(): void
    {
        ProgramEmailTemplate::create(['program_id' => $this->program->id, 'event' => 'round.advanced', 'body_html' => '<p>Mine</p>']);

        $this->actingAsOrgOwner()->deleteJson("/api/v1/programs/{$this->program->id}/email-templates/round.advanced")->assertOk();

        $this->assertDatabaseMissing('program_email_templates', ['program_id' => $this->program->id, 'event' => 'round.advanced']);
    }

    public function test_an_award_uses_the_owners_accepted_email_and_otherwise_the_award_email(): void
    {
        config(['mail.default' => 'array']);
        $service = new ProgramNotificationService();
        $data = ['program_title' => 'Green Fund', 'amount' => 50000, 'application_id' => $this->application->id];

        $service->send('application.awarded', [$this->applicantUser], $data);
        ProgramEmailTemplate::create([
            'program_id' => $this->program->id, 'event' => 'application.accepted',
            'subject' => 'Well done {{applicant_name}}', 'body_html' => '<p>Our own words for {{program_title}}.</p>',
        ]);
        $service->send('application.awarded', [$this->applicantUser], $data);

        [$default, $custom] = $this->sentEmails();
        $this->assertStringNotContainsString('Our own words', $default['html']);
        $this->assertSame("Well done {$this->applicantUser->first_name}", $custom['subject']);
        $this->assertStringContainsString('Our own words for Green Fund.', $custom['html']);
    }

    public function test_accepting_into_round_two_sends_one_email_the_owners_round_advanced(): void
    {
        config(['mail.default' => 'array']);
        $this->program->update(['program_type' => 'multi_round', 'total_rounds' => 2]);
        \App\Models\Programs\Rounds\ProgramRound::factory()->create(['program_id' => $this->program->id, 'round_number' => 2, 'round_name' => 'Pitch day', 'status' => 'published']);
        ProgramEmailTemplate::create([
            'program_id' => $this->program->id, 'event' => 'round.advanced',
            'subject' => 'On to {{round_name}}', 'body_html' => '<p>See you at {{round_name}}.</p>',
        ]);

        $this->actingAsOrgOwner()->postJson("/api/v1/programs/applications/{$this->application->id}/accept")->assertOk();

        $sent = $this->sentEmails();
        $this->assertCount(1, $sent);
        $this->assertSame('On to Pitch day', $sent[0]['subject']);
        $this->assertStringContainsString('See you at Pitch day.', $sent[0]['html']);
    }
}
