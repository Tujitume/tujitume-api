<?php

namespace Tests\Feature\Programs;

use App\Models\Business\BusinessSubscriptions;

/** "Your plan ends soon", 3 days before a plan ends: once, to the holder, not when already renewed. */
class SubscriptionReminderTest extends ProgramTestCase
{
    private function emailsTo(string $email): array
    {
        return array_values(array_filter(
            array_map(fn ($sent) => $sent->getOriginalMessage(), app('mailer')->getSymfonyTransport()->messages()->all()),
            fn ($m) => collect($m->getTo())->contains(fn ($a) => strcasecmp($a->getAddress(), $email) === 0)
        ));
    }

    private function plan(): BusinessSubscriptions
    {
        return BusinessSubscriptions::where('investor_id', $this->orgUser->id)->firstOrFail();
    }

    public function test_the_reminder_goes_once_three_days_before_the_end(): void
    {
        $this->plan()->update(['expire_date' => now()->addDays(5)]);
        $this->artisan('subscriptions:remind-ending')->assertSuccessful();
        $this->assertCount(0, $this->emailsTo($this->orgUser->email), 'too early');

        $this->travelTo(now()->addDays(2)->addHour());
        $this->artisan('subscriptions:remind-ending')->assertSuccessful();
        $this->artisan('subscriptions:remind-ending')->assertSuccessful();

        $mails = $this->emailsTo($this->orgUser->email);
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('Gold plan ends on', $mails[0]->getSubject());
        $html = (string) $mails[0]->getHtmlBody();
        $this->assertStringContainsString('create, import or publish programs', $html);
        $this->assertStringContainsString('Renew plan', $html);
        $this->assertNotNull($this->plan()->fresh()->ending_reminder_sent_at);
    }

    public function test_no_reminder_when_a_later_plan_is_already_running(): void
    {
        $this->plan()->update(['expire_date' => now()->addDays(2)]);
        BusinessSubscriptions::create(['plan' => 'gold', 'investor_id' => $this->orgUser->id, 'amount' => 29.99, 'trial' => 0,
            'start_date' => now(), 'expire_date' => now()->addDays(32), 'active' => 1, 'ending_reminder_sent_at' => null]);

        $this->artisan('subscriptions:remind-ending')->assertSuccessful();
        $this->assertCount(0, $this->emailsTo($this->orgUser->email));
    }
}
