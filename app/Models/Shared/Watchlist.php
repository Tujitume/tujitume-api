<?php

namespace App\Models\Shared;

use App\Models\Business\Listing;
use App\Models\Capital\CapitalOffer;
use App\Models\Grants\Grant;
use App\Models\Services\Services;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Watchlist extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function grant()
    {
        return $this->belongsTo(Grant::class, 'org_id', 'id');
    }

    public function capital()
    {
        return $this->belongsTo(CapitalOffer::class, 'org_id', 'id');
    }

    public function listing()
    {
        return $this->belongsTo(Listing::class, 'org_id', 'id');
    }

    public function service()
    {
        return $this->belongsTo(Services::class, 'org_id', 'id');
    }
}
