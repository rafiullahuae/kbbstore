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
        // The WooCommerce origin of this row, e.g. "user:412:billing" or
        // "order:10233:shipping", carrying a unique index so a re-run of the
        // address import updates the row it created last time instead of
        // filing a second copy of it. Fillable ON PURPOSE and unlike
        // customer_id: the importer matches with
        // updateOrCreate(['source_key' => ...], [...]), and Eloquent fills the
        // match attributes through the same guard as everything else, so a
        // guarded column here would silently produce an address with a null
        // key on every pass — which is exactly the duplication the column
        // exists to prevent. Nothing a shopper can submit reaches it: the
        // storefront's address book writes its own allowlist and leaves this
        // null, which is what an address with no WordPress origin should say.
        'source_key',
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
