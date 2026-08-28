<?php

namespace App\Models\Users;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceProviderProfile extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected static function newFactory()
    {
        return \Database\Factories\ServiceProviderProfileFactory::new();
    }

    protected $casts = [
        'service_areas' => 'array',
        'available_days' => 'array',
    ];
}
