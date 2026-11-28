<?php

uses(Tests\Feature\Capital\CapitalTestCase::class);

// Every capital step that needs the other side to act is told by email too, to the right person
function capitalMails(): \Illuminate\Support\Collection
{
    return collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => [
        'to' => $m->getOriginalMessage()->getTo()[0]->getAddress(),
        'subject' => $m->getOriginalMessage()->getSubject(),
    ]);
}

it('emails the entrepreneur when the application is accepted and when it is not selected', function () {
    config(['mail.default' => 'array']);
    $accepted = $this->makePitch();
    $this->asCapital()->getJson("/api/v1/capital/accept/{$accepted->id}")->assertOk();

    $rejected = $this->makePitch(applicant: \App\Models\Auth\User::factory()->create(['user_type_id' => 1]));
    $this->asCapital()->postJson("/api/v1/capital/reject/{$rejected->id}", ['reason' => 'Outside our regions'])->assertOk();

    $mails = capitalMails();
    expect($mails->firstWhere('to', $accepted->sme->email)['subject'] ?? null)->toStartWith('Your capital application was accepted')
        ->and($mails->firstWhere('to', $rejected->sme->email)['subject'] ?? null)->toStartWith('An update on your capital application');
});

it('emails the capital provider when the entrepreneur asks for the next release', function () {
    config(['mail.default' => 'array']);
    [$pitch] = $this->fundablePitch();

    $this->actingAs($pitch->sme, 'sanctum')->getJson("/api/v1/capital/fund-release-request/{$pitch->id}")->assertOk();

    expect(capitalMails()->firstWhere('to', $this->capitalOwner->email)['subject'] ?? null)->toStartWith('Release requested: Pilot');
    expect(\Illuminate\Support\Facades\DB::table('notifications')->where('receiver_id', $this->capitalOwner->id)->latest('id')->value('text'))->toContain('2,000');
});
