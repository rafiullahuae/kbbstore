<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryCountry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            'charge' => 'int',
            'free_from' => 'int',
            'position' => 'int',
        ];
    }
}
