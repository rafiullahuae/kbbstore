<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Brand;

/**
 * The rows the A–Z brand directory hands to App\Support\Seo for its ItemList.
 *
 * ── WHY THIS IS NOT CollectionSchema ────────────────────────────────────────
 *
 * Lane FQ shipped CollectionPage/ItemList for the category archives, the brand
 * LANDING pages and the four curated listings, and deliberately left
 * BrandController::index() alone, because every one of those pages lists
 * PRODUCTS and this one lists BRANDS. CollectionSchema's whole body is about
 * the one field that is dangerous on a product row — the price, which is
 * integer fils and which this project has already once published as 12,600 AED
 * for a 126 AED serum. A brand directory has no price, no stock status and no
 * SKU, so reusing that builder would mean either passing it models it cannot
 * read or teaching it a second shape. Two shapes in one builder is how the two
 * shapes end up disagreeing.
 *
 * So: a separate builder, and no price anywhere in it. There is nothing here
 * for the fils bug to happen to, and that is a property of the data rather
 * than a guard, which is why it is stated rather than tested for.
 *
 * ── WHAT EACH ROW IS ───────────────────────────────────────────────────────
 *
 * A `Brand` node, not a `Product` and not a bare `WebPage`. Google documents
 * two shapes for an ItemList — the "summary page" form, where each ListItem
 * carries only a position and a url pointing at a page of its own, and the
 * "all-in-one" form, where the ListItem carries the item itself. This page is
 * the first kind: every tile links onward to /korean-skincare-brands/{slug}/,
 * which is a real page with its own CollectionPage node. Naming the entity at
 * that URL as a Brand is both permitted in that form and truer than a WebPage,
 * because the thing the shopper is choosing between is a brand.
 *
 * `logo` is included only when the brand actually has one, because the tile
 * actually draws it — `store/brands.blade.php` renders `$b->logo` straight into
 * a background-image. A directory in `names` display mode draws no logo at all
 * and this builder does not know which mode is set; the caller does, and passes
 * it, so a page that shows no logos publishes none. Structured data that
 * describes a picture the page does not contain is the defect this whole family
 * of work exists to avoid.
 *
 * ── WHAT IS DELIBERATELY NOT PUBLISHED ─────────────────────────────────────
 *
 * NO PRODUCT COUNT. `index()` computes `products_count` for the tile's muted
 * state, and there is a real temptation to publish it as, say, a
 * `numberOfItems` on each entry. It would be wrong: `numberOfItems` on a
 * ListItem means nothing, and the count is not a property of the Brand either.
 * The shop's statement about how many products a brand has belongs on that
 * brand's own page, which already makes it.
 *
 * NO `description`. `brands.description` is the owner's marketing copy and is
 * published, in full, on the brand's own landing page — which is where a
 * crawler following the url will find it. Repeating ninety-three descriptions
 * into one document makes a large page that says nothing new.
 *
 * NO EMPTY BRANDS DROPPED. The directory lists a brand with nothing in stock
 * today, muted rather than hidden, and says so in its own subtitle. The list
 * is the rows the page draws; dropping some of them would make the ItemList
 * and the visible page disagree about what is on the visible page.
 */
final class BrandDirectorySchema
{
    /**
     * @param  iterable<int, Brand>  $brands  in the order the page draws them
     * @param  string  $base  absolute site root, no trailing slash
     * @param  bool  $withLogos  whether the tiles on this page actually draw logos
     * @return array{items: array<int, array<string, mixed>>, offset: int}
     */
    public static function from(iterable $brands, string $base, bool $withLogos = true): array
    {
        $base = rtrim($base, '/');
        $items = [];

        foreach ($brands as $brand) {
            if (! $brand instanceof Brand) {
                continue;
            }

            $slug = trim((string) $brand->slug);

            if ($slug === '') {
                continue;
            }

            $items[] = array_filter([
                /*
                 * `Brand`, which is what App\Support\Seo's collection branch
                 * puts in the node's @type when a row names one. Without this
                 * key that branch publishes `Product`, which is correct for
                 * every other caller and would be a lie here.
                 */
                'schema_type' => 'Brand',
                /*
                 * t(), not the column. An Arabic directory that publishes
                 * English names under an Arabic canonical is structured data
                 * disagreeing with the page it is on, which is the one kind
                 * Google acts on rather than merely ignores. CollectionSchema
                 * carries the same note and the same call for the same reason.
                 */
                'name' => (string) $brand->t('name'),
                /*
                 * The LANDING page, /korean-skincare-brands/{slug}/, which is
                 * where the tile links and which canonicalises to itself.
                 *
                 * NOT Brand::url(). URL Contract U-05 keeps that pointing at
                 * the filterable shop listing (/shop/?filter_brands={slug}) and
                 * BrandController::seoCtx() records, at length, that
                 * canonicalising one to the other tells Google this page does
                 * not exist.
                 *
                 * Url::to() is what applies KBB_BASE_PATH, and the base already
                 * carries it; Seo::canonical() collapses the one duplicate on
                 * the way out, which is why this is built exactly as the brand
                 * landing page builds its own canonical rather than as a bare
                 * literal.
                 */
                'url' => $base . Url::to('/korean-skincare-brands/' . $slug . '/'),
                'logo' => $withLogos && is_string($brand->logo) && trim($brand->logo) !== ''
                    ? trim($brand->logo)
                    : null,
            ], static fn ($v) => $v !== null && $v !== '');
        }

        return ['items' => $items, 'offset' => 0];
    }
}
