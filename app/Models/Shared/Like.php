<?php

namespace App\Models\Shared;

use App\Models\Business\Listing;
use App\Models\Services\Services;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Like extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function listing(){
        return $this->belongsTo(Listing::class,'listing_id','id');
    }

    public function service(){
        return $this->belongsTo(Services::class,'listing_id','id');
    }
}
