<?php

namespace App\Models\Programs;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramWatchlist extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function pitch()
    {
        return $this->belongsTo(ProgramApplication::class, 'pitch_id', 'id');
    }
}
