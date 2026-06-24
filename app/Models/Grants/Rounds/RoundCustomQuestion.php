<?php

namespace App\Models\Grants\Rounds;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoundCustomQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'round_id',
        'question_text',
        'question_type',
        'options',
        'is_required',
        'display_order',
        'knockout_fail_value',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
    ];

    public function round()
    {
        return $this->belongsTo(GrantRound::class, 'round_id');
    }

    public function responses()
    {
        return $this->hasMany(ApplicationRoundResponse::class, 'question_id');
    }
}
