<?php

namespace App\Models\Services;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceBookingMilestone extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = ['delivery_files' => 'array', 'delivered_at' => 'datetime'];

    public function service()
    {
        return $this->belongsTo(Services::class,'service_id','id');
    }

    public function milestone()
    {
        return $this->belongsTo(Smilestones::class,'mile_id','id');
    }

    public function booking()
    {
        return $this->belongsTo(ServiceBooking::class,'booking_id','id');
    }
}
