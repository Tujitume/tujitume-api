<?php

namespace App\Models\Programs\Rounds;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Programs\ProgramApplication;

class ReviewerApplicationAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'round_id',
        'reviewer_id',
        'reviewer_order_id',
        'application_id',
        'status',
        'assigned_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'assigned_at'  => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $assignment) {
            if (!$assignment->reviewer_order_id) {
                $assignment->reviewer_order_id = \App\Models\ReviewerOrder::where('round_id', $assignment->round_id)
                    ->where('reviewer_id', $assignment->reviewer_id)
                    ->value('id');
            }
        });

        static::created(function (self $assignment) {
            $assignment->reviewerOrder?->refreshRoundReviewFee();
        });

        static::deleted(function (self $assignment) {
            $assignment->reviewerOrder?->refreshRoundReviewFee();
        });
    }

    public function round()
    {
        return $this->belongsTo(ProgramRound::class, 'round_id');
    }
    public function reviewer()
    {
        return $this->belongsTo(\App\Models\Auth\User::class, 'reviewer_id');
    }
    public function application()
    {
        return $this->belongsTo(ProgramApplication::class, 'application_id');
    }

    public function reviewerOrder()
    {
        return $this->belongsTo(\App\Models\ReviewerOrder::class, 'reviewer_order_id');
    }
}
