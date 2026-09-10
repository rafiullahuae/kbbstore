<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'bool', 'cost' => 'int', 'min_amount' => 'int', 'settings' => 'array'];
    }

    public function zone()
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    public function isFree(): bool
    {
        return $this->type === 'free_shipping';
    }

}
