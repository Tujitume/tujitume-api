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
