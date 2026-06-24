<?php

namespace App\Models\Grants\Rounds;

use App\Models\Auth\User;
use App\Models\Grants\GrantApplication;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'round_id',
        'reviewer_id',
        'criterion_scores',
        'total_score',
        'overall_comment',
        'scored_at',
    ];

    protected $casts = [
        'criterion_scores' => 'array',
        'scored_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(GrantApplication::class);
    }

    public function round()
    {
        return $this->belongsTo(GrantRound::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
