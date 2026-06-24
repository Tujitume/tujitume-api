<?php

namespace App\Models\Auth;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeviceVerification extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','user_device_id','code','expires_at','attempts'];
    protected $casts = ['expires_at' => 'datetime'];

//    public function isExpired()
//    {
//        return $this->created_at->lt(now()->subMinutes(10));
//    }

    public function isExpired(): bool { return Carbon::now()->greaterThan($this->expires_at); }
    public function device(){ return $this->belongsTo(UserDevice::class,'user_device_id'); }
}
