<?php

namespace App\Models\Services;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceBookingMilestone extends Model
{
    use HasFactory;
    protected $guarded = [];

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
        return $this->belongsTo(serviceBook::class,'booking_id','id');
    }
}
