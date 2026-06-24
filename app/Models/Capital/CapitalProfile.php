<?php

namespace App\Models\Capital;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CapitalProfile extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'regions' => 'array',
        'startup_stage' => 'array',
        'eng_prefer' => 'array',
    ];

    public function user(){
        return $this->belongsTo(User::class,'user_id', 'id');
    }

    public function role(){
        return $this->belongsTo(Role::class,'role_id', 'id');
    }
}
