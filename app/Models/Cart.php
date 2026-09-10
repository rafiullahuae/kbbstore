<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_activity_at' => 'datetime', 'converted_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(CartItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    public function itemCount(): int
    {
        return (int) $this->items->sum('quantity');
    }

}
