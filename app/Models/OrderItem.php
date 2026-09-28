<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        /*
         * `set_contents` (Lane SET) is the second JSON snapshot on this row and
         * it is here for the same reason as the first: a set's contents change,
         * and an order sold last month must still print what was in the box.
         * App\Support\SetContents is the only thing that writes it and the only
         * thing that reads it. NULL on every ordinary line.
         */
        return ['variant_attributes' => 'array', 'set_contents' => 'array', 'quantity' => 'int', 'unit_price' => 'int', 'subtotal' => 'int', 'total' => 'int'];
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
