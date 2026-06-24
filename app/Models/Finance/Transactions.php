<?php

namespace App\Models\Finance;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transactions extends Model
{
    use HasFactory;
    use HasUlids;
    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->select(['id', 'fname', 'lname']);
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id')->select(['id', 'fname', 'lname']);
    }
}
