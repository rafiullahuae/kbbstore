<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved address in a customer's address book.
 *
 * `customer_id` is deliberately NOT fillable. Every write goes through
 * `$customer->addresses()`, which sets the owner from the relation, so the
 * column never needs to arrive from a form — and while AddressController
 * validates against its own allowlist today, a model that will happily
 * mass-assign `customer_id` is one careless `$request->all()` away from
 * letting a shopper file an address under somebody else's account. Listing the
 * columns costs nothing and removes that as a possibility.
 */
class Address extends Model
{
    protected $fillable = [
        'type',
        'is_default',
        'first_name',
        'last_name',
        'company',
        'line1',
        'line2',
        'city',
        'state',
        'postcode',
        'country',
        'phone',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'bool'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
