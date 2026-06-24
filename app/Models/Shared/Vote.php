<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vote extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'stats_at' => 'datetime',
        'ends_at' => 'datetime',
    ];
}
