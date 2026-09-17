<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;

/**
 * The rows a listing page hands to App\Support\Seo for its ItemList.
 *
 * ── WHY THIS IS ONE CLASS AND NOT THREE CONTROLLER PRIVATES ────────────────
 *
 * Four surfaces publish the same kind of page — the shop root, a category
 * archive, a brand landing page, and the four curated listings — and all four
 * reached for a different controller. A second copy of "what a product looks
 * like to a crawler" is how the two copies end up disagreeing, and this
 * repository already carries the receipt for that: the admin wrote
 * `seo.description` while the storefront read `desc`, so the box saved and
 * nothing was ever published.
 *
 * ── THE PRICE, WHICH IS THE ONLY DANGEROUS FIELD HERE ──────────────────────
 *
 * `products.price` and `products.sale_price` are INTEGER FILS: AED × 100. A
 * 126.00 AED serum is the integer 12600 in the column. Handing that column
 * straight to a schema Offer is not a rounding error, it is a claim to every
 * search engine that the serum costs 12,600 — and it is a mistake this project
 * has already made once, caught in 2.60.36 before it shipped, and recorded in
 * the plan for exactly this reason.
 *
 * So the price leaves here as BOTH halves of the pair Seo::priceString()
 * understands, and never as the column:
 *
 *   'price'       the exact decimal STRING, built by Money::decimalString()
 *                 from the integer. A string, because priceString() takes a
 *                 string as already-formatted and a NUMBER as major units —
 *                 hand it the integer 12600 under this key and it multiplies
 *                 it by a hundred again.
 *   'price_minor' the integer itself, for any caller that wants to do its own
 *                 arithmetic without parsing a decimal back.
 *
 * `effectivePrice()` rather than `price`, so a product on sale advertises the
 * sale price the page is printing beside it, within the sale window and not
 * outside it.
 *
 * ── WHAT IS DELIBERATELY NOT PUBLISHED ─────────────────────────────────────
 *
 * No rating and no review count. The product page publishes an AggregateRating
 * from the real, approved reviews it has just counted; a listing tile has no
 * such count in hand (the card reads a denormalised `rating` column that
 * DemoReviews also writes), and a review figure a crawler can reach that the
 * database cannot confirm is the defect ▒34 in the plan is about. A listing
 * ItemList is a list of products, not a second, weaker source of review data.
 *
 * No `wc_id`, no `total_sales`, no `position`. Same allowlist discipline as
 * Product::toApi(): a listing page is a public document, so what goes on it is
 * named one field at a time rather than filtered out of everything the model
 * happens to be carrying.
 */
final class CollectionSchema
{
    /**
     * @param  iterable<int, Product>  $products  in the order the page draws them
     * @param  string  $base  absolute site root, no trailing slash
     * @param  int  $offset  rows before the first one on this page (0 on page one)
     * @return array{items: array<int, array<string, mixed>>, offset: int}
     */
    public static function from(iterable $products, string $base, int $offset = 0): array
    {
        $base = rtrim($base, '/');
        $items = [];

        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }

            $minor = $product->effectivePrice();

            $items[] = array_filter([
                'name' => (string) $product->name,
                'url' => $base . $product->url(),
                /*
                 * The featured shot only, AND EXACTLY THE STRING THE TILE PUTS
                 * IN ITS src.
                 *
                 * `products.image` is already a usable address:
                 * components/product-card.blade.php renders it as
                 * `src="{{ $product->image }}"` with nothing applied to it, and
                 * Store\ProductController hands the same column to the Product
                 * node the same way. An earlier draft here ran it through
                 * Url::media(), which prefixes the WordPress uploads root -- so
                 * a path that was already complete would have been published as
                 * /wp-content/uploads/<that path>, a 404 in the one field
                 * Google uses to draw the picture. Seo::absolute() puts the
                 * site root in front of whatever this is, which is the same
                 * treatment og:image and the Product node already get.
                 *
                 * The gallery belongs to the product page that draws it; a tile
                 * draws one picture and publishes one.
                 */
                'image' => is_string($product->image) && $product->image !== ''
                    ? $product->image
                    : null,
                'sku' => is_string($product->sku ?? null) && $product->sku !== '' ? $product->sku : null,
                // `brand` is eager-loaded by every caller. `?->` rather than a
                // relation read, so a product with no brand row costs no query
                // and publishes no empty Brand node.
                'brand' => $product->relationLoaded('brand') ? ($product->brand?->name) : null,
                // Read the long note above before changing either of these.
                'price' => Money::decimalString($minor),
                'price_minor' => $minor,
                'currency' => Money::currency(),
                // The real column, never a synthesised boolean:
                // Seo::availability() has to tell 'outofstock' and
                // 'onbackorder' apart to publish what the owner decided.
                'stock_status' => $product->stock_status,
            ], static fn ($v) => $v !== null && $v !== '');
        }

        return ['items' => $items, 'offset' => max(0, $offset)];
    }
}
