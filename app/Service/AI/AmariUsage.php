<?php

namespace App\Service\AI;

use App\Models\Amari\AmariUsageLog;
use App\Models\Amari\AmariUserLimit;
use App\Models\Auth\User;
use App\Models\Misc\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Amari usage: who asked (an account, or a landing-page visitor), the daily limits an admin sets in
 * Admin › Amari usage, and one record per request. Amari is free: nothing here is billed.
 *
 * Days are UTC days. A limit counts the requests that reached the AI model (answered, stopped or failed); refused
 * requests (limit reached, paused, prompt check) are recorded but do not count.
 */
class AmariUsage
{
    public const SETTING_USER_LIMIT = 'amari_daily_limit_user';
    public const SETTING_GUEST_LIMIT = 'amari_daily_limit_guest';
    public const SETTING_NEAR_PERCENT = 'amari_near_limit_percent';

    public const DEFAULT_USER_LIMIT = 300;
    public const DEFAULT_GUEST_LIMIT = 20;
    public const DEFAULT_NEAR_PERCENT = 80;

    /** What the browser may say a request is for; anything else is recorded as chat */
    public const FEATURES = [
        'chat' => 'Chat questions',
        'guide' => 'Step-by-step guide',
        'document_reading' => 'Reading uploaded documents',
        'match_score' => 'Match score',
        'auto_score' => 'Business Coach draft scores',
        'rubric' => 'Scoring rubric',
    ];

    /** The settings an admin sets: default limits (0 = no limit) and when an account is "near" its limit */
    public static function settings(): array
    {
        $values = Setting::whereIn('key', [self::SETTING_USER_LIMIT, self::SETTING_GUEST_LIMIT, self::SETTING_NEAR_PERCENT])->pluck('value', 'key');
        $int = fn (string $key, int $default) => is_numeric($values[$key] ?? null) ? max(0, (int) $values[$key]) : $default;

        return [
            'user_limit' => $int(self::SETTING_USER_LIMIT, self::DEFAULT_USER_LIMIT),
            'guest_limit' => $int(self::SETTING_GUEST_LIMIT, self::DEFAULT_GUEST_LIMIT),
            'near_percent' => min(100, max(1, $int(self::SETTING_NEAR_PERCENT, self::DEFAULT_NEAR_PERCENT))),
        ];
    }

    public static function saveSettings(int $userLimit, int $guestLimit, int $nearPercent): void
    {
        foreach ([self::SETTING_USER_LIMIT => $userLimit, self::SETTING_GUEST_LIMIT => $guestLimit, self::SETTING_NEAR_PERCENT => $nearPercent] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }
    }

    public static function startOfToday(): Carbon
    {
        return now()->utc()->startOfDay();
    }

    /**
     * A visitor's key: a keyed hash of the browser's own guest id (X-Amari-Guest), or of the IP address when it sends
     * none. Neither is stored as it is.
     */
    public static function guestKey(Request $request): string
    {
        $guest = (string) $request->header('X-Amari-Guest', '');
        $source = preg_match('/^[A-Za-z0-9-]{8,64}$/', $guest) ? 'g:'.$guest : 'ip:'.$request->ip();

        return substr(hash_hmac('sha256', $source, (string) config('app.key')), 0, 32);
    }

    public static function feature(mixed $value): string
    {
        return is_string($value) && array_key_exists($value, self::FEATURES) ? $value : 'chat';
    }

    /**
     * The limit that applies today: ['limit' => int (0 = none), 'paused' => bool, 'custom' => bool]
     */
    public static function limitFor(?User $user): array
    {
        $settings = self::settings();
        if (! $user) {
            return ['limit' => $settings['guest_limit'], 'paused' => false, 'custom' => false];
        }
        $override = AmariUserLimit::where('user_id', $user->id)->first();

        return [
            'limit' => $override?->daily_limit ?? $settings['user_limit'],
            'paused' => (bool) $override?->paused,
            'custom' => $override?->daily_limit !== null,
        ];
    }

    public static function usedToday(?User $user, ?string $guestKey): int
    {
        return AmariUsageLog::query()
            ->when($user, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->whereNull('user_id')->where('guest_key', $guestKey))
            ->whereIn('status', AmariUsageLog::COUNTED)
            ->where('created_at', '>=', self::startOfToday())
            ->count();
    }

    /**
     * Null when the request may go on; otherwise why not: ['status' => 'paused'|'limited', 'limit' => int].
     */
    public static function refusal(?User $user, ?string $guestKey): ?array
    {
        $limit = self::limitFor($user);
        if ($limit['paused']) {
            return ['status' => 'paused', 'limit' => $limit['limit']];
        }
        if ($limit['limit'] > 0 && self::usedToday($user, $guestKey) >= $limit['limit']) {
            return ['status' => 'limited', 'limit' => $limit['limit']];
        }

        return null;
    }

    /**
     * Records one request. Recording must never break Amari, so a failure here is only reported.
     *
     * @param  array{status: string, profile?: string, feature?: string, model?: ?string, input_tokens?: int, output_tokens?: int, tokens_estimated?: bool, duration_ms?: ?int}  $row
     */
    public static function record(?User $user, ?string $guestKey, array $row): void
    {
        try {
            $in = max(0, (int) ($row['input_tokens'] ?? 0));
            $out = max(0, (int) ($row['output_tokens'] ?? 0));
            AmariUsageLog::create([
                'user_id' => $user?->id,
                'organization_id' => $user?->organization_id,
                'user_type_id' => $user?->user_type_id,
                'guest_key' => $user ? null : $guestKey,
                'profile' => $row['profile'] ?? ($user ? 'portal' : 'public'),
                'feature' => self::feature($row['feature'] ?? null),
                'model' => isset($row['model']) ? substr((string) $row['model'], 0, 120) : null,
                'status' => in_array($row['status'], AmariUsageLog::STATUSES, true) ? $row['status'] : 'error',
                'input_tokens' => $in,
                'output_tokens' => $out,
                'total_tokens' => $in + $out,
                'tokens_estimated' => (bool) ($row['tokens_estimated'] ?? false),
                'duration_ms' => isset($row['duration_ms']) ? max(0, (int) $row['duration_ms']) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** A rough token count (about four characters a token) for when the provider sends no usage */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    /** The text of the messages sent, for the estimate */
    public static function messagesText(array $messages): string
    {
        return collect($messages)->map(fn ($m) => is_string($m['content'] ?? null) ? $m['content'] : json_encode($m['content'] ?? ''))->implode("\n");
    }
}
