<?php

use App\Service\Program\ApplicationTrackingLink;

uses(Tests\Feature\Programs\ProgramTestCase::class);

it('returns the real email next to the masked one, only with a valid signed link', function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $this->application->update(['uid' => 'ABC123', 'email' => 'Guest.Person@Example.com', 'user_id' => null]);
    parse_str(parse_url(ApplicationTrackingLink::url($this->application->fresh()), PHP_URL_QUERY), $query);

    $res = $this->getJson('/api/v1/public/applications/track/ABC123?'.http_build_query($query))->assertOk();
    expect($res->json('data.contact_email'))->toBe('guest.person@example.com')
        ->and($res->json('data.email'))->toContain('*')->not->toBe('guest.person@example.com');

    // A wrong or missing token reveals nothing
    $this->getJson('/api/v1/public/applications/track/ABC123?e='.$query['e'].'&t=wrong')->assertStatus(404)->assertJsonMissing(['contact_email']);
    $this->getJson('/api/v1/public/applications/track/ABC123')->assertStatus(404);
});
