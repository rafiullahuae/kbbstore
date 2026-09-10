<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'bool'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

}
