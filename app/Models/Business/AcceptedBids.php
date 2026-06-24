<?php

namespace App\Models\Business;

use App\Models\Auth\User;
use App\Models\Milestones\Milestones;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcceptedBids extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function listing()
    {
        return $this->belongsTo(Listing::class, 'business_id', 'id');
    }

    public function investor()
    {
        return $this->belongsTo(User::class, 'investor_id', 'id');
    }

    public function milestone()
    {
        return $this->belongsTo(Milestones::class, 'ms_id', 'id');
    }
}
