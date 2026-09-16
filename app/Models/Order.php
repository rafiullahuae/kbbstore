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
            'captured_at' => 'datetime',
            'captured_total' => 'int',
            'completed_at' => 'datetime',
            'invoiced_at' => 'datetime',
            'deleted_at' => 'datetime',
            'subtotal' => 'int',
            'discount_total' => 'int',
            'shipping_total' => 'int',
            'fee_total' => 'int',
            'tax_total' => 'int',
            /*
             * NOT cast to float. Null is the load-bearing value here: it means
             * "this order predates the tax engine, or was placed while the shop
             * was only printing a VAT line" — and every reader branches on
             * that to keep behaving exactly as it did. `'float'` would turn a
             * null column into 0.0 and tell those readers the order was taxed
             * at nought per cent, which is a different and untrue statement.
             */
            'total' => 'int',
        ];
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * How the payment method should read on a page a customer sees.
     *
     * Orders placed from 2.60.117 onward snapshot the merchant's own wording
     * at checkout into payment_method_title, and that always wins — an order
     * must keep saying what it said on the day, even after the gateway is
     * renamed in Store → Payments.
     *
     * Every order placed BEFORE that has the column null, because nothing in
     * the app ever wrote it except the demo seeder. Those fall back to the
     * gateway's own title, which is why this is not just `?:` in the views:
     * without the lookup they printed the bare id and showed the customer
     * "cod". The humanised id is the last resort for a gateway this build no
     * longer carries code for.
     */
    public function paymentLabel(): string
    {
        $title = trim((string) $this->payment_method_title);

        if ($title !== '') {
            return $title;
        }

        $code = trim((string) $this->payment_method);

        if ($code === '') {
            return 'Not recorded';
        }

        $gateway = app(\App\Services\Payments\GatewayRegistry::class)->find($code);

        return $gateway !== null
            ? $gateway->title()
            : ucfirst(str_replace(['_', '-'], ' ', $code));
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
