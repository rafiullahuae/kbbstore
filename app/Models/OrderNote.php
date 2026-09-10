<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderNote extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_customer_note' => 'bool'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

}
