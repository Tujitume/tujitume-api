<?php

namespace App\Models\Programs;

use Illuminate\Database\Eloquent\Model;

class ProgramCustomFieldValue extends Model
{
    protected $guarded = [];

    protected $casts = [
        'value_json' => 'array',
        'value_bool' => 'boolean',
        'value_number' => 'decimal:4',
    ];

    public function field()
    {
        return $this->belongsTo(ProgramCustomField::class, 'field_id');
    }
}
