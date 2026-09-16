<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponRedemption extends Model
{

    protected $guarded = [];

    /**
     * `released_at` is the fact that this use has been handed back — see the
     * migration that adds it and CouponService::releaseRedemptions(). Cast so
     * a released row reads as a date rather than a string, and so `whereNull`
     * and `$row->released_at === null` agree about what NULL means.
     */
    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

}
