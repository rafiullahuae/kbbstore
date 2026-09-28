<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One member of a Set. (Lane SET)
 *
 * The pivot between a `products` row whose `type` is 'set' and the `products`
 * row it contains, optionally narrowed to one `product_variants` row so a set
 * can name the 50ml rather than the product.
 *
 * ── WHAT THIS MODEL IS NOT ─────────────────────────────────────────────────
 *
 * It is not what an ORDER reads. An order reads `order_items.set_contents`, the
 * JSON snapshot App\Support\SetContents wrote on the day, because a set's
 * contents change and an order sold last month must still print what was in the
 * box. Nothing on an order-shaped surface -- the invoice, the confirmation
 * email, the customer's order page, the admin order screen -- may reach through
 * this pivot, and none of them does.
 *
 * ── MONEY ──────────────────────────────────────────────────────────────────
 *
 * There is none on this row, deliberately. A member's price is the member
 * product's own, read at the moment it is needed (for the display) or
 * snapshotted (for an order). A price column here would be a third answer to
 * what a product costs, drifting away from the two this shop already has.
 */
class ProductSetItem extends Model
{
    protected $table = 'product_set_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'int', 'position' => 'int'];
    }

    /** The set this row belongs to -- a `products` row with type 'set'. */
    public function set()
    {
        return $this->belongsTo(Product::class, 'set_product_id');
    }

    /** The product in the box. */
    public function member()
    {
        return $this->belongsTo(Product::class, 'member_product_id');
    }

    /** The chosen option, when the set names one. Null is the whole product. */
    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'member_variant_id');
    }
}
