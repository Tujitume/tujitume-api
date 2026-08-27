<?php

namespace App\Models\Programs\Rounds;

use App\Models\Auth\User;
use App\Models\Programs\ProgramApplication;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationScore extends Model
{
    use HasFactory;
    protected static function newFactory() { return \Database\Factories\ApplicationScoreFactory::new(); }

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
        return $this->belongsTo(ProgramApplication::class);
    }

    public function round()
    {
        return $this->belongsTo(ProgramRound::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
