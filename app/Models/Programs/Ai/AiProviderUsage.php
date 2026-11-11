<?php

namespace App\Models\Programs\Ai;

use Illuminate\Database\Eloquent\Model;

class AiProviderUsage extends Model
{
    protected $table = 'ai_provider_usage';
    protected $guarded = [];
    protected $casts = ['date' => 'date'];
}
