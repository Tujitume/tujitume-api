<?php

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    use HasFactory;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id','user_id','user_device_id','ip','user_agent','last_activity'];
    protected $casts = ['last_activity' => 'integer'];

    public function user(){ return $this->belongsTo(User::class); }
    public function device(){ return $this->belongsTo(UserDevice::class,'user_device_id'); }
}
