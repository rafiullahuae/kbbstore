<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{

    protected $guarded = [];

    /**
     * Every write to this table drops App\Services\VariantPricing's snapshot.
     *
     * The same hook Product::booted() carries, for the other half of the same
     * query: that snapshot is a GROUP BY over `product_variants`, so adding a
     * variation, repricing one or marking one down invalidates it just as
     * surely as inserting the parent does. Without this, a console process that
     * imported a parent and then priced its variations would read the range it
     * had before they existed — and range() answering null is effectivePrice()
     * answering 0 fils, which is the AED 0 that class exists to remove.
     *
     * See the note on Product::booted() for why the signal is taken here, and
     * VariantPricing::invalidate() for what it costs and where it stops.
     */
    protected static function booted(): void
    {
        static::saved(static fn () => \App\Services\VariantPricing::invalidate());
        static::deleted(static fn () => \App\Services\VariantPricing::invalidate());
    }

    protected function casts(): array
    {
        return ['manage_stock' => 'bool', 'price' => 'int', 'sale_price' => 'int'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The size/shade values this variant is defined by.
     *
     * THE PIVOT TABLE IS NAMED EXPLICITLY, because Laravel's convention does
     * not produce the name this schema uses. belongsToMany() sorts the two
     * model names alphabetically -- attribute_value + product_variant ->
     * `attribute_value_product_variant` -- and 0001_01_01_000000_create_kbb_schema
     * creates `product_variant_attribute_value`. That table does not exist, so
     * the relation threw "no such table" every time it was touched:
     *
     *   - Store\ProductController eager-loads variants.attributeValues, so ANY
     *     product page for a product with at least one variant returned a 500.
     *   - Store\CheckoutController eager-loads items.variant.attributeValues,
     *     so the checkout died on any basket holding a variant line.
     *   - store/cart-inner.blade.php calls $item->variant?->label(), which reads
     *     the same relation, so the cart page died too.
     *
     * The column names were always right; only the table name was wrong. The
     * admin's AttributesApiController has been querying the real table by name
     * through the query builder all along, which is why the schema side is the
     * authority here and the model is the half that moves.
     *
     * Invisible until now only because nothing in the seeders or the suite ever
     * created a ProductVariant row. The moment the owner adds a size or a shade
     * to a product in the admin, that product's page stops loading.
     */
    public function attributeValues()
    {
        return $this->belongsToMany(AttributeValue::class, 'product_variant_attribute_value');
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


    /**
     * The figure THIS option was marked down from, or null when it has none.
     *
     * ── WHY A VARIATION NEEDS ITS OWN COMPARE-AT ────────────────────────────
     *
     * Product::compareAtPrice() answers for the PRODUCT, and on a variable one
     * that is deliberately MIN(regular) across the variations — the from-price
     * the tile printed the day before the markdown started. That is the right
     * figure everywhere the product is collapsed to one number: the tile, the
     * headline, the "On sale" facet.
     *
     * A basket line is the one place it is not. The line knows exactly which
     * option is in it, so the honest "was" there is that option's own regular
     * price. The two differ whenever the shopper picked a dearer option, and
     * they differ in the direction that understates: a parent whose options are
     * AED 120 and AED 190 has a compare-at of AED 120, so a line holding the
     * AED 190 option marked down to AED 140 had a compare-at BELOW what is
     * being charged. store/cart-inner.blade.php clamped that with max() rather
     * than print it — correct, and silent: the line showed AED 140 with no
     * struck figure beside it, for a shopper who is in fact saving AED 50.
     *
     * NULL, NEVER ZERO, and for the same reason Product::compareAtPrice()
     * answers null: `product_variants.price` is nullable, and a variation
     * priced by `sale_price` alone has no compare-at that can be vouched for.
     * `(int) null` would advertise a markdown FROM AED 0.
     */
    public function compareAtPrice(): ?int
    {
        return $this->price === null ? null : (int) $this->price;
    }

    /**
     * Is THIS option cheaper right now than it normally is?
     *
     * Product::isOnSale() in the shape a variation can answer: a compare-at it
     * can vouch for, a markdown below it, and the parent's window open — which
     * is the only window there is, because `product_variants` carries no date
     * columns at all (see effectivePrice() above).
     *
     * ── IT NEVER LOADS THE PARENT, AND THAT IS THE N+1 GUARD ────────────────
     *
     * The window lives on `products`, so this has to consult the parent row —
     * and reading `$this->product` would lazily fetch it. Every caller of this
     * method is a LIST: Store\CartController::loadCart() eager-loads
     * `items.variant` with a named column list and does NOT load
     * `variant.product`, so a basket of ten variable lines would have run ten
     * extra queries, one per line, on the screen where somebody decides to pay.
     *
     * So an unloaded parent is not fetched, it is REFUSED: no window that can
     * be seen means no sale that can be vouched for. The caller that has the
     * parent in hand — and the cart does, as `$item->product`, the very same
     * row — hands it over with setRelation() and this costs nothing. An N+1
     * here is not slow, it is impossible.
     *
     * ── AND AN UNSELECTED WINDOW FAILS CLOSED ───────────────────────────────
     *
     * Half this application hydrates explicit column lists on public paths.
     * Eloquent answers null for a column it never selected, and two null bounds
     * read as "no start, no end" — a sale that is always on. That is the trap
     * Product::advertisedSalePrice() documents one model along, and it fails in
     * the direction that keeps advertising an expired markdown. A parent whose
     * `sale_starts_at` / `sale_ends_at` were not selected therefore answers
     * false here rather than "open".
     */
    public function isOnSale(): bool
    {
        $compare = $this->compareAtPrice();

        if ($compare === null || $this->sale_price === null || (int) $this->sale_price >= $compare) {
            return false;
        }

        // Never `$this->product`: that accessor would lazily fetch the row.
        if (! $this->relationLoaded('product')) {
            return false;
        }

        $product = $this->getRelation('product');

        if ($product === null) {
            return false;
        }

        $attributes = $product->getAttributes();

        if (! array_key_exists('sale_starts_at', $attributes)
            || ! array_key_exists('sale_ends_at', $attributes)) {
            return false;
        }

        $now = now();

        if ($product->sale_starts_at && $now->lt($product->sale_starts_at)) {
            return false;
        }

        return ! ($product->sale_ends_at && $now->gt($product->sale_ends_at));
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
