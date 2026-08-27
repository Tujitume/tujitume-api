<?php

namespace App\Models\Programs;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramWallet extends Model
{
    use HasFactory;
    protected static function newFactory() { return \Database\Factories\ProgramWalletFactory::new(); }
    protected $fillable = [
        'program_id',
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

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function application()
    {
        return $this->belongsTo(ProgramApplication::class, 'application_id');
    }

    public function disbursements()
    {
        return $this->hasMany(Disbursement::class, 'wallet_id');
    }
}
