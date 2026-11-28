<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A one-time link to the program-provider sign-up page (/auth/create/program?invite=…). Program-provider accounts
 * can only be created through one: the page is not open to the public. Each link is for one email address,
 * works until it is used (or expires / is revoked), and only the token's hash is stored.
 */
class ProgramRegistrationInvite extends Model
{
    public const VALID_DAYS = 14;

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'workspace_ready_sent_at' => 'datetime',
    ];

    /** Creates an invite and returns [invite, plain token]. Earlier unused invites for the same email are revoked. */
    public static function issue(string $email, ?string $name = null, ?int $demoRequestId = null, ?int $adminId = null): array
    {
        $email = strtolower(trim($email));
        static::query()->whereRaw('LOWER(email) = ?', [$email])->whereNull('used_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $token = Str::random(64);
        $invite = static::create([
            'email' => $email,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::VALID_DAYS),
            'demo_request_id' => $demoRequestId,
            'created_by_admin_id' => $adminId,
        ]);

        return [$invite, $token];
    }

    /** The invite a plain token belongs to, when it can still be used. */
    public static function findUsable(?string $token): ?self
    {
        if (! is_string($token) || strlen($token) !== 64) return null;

        $invite = static::where('token_hash', hash('sha256', $token))->first();

        return $invite && $invite->isUsable() ? $invite : null;
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function state(): string
    {
        return match (true) {
            $this->used_at !== null => 'used',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'open',
        };
    }

    /** Link to the sign-up page on the main site. */
    public static function urlFor(string $token): string
    {
        return rtrim((string) config('app.app_url'), '/').'/auth/create/program?invite='.$token;
    }

    public function demoRequest(): BelongsTo
    {
        return $this->belongsTo(DemoRequest::class);
    }
}
