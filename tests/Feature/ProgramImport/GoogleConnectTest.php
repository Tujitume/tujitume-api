<?php

use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportToken;
use App\Modules\ProgramImport\Pipeline\FormSpecStager;
use App\Modules\ProgramImport\Support\GoogleConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Tests\Feature\ProgramImport\ApiImportTestCase;

uses(ApiImportTestCase::class);

class PiFakeGoogleDriver
{
    public array $scopes = [];

    public ?string $state = null;

    public function stateless() { return $this; }

    public function redirectUrl($u) { return $this; }

    public function scopes($s) { $this->scopes = $s; return $this; }

    public function with($p) { $this->state = $p['state'] ?? $this->state; return $this; }

    public function redirect() { return new \Illuminate\Http\RedirectResponse('https://accounts.google.com/o/oauth2/auth?client_id=cid&scope='.urlencode(implode(' ', $this->scopes)).'&state='.urlencode((string) $this->state)); }

    public function user() { return (object) ['token' => 'ACCESS-TOKEN', 'refreshToken' => 'REFRESH-TOKEN', 'expiresIn' => 3600]; }
}

function pi_google_on(): PiFakeGoogleDriver
{
    config(['program_imports.google.oauth_enabled' => true, 'services.google.client_id' => 'cid', 'services.google.client_secret' => 'secret']);
    $d = new PiFakeGoogleDriver;
    Socialite::shouldReceive('driver')->with('google')->andReturn($d);

    return $d;
}

it('answers not configured until the flag and credentials exist', function () {
    $this->po()->getJson('/api/v1/program-imports/integrations/google')->assertOk()->assertJsonPath('data.configured', false);
    $this->po()->postJson('/api/v1/program-imports/integrations/google/connect')->assertStatus(422)->assertJsonPath('errors.code', 'google_not_configured');
    $this->po()->deleteJson('/api/v1/program-imports/integrations/google')->assertStatus(422)->assertJsonPath('errors.code', 'google_not_configured');
});

it('connects with read-only scopes, stores tokens encrypted, and disconnects', function () {
    $d = pi_google_on();
    $url = $this->po()->postJson('/api/v1/program-imports/integrations/google/connect')->assertOk()->json('data.url');
    expect($url)->toContain('forms.body.readonly')->toContain('spreadsheets.readonly')->not->toContain('drive')->and(GoogleConnection::SCOPES)->each->toContain('readonly');

    // Google sends the browser back with the signed state; no login is needed on that request
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $this->get('/api/v1/program-imports/integrations/google/callback?code=x&state='.urlencode($d->state))->assertRedirect();
    $t = ImportToken::first();
    expect($t->organization_id)->toBe($this->org->id)->and($t->access_token)->toBe('ACCESS-TOKEN')
        ->and(\Illuminate\Support\Facades\DB::table('program_import_tokens')->value('access_token'))->not->toBe('ACCESS-TOKEN')->not->toContain('ACCESS');

    $this->po()->getJson('/api/v1/program-imports/integrations/google')->assertJsonPath('data.connected', true);
    $this->po()->deleteJson('/api/v1/program-imports/integrations/google')->assertOk();
    expect(ImportToken::count())->toBe(0);
});

it('refuses a forged or expired state', function () {
    pi_google_on();
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $this->getJson('/api/v1/program-imports/integrations/google/callback?code=x&state=forged')->assertStatus(422)->assertJsonPath('errors.code', 'google_state');
    $old = encrypt(['o' => $this->org->id, 'u' => $this->orgUser->id, 'exp' => now()->subMinute()->timestamp]);
    $this->getJson('/api/v1/program-imports/integrations/google/callback?code=x&state='.urlencode($old))->assertStatus(422);
    expect(ImportToken::count())->toBe(0);
});

it('reads a form that needs a sign-in through the connected account', function () {
    pi_google_on();
    ImportToken::create(['organization_id' => $this->org->id, 'user_id' => $this->orgUser->id, 'provider' => 'google', 'access_token' => 'ACCESS-TOKEN', 'expires_at' => now()->addHour()]);
    app()->instance('program_imports.resolver', fn () => ['142.250.1.1']);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        'docs.google.com/*' => Http::response('<html>sign in</html>'),
        'forms.googleapis.com/*' => Http::response(['info' => ['title' => 'Private Fund', 'description' => 'd'], 'items' => [
            ['title' => 'Business name', 'questionItem' => ['question' => ['required' => true, 'textQuestion' => ['paragraph' => false]]]],
            ['title' => 'Sector', 'questionItem' => ['question' => ['choiceQuestion' => ['type' => 'RADIO', 'options' => [['value' => 'Agri'], ['value' => 'Health']]]]]],
            ['title' => 'A section', 'textItem' => []],
        ]]),
    ]);
    $b = ImportBatch::create(['organization_id' => $this->org->id, 'user_id' => $this->orgUser->id, 'type' => 'form', 'start_point' => 'setup', 'source' => 'google_form_url', 'status' => 'uploaded']);
    (new FormSpecStager)->extract($b, 'https://docs.google.com/forms/d/e/1FAIpQLSabcdefghijk/viewform');
    $spec = $b->refresh()->form_spec;
    expect($spec['title'])->toBe('Private Fund')->and(array_column($spec['questions'], 'type'))->toBe(['text', 'choice'])->and($spec['questions'][1]['options'])->toBe(['Agri', 'Health']);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'forms.googleapis.com') && $r->hasHeader('Authorization', 'Bearer ACCESS-TOKEN'));
});
