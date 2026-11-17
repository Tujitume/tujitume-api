<?php

namespace App\Models\Programs;

use Illuminate\Database\Eloquent\Model;

class ProgramCustomField extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_required' => 'boolean', 'is_scoring' => 'boolean', 'is_bonus' => 'boolean', 'is_public' => 'boolean', 'is_archived' => 'boolean',
        'options' => 'array', 'validation' => 'array', 'scoring' => 'array', 'scoring_suggestion' => 'array',
    ];

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function values()
    {
        return $this->hasMany(ProgramCustomFieldValue::class, 'field_id');
    }

    /** What an applicant is allowed to see: never the scoring, weights or targets. */
    public function publicView(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'help_text' => $this->help_text, 'type' => $this->type, 'options' => $this->options,
            'is_required' => $this->is_required, 'validation' => $this->validation, 'display_order' => $this->display_order];
    }
}
