<?php

namespace App\Models\Programs\Rounds;

use App\Service\Program\RoundMatchScore;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'file_category',
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

    /**
     * The categories a file-upload question accepts (document, photo, video, beneficiary_list, other; photo_video is the
     * old combined one), as a list. Questions saved before the column existed keep them in `options`, so those are read
     * from there while the column is empty. Null for every other question type.
     */
    protected function fileCategory(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $stored = is_string($value) ? json_decode($value, true) : $value;
                if (is_array($stored) && $stored) {
                    return array_values($stored);
                }
                if ($this->question_type !== 'file_upload') {
                    return null;
                }

                return RoundMatchScore::fileCategoriesIn((array) ($this->options ?? [])) ?: null;
            },
            set: fn ($value) => $value === null || $value === [] ? null : json_encode(array_values((array) $value)),
        );
    }

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
