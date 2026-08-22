<?php

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSetting extends Model
{
    protected $fillable = [
        'user_id',
        'theme',
        'mode',
        'logo',
        'accent_color',
        'bg_color',
        'font_weight',
        'subscription_status',
        'language',
        'currency',
        'timezone',
        'profile_visibility',
        'date_format',
        'supported_currencies',
        'supported_languages',
        'email_notifications',
        'push_notifications',
        'custom',
    ];

    protected $casts = [
        'email_notifications' => 'boolean',
        'push_notifications'  => 'boolean',
        'supported_currencies' => 'array',
        'supported_languages' => 'array',
        'custom'              => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
