<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return ['manage_stock' => 'bool', 'price' => 'int', 'sale_price' => 'int'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues()
    {
        return $this->belongsToMany(AttributeValue::class);
    }

    /**
     * What this variant is actually charged at, in fils.
     *
     * THE SALE WINDOW IS THE PARENT'S, because it is the only one there is.
     * `product_variants` carries `price` and `sale_price` and no date columns
     * at all; a variable product's markdown is scheduled once, on its
     * `products` row, for every variant under it.
     *
     * This method used to be:
     *
     *     return (int) ($this->sale_price ?? $this->price ?? $this->product?->effectivePrice() ?? 0);
     *
     * — `sale_price` returned unconditionally, with no reference to any
     * schedule. So on a variable product a sale that had ENDED kept selling at
     * the sale price forever, and one scheduled for next week sold at the
     * discount today, while the product page, the shop's price sort and filter
     * and the Sale badge had all correctly reverted or not yet started. This is
     * the third answer to "what does this cost" in a codebase that has twice
     * been repaired specifically so there would be one: see the note in
     * Api\CheckoutController ("an expired sale kept selling at the sale price
     * and a future one sold early. Money, quietly, in both directions") and
     * Support\EffectivePrice, which mirrors Product::effectivePrice()
     * condition for condition so the filter cannot disagree with the card.
     *
     * It matters more here than in either of those, because this is the value
     * CartService::add() snapshots onto `cart_items.unit_price`, which becomes
     * `order_items.unit_price` and `orders.subtotal`. A sort order that
     * disagrees is a bad list; this one is money.
     *
     * A NULL window still means an open-ended sale, which is the reading
     * Product::effectivePrice() gives it, so an ordinary unscheduled markdown
     * behaves exactly as it always has.
     */
    public function effectivePrice(): int
    {
        // The variant's own regular price, or the parent's charged price when
        // the variant is priced by neither column. Unchanged from before.
        $base = $this->price ?? $this->product?->effectivePrice() ?? 0;

        if ($this->sale_price === null) {
            return (int) $base;
        }

        return $this->saleWindowOpen() ? (int) $this->sale_price : (int) $base;
    }

    /**
     * Is the parent product's sale running right now?
     *
     * Same two conditions, in the same order, as Product::effectivePrice().
     * A variant with no parent row to consult keeps the old unconditional
     * behaviour rather than silently dropping to full price — an orphan
     * variant is a data problem, not a reason to reprice a live line.
     */
    private function saleWindowOpen(): bool
    {
        $product = $this->product;

        if ($product === null) {
            return true;
        }

        $now = now();

        if ($product->sale_starts_at && $now->lt($product->sale_starts_at)) {
            return false;
        }

        if ($product->sale_ends_at && $now->gt($product->sale_ends_at)) {
            return false;
        }

        return true;
    }

    public function inStock(): bool
    {
        return $this->stock_status === 'instock';
    }

    /** e.g. "50ml / Rose" — built from the variant's attribute values. */
    public function label(): string
    {
        return $this->attributeValues->pluck('name')->implode(' / ');
    }

}
