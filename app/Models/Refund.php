<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        // Fils, like every money column in this schema. Cast so a refund read
        // back out of SQLite (which hands integers back as strings often
        // enough) cannot be compared against an int and quietly differ.
        return ['amount' => 'int'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /** Refund rows that hold money: settled, or in flight and reserved. */
    public function scopeCounted($query)
    {
        return $query->whereIn('status', \App\Services\Payments\PaymentRefunder::COUNTED);
    }

}
