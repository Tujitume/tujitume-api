<?php

namespace App\Modules\ProgramImport\Support;

use App\Modules\ProgramImport\Models\ImportToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Facades\Socialite;

/**
 * Phase C: optional read-only Google connection (Forms API for forms that need a sign-in). Behind PROGRAM_IMPORTS_GOOGLE_OAUTH and the
 * platform's Google client credentials; tokens are stored encrypted per organization and can be removed at any time.
 */
class GoogleConnection
{
    public const SCOPES = ['https://www.googleapis.com/auth/forms.body.readonly', 'https://www.googleapis.com/auth/spreadsheets.readonly'];

    public static function configured(): bool
    {
        return (bool) config('program_imports.google.oauth_enabled') && config('services.google.client_id') && config('services.google.client_secret');
    }

    private function driver()
    {
        return Socialite::driver('google')->stateless()->redirectUrl(url('/api/v1/program-imports/integrations/google/callback'))
            ->scopes(self::SCOPES)->with(['access_type' => 'offline', 'prompt' => 'consent']);
    }

    /** A signed, expiring state so the callback (which has no login) knows who started the connection. */
    public function authUrl(int $organizationId, int $userId): string
    {
        $state = encrypt(['o' => $organizationId, 'u' => $userId, 'exp' => now()->addMinutes(10)->timestamp]);

        return $this->driver()->with(['state' => $state, 'access_type' => 'offline', 'prompt' => 'consent'])->redirect()->getTargetUrl();
    }

    public function complete(string $state): ImportToken
    {
        try {
            $data = decrypt($state);
        } catch (\Throwable) {
            throw new FormFetchException('google_state');
        }
        if (($data['exp'] ?? 0) < now()->timestamp) {
            throw new FormFetchException('google_state');
        }
        $user = $this->driver()->user();

        return ImportToken::updateOrCreate(
            ['organization_id' => $data['o'], 'provider' => 'google'],
            ['user_id' => $data['u'], 'access_token' => $user->token, 'refresh_token' => $user->refreshToken ?: ImportToken::where('organization_id', $data['o'])->first()?->refresh_token,
                'expires_at' => $user->expiresIn ? now()->addSeconds((int) $user->expiresIn) : null, 'scopes' => self::SCOPES],
        );
    }

    public function token(int $organizationId): ?string
    {
        $t = ImportToken::where('organization_id', $organizationId)->where('provider', 'google')->first();
        if (! $t) {
            return null;
        }
        if ($t->expires_at && $t->expires_at->isPast() && $t->refresh_token) {
            $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $t->refresh_token, 'grant_type' => 'refresh_token',
            ]);
            if (! $res->successful()) {
                return null;
            }
            $t->update(['access_token' => $res->json('access_token'), 'expires_at' => now()->addSeconds((int) $res->json('expires_in', 3600))]);
        }

        return $t->access_token;
    }

    public function disconnect(int $organizationId): void
    {
        ImportToken::where('organization_id', $organizationId)->where('provider', 'google')->delete();
    }

    /** Read a form through the Forms API with the organization's own access. Same spec shape as the public parser. */
    public function fetchForm(int $organizationId, string $urlOrId): array
    {
        $token = $this->token($organizationId) ?? throw new FormFetchException('google_not_connected');
        if (! preg_match('#/forms/d/(?:e/)?([A-Za-z0-9_-]{10,})#', $urlOrId, $m) && ! preg_match('/^[A-Za-z0-9_-]{10,}$/', $urlOrId)) {
            throw new FormFetchException('form_url');
        }
        $id = $m[1] ?? $urlOrId;
        $res = Http::withToken($token)->timeout((int) config('program_imports.google.timeout'))->get('https://forms.googleapis.com/v1/forms/'.$id);
        if (! $res->successful()) {
            throw new FormFetchException($res->status() === 403 || $res->status() === 404 ? 'form_private' : 'form_unreachable');
        }
        $questions = [];
        foreach ($res->json('items', []) as $item) {
            $q = $item['questionItem']['question'] ?? null;
            $title = trim((string) ($item['title'] ?? ''));
            if (! $q || $title === '' || count($questions) >= 200) {
                continue;
            }
            $type = 'text';
            $options = [];
            if (isset($q['choiceQuestion'])) {
                $type = ($q['choiceQuestion']['type'] ?? '') === 'CHECKBOX' ? 'checkbox' : 'choice';
                $options = array_values(array_filter(array_map(fn ($o) => trim((string) ($o['value'] ?? '')), $q['choiceQuestion']['options'] ?? [])));
            } elseif (isset($q['textQuestion'])) {
                $type = ! empty($q['textQuestion']['paragraph']) ? 'paragraph' : 'text';
            } elseif (isset($q['scaleQuestion'])) {
                $type = 'scale';
            } elseif (isset($q['dateQuestion'])) {
                $type = 'date';
            }
            $questions[] = ['label' => mb_substr($title, 0, 500), 'help_text' => trim((string) ($item['description'] ?? '')) ?: null, 'type' => $type, 'required' => ! empty($q['required']),
                'options' => $options, 'maps_to' => null, 'confidence' => 1.0, 'suggested' => true];
        }
        if (! $questions) {
            throw new FormFetchException('form_empty');
        }

        return ['title' => $res->json('info.title'), 'description' => $res->json('info.description'), 'questions' => $questions];
    }
}
