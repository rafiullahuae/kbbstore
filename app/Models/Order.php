<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    /**
     * Statuses that count as a real, completed order — mirrors the
     * storefront's own derived-value rule. Single source of truth: was
     * duplicated as a private constant on AdminController for revenue,
     * moved here so the Catalog → Reorder / Products order-count and any
     * future use read the exact same definition rather than risk drifting
     * out of sync with it.
     */
    public const REAL_STATUSES = ['processing', 'onhold', 'shipped', 'completed'];


    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'whatsapp_optin' => 'bool',
            'is_gift' => 'bool',
            'gift_fee' => 'int',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
            'invoiced_at' => 'datetime',
            'deleted_at' => 'datetime',
            'subtotal' => 'int',
            'discount_total' => 'int',
            'shipping_total' => 'int',
            'fee_total' => 'int',
            'tax_total' => 'int',
            'total' => 'int',
        ];
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function notes()
    {
        return $this->hasMany(OrderNote::class)->latest();
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

}
