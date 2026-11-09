<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

// it('uses a sanitized share message as the LinkedIn preview description', function () {
//     $message = 'A personal invitation for local growers';
//     $encoded = rawurlencode($message);

//     $response = $this->get('/program-preview/' . $this->program->public_id . '?share_message=' . $encoded)->assertOk();
//     $html = $response->getContent();

//     expect($html)
//         ->toContain('<meta property="og:description" content="' . $message . '">')
//         ->toContain('<meta property="og:url" content="https://tujitume.com/programs/' . $this->program->public_id . '?share_message=' . $encoded . '">');

//     $unsafeResponse = $this->get('/program-preview/' . $this->program->public_id . '?share_message=' . rawurlencode('<img src=x onerror=alert(1)>Funding for growers'))
//         ->assertOk();

//     expect($unsafeResponse->getContent())
//         ->toContain('content="Funding for growers"')
//         ->not->toContain('onerror=alert(1)');
// });

// it('provides published program details to the public React page without private data', function () {
//     $response = $this->getJson('/api/v1/public/programs/' . $this->program->public_id)->assertOk();

//     expect($response->json('data.program'))
//         ->toHaveKey('program_title', $this->program->program_title)
//         ->toHaveKey('rounds');
//     expect($response->json('data.program'))->not->toHaveKeys(['wallet', 'applications', 'questions', 'scoring_criteria']);

//     $this->program->update(['status' => 'draft']);
//     $this->getJson('/api/v1/public/programs/' . $this->program->public_id)->assertNotFound();
// });
