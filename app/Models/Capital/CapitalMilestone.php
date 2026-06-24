<?php

namespace App\Models\Capital;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CapitalMilestone extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function capital()
    {
        return $this->belongsTo(CapitalOffer::class, 'capital_id');
    }
    public function application()
    {
        return $this->belongsTo(StartupPitches::class, 'capital_id');
    }
}
