<?php

namespace App\Models\Programs\Rounds;

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
        'use_in_match_score',
        'match_points',
        'match_answers',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'use_in_match_score' => 'boolean',
        'match_points' => 'integer',
        'match_answers' => 'array',
    ];

    public function round()
    {
        return $this->belongsTo(ProgramRound::class, 'round_id');
    }

    public function responses()
    {
        return $this->hasMany(ApplicationRoundResponse::class, 'question_id');
    }

    public function applicantView(): array
    {
        return array_diff_key($this->toArray(), array_flip(['match_answers', 'match_points', 'knockout_fail_value']));
    }
}
