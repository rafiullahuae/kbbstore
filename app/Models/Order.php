<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'whatsapp_optin' => 'bool',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
            'invoiced_at' => 'datetime',
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
