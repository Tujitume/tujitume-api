<?php

namespace App\Models\Amari;

use Illuminate\Database\Eloquent\Model;

/** An admin's override of one account's daily Amari limit, or a pause */
class AmariUserLimit extends Model
{
    protected $guarded = [];

    protected $casts = ['paused' => 'boolean', 'daily_limit' => 'integer'];
}
