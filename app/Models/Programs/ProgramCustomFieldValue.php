<?php

namespace App\Models\Programs;

use Illuminate\Database\Eloquent\Model;

class ProgramCustomFieldValue extends Model
{
    protected $guarded = [];

    protected $casts = ['value_bool' => 'boolean', 'value_json' => 'array', 'value_number' => 'float'];

    public function field()
    {
        return $this->belongsTo(ProgramCustomField::class, 'field_id');
    }

    /** The stored value in the field's own type. */
    public function typed(string $type): mixed
    {
        return match ($type) {
            'number' => $this->value_number,
            'boolean' => $this->value_bool,
            'date' => $this->value_date ? \Illuminate\Support\Carbon::parse($this->value_date)->toDateString() : null,
            'multi_select' => $this->value_json ?? [],
            default => $this->value_text,
        };
    }
}
