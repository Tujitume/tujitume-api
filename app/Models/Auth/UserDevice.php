<?php

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserDevice extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id','device_uuid','name','platform','browser','ip','location','is_verified','verified_at','last_seen_at'
    ];

    protected $casts = [
        'is_verified' => 'bool',
        'verified_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function user(){ return $this->belongsTo(User::class); }
    public function verifications(){ return $this->hasMany(DeviceVerification::class); }
    public function sessions(){ return $this->hasMany(UserSession::class); }
}
