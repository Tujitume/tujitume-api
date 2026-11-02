<?php

namespace App\Models\Programs;

use Illuminate\Database\Eloquent\Model;

class ProgramMetadata extends Model
{
    protected $table = 'program_metadata';

    protected $guarded = [];

    protected $casts = [
        'value' => 'json',
    ];
}
