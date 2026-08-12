<?php

namespace App\Models\Services;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceOffer extends Model
{
    protected $fillable = [
        'service_id',
        'booker_id',
        'booking_id',
        'original_price',
        'offered_price',
        'discount_percent',
        'note',
        'status',
        'counter_price',
        'counter_note',
    ];

    protected $casts = [
        'original_price'   => 'float',
        'offered_price'    => 'float',
        'discount_percent' => 'float',
        'counter_price'    => 'float',
    ];

    public function service()
    {
        return $this->belongsTo(Services::class, 'service_id');
    }
    public function booker()
    {
        return $this->belongsTo(\App\Models\Auth\User::class, 'booker_id');
    }
    public function booking()
    {
        return $this->belongsTo(ServiceBook::class, 'booking_id');
    }
}