<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'product_ids' => 'array',
            'excluded_product_ids' => 'array',
            'category_ids' => 'array',
            'excluded_category_ids' => 'array',
            'allowed_emails' => 'array',
            'free_shipping' => 'bool',
            'individual_use' => 'bool',
            'exclude_sale_items' => 'bool',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'amount' => 'int',
            'usage_count' => 'int',
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
