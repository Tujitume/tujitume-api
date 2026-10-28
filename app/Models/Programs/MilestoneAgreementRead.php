<?php

namespace App\Models\Programs;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;

class MilestoneAgreementRead extends Model
{
    protected $fillable = ['agreement_id', 'user_id', 'last_read_comment_id', 'read_at'];

    protected $casts = [
        'last_read_comment_id' => 'integer',
        'read_at' => 'datetime',
    ];

    public function agreement()
    {
        return $this->belongsTo(MilestonePreAgreement::class, 'agreement_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
