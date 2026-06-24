<?php

namespace App\Models\Grants;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GrantWallet extends Model
{
    use HasFactory;
    protected $fillable = [
        'grant_id',
        'application_id',
        'total_deposited',
        'total_disbursed',
        'total_reserved',
        'balance',
        'currency',
        'status',
        'notes',
    ];

    protected $casts = [
        'total_deposited' => 'decimal:2',
        'total_disbursed' => 'decimal:2',
        'total_reserved' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    // ─────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────

    public function grant()
    {
        return $this->belongsTo(Grant::class);
    }

    public function application()
    {
        return $this->belongsTo(GrantApplication::class, 'application_id');
    }

    public function disbursements()
    {
        return $this->hasMany(Disbursement::class, 'wallet_id');
    }
}
