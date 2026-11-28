<?php

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSetting extends Model
{
    public const THEME_KEYS = [
        'default',
        'tujitume',
        'emerald',
        'blue',
        'ocean',
        'forest',
        'purple',
        'rose',
        'amber',
        'pink',
        'yellow',
        'orange',
        'sunset',
        'lime',
        'cyan',
        'inkpaper',
        'minimal',
        'custom',
    ];

    public const MODE_KEYS = ['light', 'dark', 'system'];

    public const DEFAULTS = [
        'theme' => 'default',
        'mode' => 'system',
        'logo' => null,
        'accent_color' => '#16a34a',
        'bg_color' => null,
        'font_weight' => 'light',
        'subscription_status' => 'inactive',
        'language' => 'en',
        'currency' => 'KES', // Tujitume works in Kenyan shillings for now (the column's default too)
        'timezone' => 'Africa/Nairobi', // a new row takes the device's zone instead, see defaults()
        'profile_visibility' => 'public',
        'date_format' => 'DD/MM/YYYY',
        'supported_currencies' => null,
        'supported_languages' => null,
        'email_notifications' => true,
        'push_notifications' => true,
        'custom' => null,
    ];

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

    /** Settings for a new row: the person's device zone (X-Timezone) when the app sent one, never a blanket UTC */
    public static function defaults(): array
    {
        return array_merge(self::DEFAULTS, ['timezone' => \App\Support\ProgramSchedule::deviceZone()]);
    }

    public function toFrontendArray(): array
    {
        $data = array_merge(self::DEFAULTS, $this->attributesToArray());
        $hasCustomBrand = !empty($data['accent_color']) || !empty($data['custom']);
        if (empty($data['theme']) && $hasCustomBrand) {
            $data['theme'] = 'custom';
        } elseif (empty($data['theme'])) {
            $data['theme'] = self::DEFAULTS['theme'];
        }

        $data['id'] = $this->id;
        $data['user_id'] = $this->user_id;
        $data['email_notifications'] = (bool) $data['email_notifications'];
        $data['push_notifications'] = (bool) $data['push_notifications'];
        $data['created_at'] = $this->created_at?->toJSON();
        $data['updated_at'] = $this->updated_at?->toJSON();

        return $data;
    }
}
