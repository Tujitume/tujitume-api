<?php

use App\Models\Admin;
use App\Models\Amari\AmariUsageLog;
use App\Models\Amari\AmariUserLimit;
use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Service\AI\AmariUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * Admin › Amari usage: every request through /amari/chat is recorded per account (with its organization) or per
 * landing-page visitor, with its tokens; daily limits (default and per account) and pauses are enforced; the admin
 * pages list accounts, organizations, visitors and activity, export them, and save limits. Never the question text.
 */
beforeEach(function () {
    config(['services.amari.key' => 'k', 'services.amari.url' => 'https://amari.test/chat', 'services.amari.model' => 'answer-model',
        'services.amari.public_model' => 'answer-model', 'services.amari.guard_model' => 'guard-model']);
});

function amariAnswer(int $in = 120, int $out = 30): array
{
    return ['model' => 'answer-model', 'choices' => [['message' => ['content' => 'Open Programs › Create.']]], 'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out]];
}

function fakeAmari(): void
{
    Http::fake(function ($request) {
        if (($request['model'] ?? null) === 'guard-model') {
            return Http::response(['choices' => [['message' => ['content' => '0.01']]]]);
        }

        return Http::response(amariAnswer());
    });
}

function amariAsk(array $extra = [], array $headers = [])
{
    return test()->withHeaders($headers)->postJson('/api/v1/amari/chat', $extra + [
        'stream' => false,
        'messages' => [['role' => 'system', 'content' => 'rules'], ['role' => 'user', 'content' => 'How do I create a program?']],
    ]);
}

function amariMember(): User
{
    $owner = User::factory()->create(['user_type_id' => 4, 'first_name' => 'Wanjiru', 'last_name' => 'Kamau']);
    $org = Organization::create(['name' => 'Impala Fund', 'owner_user_id' => $owner->id]);
    $owner->update(['organization_id' => $org->id]);

    return $owner->fresh();
}

function amariAdmin(): Admin
{
    return Admin::create(['name' => 'Ops', 'email' => 'ops@tujitume.test', 'password' => Hash::make('secret')]);
}

it('records a signed-in request with its account, organization, feature and the provider\'s token counts, never the question', function () {
    fakeAmari();
    $user = amariMember();

    $this->actingAs($user, 'sanctum');
    amariAsk(['feature' => 'match_score'])->assertOk()->assertJsonPath('choices.0.message.content', 'Open Programs › Create.');

    $log = AmariUsageLog::sole();
    expect($log->user_id)->toBe($user->id)
        ->and($log->organization_id)->toBe($user->organization_id)
        ->and($log->user_type_id)->toBe(4)
        ->and($log->guest_key)->toBeNull()
        ->and($log->profile)->toBe('portal')
        ->and($log->feature)->toBe('match_score')
        ->and($log->status)->toBe('answered')
        ->and([$log->input_tokens, $log->output_tokens, $log->total_tokens])->toBe([120, 30, 150])
        ->and($log->tokens_estimated)->toBeFalse()
        ->and(json_encode($log->getAttributes()))->not->toContain('create a program');
    // The signed-in account block goes with it (this crashed before: the helper expected a User class that did not exist)
    Http::assertSent(fn ($r) => collect($r['messages'] ?? [])->contains(fn ($m) => str_starts_with((string) ($m['content'] ?? ''), 'ACCOUNT ')));
});

it('reads the tokens from the last chunk of a streamed answer (Groq x_groq.usage) and passes the stream through', function () {
    $sse = "data: ".json_encode(['choices' => [['delta' => ['content' => 'Hello']]]])."\n\n"
        ."data: ".json_encode(['choices' => [['delta' => []]], 'x_groq' => ['usage' => ['prompt_tokens' => 900, 'completion_tokens' => 45]]])."\n\n"
        ."data: [DONE]\n\n";
    Http::fake(['amari.test/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);
    $user = User::factory()->create(['user_type_id' => 1]);

    $this->actingAs($user, 'sanctum');
    $response = amariAsk(['stream' => true]);
    expect($response->streamedContent())->toContain('Hello');

    $log = AmariUsageLog::sole();
    expect([$log->status, $log->input_tokens, $log->output_tokens, $log->tokens_estimated])->toBe(['answered', 900, 45, false]);
    Http::assertSent(fn ($r) => ($r['stream_options']['include_usage'] ?? false) === true);
});

it('estimates the tokens when the provider sends no count', function () {
    Http::fake(['amari.test/*' => Http::response(['choices' => [['message' => ['content' => str_repeat('a', 400)]]]])]);
    $this->actingAs(User::factory()->create(['user_type_id' => 1]), 'sanctum');

    amariAsk()->assertOk();

    $log = AmariUsageLog::sole();
    expect($log->tokens_estimated)->toBeTrue()->and($log->output_tokens)->toBe(100)->and($log->input_tokens)->toBeGreaterThan(0);
});

it('records a landing-page visitor by a hash of the browser\'s guest id, the same one each time, never the raw id or IP', function () {
    fakeAmari();

    amariAsk(['profile' => 'public'], ['X-Amari-Guest' => 'guest-abc-12345'])->assertOk();
    amariAsk(['profile' => 'public'], ['X-Amari-Guest' => 'guest-abc-12345'])->assertOk();
    amariAsk(['profile' => 'public'], ['X-Amari-Guest' => 'someone-else-999'])->assertOk();

    $logs = AmariUsageLog::orderBy('id')->get();
    expect($logs)->toHaveCount(3)
        ->and($logs->pluck('user_id')->filter())->toBeEmpty()
        ->and($logs->pluck('profile')->unique()->all())->toBe(['public'])
        ->and($logs[0]->guest_key)->toBe($logs[1]->guest_key)
        ->and($logs[2]->guest_key)->not->toBe($logs[0]->guest_key)
        ->and($logs[0]->guest_key)->not->toContain('guest-abc')
        ->and($logs[0]->guest_key)->not->toContain('127.0.0.1');
});

it('refuses a visitor past the daily limit without calling the AI, records the refusal, and counts only requests that reached the model', function () {
    fakeAmari();
    AmariUsage::saveSettings(300, 2, 80);
    $visitor = ['X-Amari-Guest' => 'guest-limit-0001'];

    amariAsk(['profile' => 'public'], $visitor)->assertOk();
    amariAsk(['profile' => 'public'], $visitor)->assertOk();
    $refused = amariAsk(['profile' => 'public'], $visitor)->assertStatus(429)->assertJsonPath('error.code', 'daily_limit')->assertJsonPath('limit', 2);
    expect($refused->json('resets_at'))->toBe(AmariUsage::startOfToday()->addDay()->toIso8601String());
    amariAsk(['profile' => 'public'], $visitor)->assertStatus(429); // a refusal does not count as use, and is not re-sent

    expect(AmariUsageLog::where('status', 'limited')->count())->toBe(2)
        ->and(AmariUsage::usedToday(null, AmariUsageLog::first()->guest_key))->toBe(2);
    Http::assertSentCount(3); // one prompt check (the same question is checked once) + two answers; nothing for the refused ones

    // Another visitor still has their own allowance
    amariAsk(['profile' => 'public'], ['X-Amari-Guest' => 'guest-limit-0002'])->assertOk();
});

it('applies an account\'s own limit (0 = none) and pause over the default', function () {
    fakeAmari();
    AmariUsage::saveSettings(1, 20, 80);
    $user = User::factory()->create(['user_type_id' => 1]);
    $this->actingAs($user, 'sanctum');

    amariAsk()->assertOk();
    amariAsk()->assertStatus(429)->assertJsonPath('error.code', 'daily_limit');

    AmariUserLimit::create(['user_id' => $user->id, 'daily_limit' => 0]);
    amariAsk()->assertOk(); // own limit 0: no limit

    AmariUserLimit::where('user_id', $user->id)->update(['paused' => true]);
    amariAsk()->assertForbidden()->assertJsonPath('error.code', 'amari_paused');
    expect(AmariUsageLog::where('status', 'paused')->count())->toBe(1);
});

it('records a failed upstream answer as an error', function () {
    Http::fake(['amari.test/*' => Http::response(['error' => 'down'], 500)]);
    $this->actingAs(User::factory()->create(['user_type_id' => 1]), 'sanctum');

    amariAsk()->assertStatus(502);
    expect(AmariUsageLog::sole()->status)->toBe('error');
});

it('shows the admin who uses Amari: accounts, organizations, visitors and activity, with flags and CSV export', function () {
    fakeAmari();
    AmariUsage::saveSettings(2, 1, 50);
    $member = amariMember();
    $this->actingAs($member, 'sanctum');
    amariAsk()->assertOk();
    amariAsk()->assertOk();
    amariAsk()->assertStatus(429);
    $this->app['auth']->forgetGuards();
    amariAsk(['profile' => 'public'], ['X-Amari-Guest' => 'guest-admin-0001'])->assertOk();
    $guestKey = AmariUsageLog::whereNull('user_id')->value('guest_key');

    $admin = amariAdmin();
    $this->actingAs($admin, 'admin')->get(route('admin.amari.index'))->assertOk()
        ->assertSee('Wanjiru Kamau')->assertSee('Impala Fund')->assertSee('Reached the limit today')
        ->assertSee('3 requests', false); // 2 answers + 1 visitor today
    $this->get(route('admin.amari.index', ['tab' => 'organizations']))->assertOk()->assertSee('Impala Fund');
    $this->get(route('admin.amari.index', ['tab' => 'guests']))->assertOk()->assertSee('Visitor '.substr($guestKey, 0, 8));
    $this->get(route('admin.amari.index', ['tab' => 'activity', 'status' => 'limited']))->assertOk()->assertSee('Refused: limit reached');
    $this->get(route('admin.amari.index', ['tab' => 'users', 'flag' => 'limit_hit']))->assertOk()->assertSee('Wanjiru Kamau');
    $this->get(route('admin.amari.index', ['tab' => 'users', 'flag' => 'paused']))->assertOk()->assertDontSee('Wanjiru Kamau');
    $this->get(route('admin.amari.index', ['tab' => 'users', 'org' => $member->organization_id]))->assertOk()->assertSee('Showing accounts in');

    $csv = $this->get(route('admin.amari.index', ['tab' => 'users', 'export' => 'csv']))->assertOk()->streamedContent();
    expect($csv)->toContain('Wanjiru Kamau')->toContain('Impala Fund')->toContain('Used today');
    $activity = $this->get(route('admin.amari.index', ['tab' => 'activity', 'export' => 'csv']))->streamedContent();
    expect(substr_count(trim($activity), "\n"))->toBe(4); // header + 4 requests

    $this->get(route('admin.amari.user', $member))->assertOk()->assertSee('Limit for this account')->assertSee('Refused: limit reached');
});

it('lets the admin save the default limits and one account\'s own limit, pause and note, and go back to the default', function () {
    $user = User::factory()->create(['user_type_id' => 3]);
    $admin = amariAdmin();
    $this->actingAs($admin, 'admin');

    $this->post(route('admin.amari.settings'), ['user_limit' => 150, 'guest_limit' => 5, 'near_percent' => 90])->assertRedirect()->assertSessionHasNoErrors();
    expect(AmariUsage::settings())->toBe(['user_limit' => 150, 'guest_limit' => 5, 'near_percent' => 90]);
    $this->post(route('admin.amari.settings'), ['user_limit' => -1, 'guest_limit' => 5, 'near_percent' => 90])->assertSessionHasErrors('user_limit');

    $this->post(route('admin.amari.user.limit', $user), ['limit_mode' => 'custom', 'daily_limit' => 40, 'paused' => 1, 'note' => 'Heavy use'])->assertRedirect()->assertSessionHasNoErrors();
    $limit = AmariUserLimit::where('user_id', $user->id)->sole();
    expect([$limit->daily_limit, $limit->paused, $limit->note, $limit->updated_by_admin_id])->toBe([40, true, 'Heavy use', $admin->id])
        ->and(AmariUsage::limitFor($user))->toBe(['limit' => 40, 'paused' => true, 'custom' => true]);

    $this->post(route('admin.amari.user.limit', $user), ['limit_mode' => 'custom'])->assertSessionHasErrors('daily_limit');
    $this->post(route('admin.amari.user.limit', $user), ['limit_mode' => 'default', 'paused' => 0])->assertSessionHasNoErrors();
    expect(AmariUserLimit::where('user_id', $user->id)->exists())->toBeFalse()
        ->and(AmariUsage::limitFor($user))->toBe(['limit' => 150, 'paused' => false, 'custom' => false]);
});

it('keeps the Amari usage pages for signed-in admins only', function () {
    $this->get(route('admin.amari.index'))->assertRedirect();
    $this->post(route('admin.amari.settings'), ['user_limit' => 0, 'guest_limit' => 0, 'near_percent' => 80])->assertRedirect();
    expect(AmariUsage::settings()['user_limit'])->toBe(AmariUsage::DEFAULT_USER_LIMIT);

    $this->actingAs(User::factory()->create(['user_type_id' => 4]), 'sanctum')
        ->get(route('admin.amari.index'))->assertRedirect();
});
