<?php

namespace App\Models\Services;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;

/** A service provider's request to work with a business, a program or a capital offer, sharing one of their services. */
class ServiceProposal extends Model
{
    public const TARGETS = ['listing', 'program', 'capital_offer'];
    public const STATUSES = ['pending', 'accepted', 'declined', 'withdrawn'];

    protected $guarded = [];

    protected $casts = [
        'proposed_price' => 'float',
        'decided_at' => 'datetime',
    ];

    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function service()
    {
        return $this->belongsTo(Services::class, 'service_id');
    }
}
