<?php

namespace App\Models\Grants;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GrantWatchlist extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function pitch()
    {
        return $this->belongsTo(GrantApplication::class, 'pitch_id', 'id');
    }
}
