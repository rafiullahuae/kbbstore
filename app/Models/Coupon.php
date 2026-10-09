<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{

    protected $guarded = [];

    /**
     * Appearance → Cart panel advertises one coupon from a snapshot of it, so
     * the panel on every shop page never queries this table. Any save or
     * delete of THAT coupon re-takes the snapshot; any other coupon costs one
     * memoised settings lookup and nothing else. (Lane QK3)
     */
    protected static function booted(): void
    {
        static::saved(fn (self $coupon) => \App\Services\CartPanel::couponChanged((int) $coupon->getKey()));
        static::deleted(fn (self $coupon) => \App\Services\CartPanel::couponChanged((int) $coupon->getKey()));
    }

    protected function casts(): array
    {
        return [
            'product_ids' => 'array',
            'excluded_product_ids' => 'array',
            'category_ids' => 'array',
            'excluded_category_ids' => 'array',
            // Same shape as the four above: a JSON list of ids, null for "no
            // restriction". Read by CouponService::eligibleItems() against
            // products.brand_id.
            'brand_ids' => 'array',
            'excluded_brand_ids' => 'array',
            'allowed_emails' => 'array',
            'free_shipping' => 'bool',
            'individual_use' => 'bool',
            'exclude_sale_items' => 'bool',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'amount' => 'int',
            'usage_count' => 'int',
            // NULL is "no cap" and must stay distinguishable from 0, which is
            // "discount nothing". Laravel's integer cast leaves null alone, so
            // the `=== null` test in CouponService::cappedLines() keeps meaning
            // what it reads on both engines.
            'limit_usage_to_x_items' => 'int',
        ];
    }

    public function redemptions()
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function scopeCode($query, string $code)
    {
        return $query->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))]);
    }

}
