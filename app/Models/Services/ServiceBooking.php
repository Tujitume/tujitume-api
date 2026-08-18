<?php

namespace App\Models\Services;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceBooking extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function service()
    {
        return $this->belongsTo(Services::class, 'service_id', 'id');
    }
    public function customer()
    {
        return $this->belongsTo(User::class, 'booker_id', 'id');
    }

    public function milestoneInstances()
    {
        return $this->hasMany(ServiceBookingMilestone::class, 'booking_id', 'id');
    }
}
