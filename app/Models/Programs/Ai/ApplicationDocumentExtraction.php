<?php

namespace App\Models\Programs\Ai;

use Illuminate\Database\Eloquent\Model;

class ApplicationDocumentExtraction extends Model
{
    protected $guarded = [];

    protected $casts = ['pages' => 'array', 'digest' => 'array', 'expires_at' => 'datetime'];

    // Extracted text is private: it is never part of an API response.
    protected $hidden = ['text_content'];
}
