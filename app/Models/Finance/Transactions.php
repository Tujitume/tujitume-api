<?php

namespace App\Models\Finance;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transactions extends Model
{
    use HasFactory;
    use HasUlids;
    protected $guarded = [];

    protected $appends = ['currency'];

    /** Types that are always organization money when an organization person makes them (paid from its wallets). */
    public const ORGANIZATION_TYPES = ['transfer_to_program_wallet', 'program_milestone', 'program_milestone_bulk', 'reviewer_payment_sent'];

    /** Methods of organization wallet money: a deposit into it, and the organization side of a withdrawal from it. */
    public const ORGANIZATION_METHODS = ['org-wallet'];

    protected static function booted(): void
    {
        static::creating(function (Transactions $t) {
            if ($t->organization_id === null) $t->organization_id = self::organizationOf($t->getAttributes());
        });
    }

    /**
     * The organization whose wallet a row belongs to, or null for a person's own money: an organization person's
     * program payouts, moves to program wallets, coach payments and program audit fees, organization wallet deposits,
     * and the organization side (debit) of a withdrawal to someone's My Wallet.
     */
    public static function organizationOf(array $row): ?int
    {
        $type = $row['type'] ?? null;
        $method = $row['method'] ?? null;
        $debit = ($row['direction'] ?? null) === 'debit';
        $isOrganizationMoney = in_array($type, self::ORGANIZATION_TYPES, true)
            || in_array($method, self::ORGANIZATION_METHODS, true)
            || ($type === 'pm_audit_fee' && $debit)
            || ($method === \App\Service\Program\OrganizationWithdrawal::METHOD && $debit);
        if (! $isOrganizationMoney || empty($row['user_id'])) return null;

        $user = \Illuminate\Support\Facades\DB::table('users')->where('id', $row['user_id'])->first(['id', 'user_type_id', 'organization_id']);
        if (! $user || (int) $user->user_type_id !== 4) return null;

        return $user->organization_id
            ? (int) $user->organization_id
            : ((int) \Illuminate\Support\Facades\DB::table('organizations')->where('owner_user_id', $user->id)->value('id') ?: null);
    }

    /**
     * The currency of this row's amounts. The ledger has no currency column: M-Pesa (lipr) payments are recorded in KES and
     * Stripe payments in USD, so the payment method says which. Anything else is the platform's local currency (KES).
     */
    public static function currencyForMethod(?string $method): string
    {
        return str_starts_with(strtolower((string) $method), 'stripe') ? 'USD' : 'KES';
    }

    public function getCurrencyAttribute(): string
    {
        return self::currencyForMethod($this->attributes['method'] ?? null);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->select(['id', 'first_name', 'last_name']);
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id')->select(['id', 'first_name', 'last_name']);
    }
}
