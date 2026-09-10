<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingZone extends Model
{

    protected $guarded = [];

    public function locations()
    {
        return $this->hasMany(ShippingZoneLocation::class);
    }

    public function methods()
    {
        return $this->hasMany(ShippingMethod::class)->where('enabled', true)->orderBy('position');
    }

}
