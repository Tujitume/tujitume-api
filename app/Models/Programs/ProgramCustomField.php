<?php

namespace App\Models\Programs;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramCustomField extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
        'validation' => 'array',
        'scoring' => 'array',
        'is_required' => 'boolean',
        'is_scoring' => 'boolean',
        'is_bonus' => 'boolean',
        'is_archived' => 'boolean',
        'display_order' => 'integer',
    ];

    public function program()
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function values()
    {
        return $this->hasMany(ProgramCustomFieldValue::class, 'field_id');
    }
}
