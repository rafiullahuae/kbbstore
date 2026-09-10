<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return ['variant_attributes' => 'array', 'quantity' => 'int', 'unit_price' => 'int', 'subtotal' => 'int', 'total' => 'int'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

}
