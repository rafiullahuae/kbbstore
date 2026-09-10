<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponRedemption extends Model
{

    protected $guarded = [];

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

}
