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
        'accent_color',
        'bg_color',
        'font_weight',
        'language',
        'currency',
        'timezone',
        'profile_visibility',
        'email_notifications',
        'push_notifications',
        'marketing_emails',
        'custom',
    ];

    protected $casts = [
        'email_notifications' => 'boolean',
        'push_notifications'  => 'boolean',
        'marketing_emails'    => 'boolean',
        'custom'              => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
