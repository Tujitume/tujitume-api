<?php

namespace App\Models\Grants;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MilestoneAgreementComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'agreement_id',
        'user_id',
        'role',
        'comment',
        'action',
        'rejection_reason',
    ];

    // ─── Relationships ───────────────────────────────────────────────────

    public function agreement()
    {
        return $this->belongsTo(MilestonePreAgreement::class, 'agreement_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

}
