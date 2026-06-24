<?php

namespace App\Models\Capital;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CapitalWatchlist extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function pitch()
    {
        return $this->belongsTo(StartupPitches::class, 'pitch_id', 'id');
    }
}
