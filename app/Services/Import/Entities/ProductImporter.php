<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Product;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Services\Import\SlugGuard;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Products, matched on `wc_id`.
 *
 * `wc_id` IS A URL CONTRACT, not merely a key. `?add-to-cart={id}` links built
 * against the WooCommerce post ids are live in the wild — in old emails, in
 * affiliate posts, in the Meta catalogue feed — so the id has to survive the
 * migration attached to the same product it named before. That is also why this
 * importer will not fall back to matching on SKU: `products.sku` is indexed and
 * NOT unique, production reuses SKUs across variants, and a product matched on a
 * repeated SKU is a product whose old add-to-cart links now point at something
 * else.
 *
 * STATUS. WooCommerce post statuses are publish / draft / private / pending /
 * trash. This column holds publish / draft / private, and the previous dead
 * importer in this repository defaulted it to 'active' — a value the schema has
 * no concept of, which would have made every imported product invisible to
 * every query that filters on status. Mapped explicitly below; a status not in
 * the map is a rejection rather than a guess, because guessing wrong in the
 * unsafe direction publishes something the owner had unpublished.
 *
 * PRICE. Two columns, `price` and `sale_price`, both integer fils. Woo's
 * exporter writes "Regular price" and "Sale price"; `price` here is the regular
 * price, and the storefront decides what to charge from the sale window. A
 * product with a sale price and no regular price is rejected, because the
 * schema has no way to express "on sale from nothing".
 *
 * CATEGORIES. `products.category_id` is the primary category and
 * `category_product` is the full set; both are written. The pivot is a composite
 * primary key, so re-inserting the same pair fails rather than duplicating —
 * the sync below tolerates that by computing the difference instead of
 * re-inserting, which also makes a second pass report the product unchanged
 * rather than churning the pivot.
 */
final class ProductImporter extends EntityImporter
{
    /** @var array<string, string> Woo post status => this schema's status */
    private const STATUS_MAP = [
        'publish' => 'publish',
        'published' => 'publish',
        'draft' => 'draft',
        'private' => 'private',
        'pending' => 'draft',
        'future' => 'draft',
        'instock' => 'publish',
        '1' => 'publish',
        '0' => 'draft',
        '-1' => 'private',
    ];

    /** @var array<string, string> */
    private const STOCK_MAP = [
        'instock' => 'instock',
        'in stock' => 'instock',
        '1' => 'instock',
        'yes' => 'instock',
        'outofstock' => 'outofstock',
        'out of stock' => 'outofstock',
        '0' => 'outofstock',
        'no' => 'outofstock',
        'onbackorder' => 'onbackorder',
        'on backorder' => 'onbackorder',
        'backorder' => 'onbackorder',
    ];

    /**
     * SKUs this run has already placed, so a duplicate inside ONE export is
     * caught as well as a duplicate against a row already in the database.
     *
     * A per-instance array and not a static: ImportRunner::entities() builds a
     * fresh importer per run, so it cannot leak between runs, and a dry run's
     * rollback cannot leave it holding ids that no longer exist.
     *
     * @var array<string, int> sku => the wc_id that placed it
     */
    private array $skusSeen = [];

    /**
     * SKUs already reported as shared, so the count does not depend on how
     * much of the import had already run.
     *
     * WHY THIS IS NOT OPTIONAL, and it was found by the resume test rather than
     * by reading. A collision reported PER ROW says "1" on the first pass —
     * only the second of the pair sees the first — and "2" on every pass after
     * that, because by then both rows are in the database and each one finds
     * the other. A number in the discard report that changes depending on
     * whether the run was interrupted is a number the owner cannot act on, and
     * "unchanged on the second pass" is the only evidence this importer offers
     * that it did the same thing twice. The report has to be idempotent for the
     * same reason the writes do.
     *
     * Reported once per SKU, which is also the truthful unit: one SKU is shared
     * by two products, and that is one problem, not two.
     *
     * @var array<string, true>
     */
    private array $skusReported = [];

    public function name(): string
    {
        return 'products';
    }

    public function conventionalFile(): string
    {
        return 'products.csv';
    }

    /** Products the export supplied. withTrashed(), deliberately: a soft-deleted product is still a row this import wrote, and counting it as missing would send the owner looking for an import failure that is a deletion. */
    public function countImported(): ?int
    {
        return Product::query()->withTrashed()->whereNotNull('wc_id')->count();
    }

    public function import(Row $row, ImportContext $context): void
    {
        $wcId = $row->requireId('id', 'id', 'wc_id', 'product_id', 'post_id');
        $name = $row->requireText('name', 'name', 'post_title', 'title');

        $sourceSlug = $row->text('slug', 'post_name');
        $slug = $sourceSlug ?? Str::slug($name);

        if ($slug === '') {
            throw RowRejected::because("name '".$name."' does not reduce to a usable slug");
        }

        /*
         * THE SLUG IS THE ADDRESS, and this is the one place the import can
         * silently move a page Google already has.
         *
         * RedirectMap's stated finding is that products do not move: Woo's
         * default product base and this shop's U-01 are both /product/{slug}/,
         * and SlugGuard never rewrites a slug. That is true only while the
         * slug COMES FROM THE EXPORT. When the export carries no slug column --
         * and several Woo exporters do not -- this line invents one with
         * Str::slug($name), which is a transliteration, not a copy. Str::slug()
         * turns "مرطب الوجه — Creme Hydratante 保湿" into
         * "mrtb-alogh-creme-hydratante": the Arabic is romanised and the CJK is
         * dropped outright. The old address and the new one are then different
         * strings, the old one 404s, and the import report said "created".
         *
         * Reported as an adjustment rather than refused, because a regenerated
         * slug is usually right and refusing 671 products over it helps nobody.
         * What the owner needs is the list, so a redirect can be written for
         * each one.
         */
        if ($sourceSlug === null) {
            $context->report->for($this->name())->adjusted(
                'slug invented from the product name because the export carried no slug column '
                .'-- the old /product/<slug>/ address will 404 unless a redirect is written',
                $row->line,
                $this->identify($row),
                'slug',
                $name,
                $slug,
            );
        }

        $price = $row->money('regular_price', 'regular_price', 'price');
        $salePrice = $row->money('sale_price', 'sale_price');

        if ($price === null && $salePrice !== null) {
            throw RowRejected::because(
                'sale_price is set but regular_price is empty — this schema prices from the regular price '
                .'and has no way to express a sale from nothing'
            );
        }

        $status = $this->mapStatus($row);
        $stockStatus = $this->mapStockStatus($row);

        $this->reportSku($row, $wcId, $name, $context);
        $this->reportFils($row, $context, $price, $salePrice);

        $product = Product::query()->withTrashed()->where('wc_id', $wcId)->first();

        if ($product === null) {
            $adopt = SlugGuard::resolve(
                Product::query()->withTrashed(), 'products', 'wc_id', $slug, $wcId, $context, $this->name(),
            );

            $product = $adopt === null ? new Product : Product::query()->withTrashed()->findOrFail($adopt);
        }

        $brandId = null;
        $brandTerm = $row->id('brand_term_id', 'brand_term_id', 'brand_id');

        if ($brandTerm !== null) {
            $brandId = $context->localId('brands', $brandTerm);

            if ($brandId === null) {
                $context->report->for($this->name())->note(
                    'brand term '.$brandTerm.' is not in this import; the product was imported without a brand'
                );
            }
        }

        $categoryTerms = array_values(array_unique(array_map(
            'intval',
            array_filter($row->list(',', 'category_term_ids', 'categories', 'category_ids'), 'is_numeric'),
        )));

        $categoryIds = [];

        foreach ($categoryTerms as $term) {
            $localId = $context->localId('categories', $term);

            if ($localId === null) {
                $context->report->for($this->name())->note(
                    'category term '.$term.' is not in this import; the product was not filed under it'
                );

                continue;
            }

            $categoryIds[] = $localId;
        }

        $attributes = [
            'wc_id' => $wcId,
            'slug' => $slug,
            'name' => $name,
            'sku' => $row->text('sku'),
            'brand_id' => $brandId,
            'category_id' => $categoryIds[0] ?? null,
            'type' => $this->mapType($row),
            'status' => $status,
            'is_visible' => $row->bool(true, 'is_visible', 'visible', 'visibility_in_catalogue', 'catalog_visibility'),
            'price' => $price,
            'sale_price' => $salePrice,
            'sale_starts_at' => $row->date('sale_starts_at', $context->timezone(), 'sale_starts_at', 'date_sale_price_starts', 'date_on_sale_from'),
            'sale_ends_at' => $row->date('sale_ends_at', $context->timezone(), 'sale_ends_at', 'date_sale_price_ends', 'date_on_sale_to'),
            'manage_stock' => $row->bool(false, 'manage_stock'),
            'stock' => $row->text('stock', 'stock_quantity') === null ? null : $row->int(0, 'stock', 'stock_quantity'),
            'stock_status' => $stockStatus,
            // Sanitised on the way in, not on the way out.
            //
            // A WooCommerce export is a THIRD-PARTY FILE. Everything else in
            // this importer treats it that way -- statuses are mapped rather
            // than trusted, money is parsed digit by digit, a slug goes past
            // SlugGuard -- and these two columns were the exception, copied
            // through verbatim into the one place the storefront prints raw:
            // partials/product-tabs.blade.php renders both with {!! !!}.
            //
            // That makes this the one RichText bypass whose threat model needs
            // no hostile admin. The owner imports a catalogue somebody else
            // generated, every byte of post_content in it is that somebody's to
            // choose, and the result is stored XSS on every imported product
            // page. See App\Support\RichText for why the allowlist is the
            // control and the editor is only a convenience.
            'short_description' => $this->cleanHtmlReported($row->text('short_description', 'post_excerpt'), 'short_description', $row, $context),
            'description' => $this->cleanHtmlReported($row->text('description', 'post_content'), 'description', $row, $context),
            'image' => $row->text('image', 'featured_image'),
            'featured' => $row->bool(false, 'featured', 'is_featured'),
            'position' => $row->int((int) ($product->position ?? 0), 'position', 'menu_order'),
            'total_sales' => max(0, $row->int((int) ($product->total_sales ?? 0), 'total_sales')),
        ];

        /*
         * THE GALLERY SEPARATOR IS CHOSEN BY LOOKING, not by trying one and
         * falling back, and the difference is not stylistic.
         *
         * This was `list('|', …)` with a `if ($images === []) list(',', …)`
         * fallback under it. `Row::list()` splits and then drops empty parts,
         * so splitting "a.jpg,b.jpg" on "|" returns ONE element — the whole
         * string — which is not `[]`, so the comma branch never ran. The only
         * input that reached it was an empty cell, for which the comma split
         * also returns `[]`. A fallback that can only fire when it has nothing
         * to do is the same dead-filter shape as `Api\ProductController`'s
         * status check, and it hid the same kind of second bug.
         *
         * WHAT IT COST: WooCommerce's own product CSV exporter writes the
         * Images column COMMA-separated. So every multi-image product imported
         * with its whole gallery as a single entry —
         * "https://…/a.jpg,https://…/b.jpg" — one string that is not a URL. The
         * product page then renders one broken image instead of the four that
         * were exported, and the import report says "created" either way. Found
         * by `kbb:import-media`, which is the entire argument for that command
         * existing: nothing in a row-count reconciliation can see this.
         *
         * A pipe is the unambiguous case, so it wins where it appears; a URL
         * cannot contain a bare "|". Otherwise the comma is what Woo wrote.
         */
        $raw = $row->text('images', 'image_gallery', 'gallery');
        $images = $raw !== null && str_contains($raw, '|')
            ? $row->list('|', 'images', 'image_gallery', 'gallery')
            : $row->list(',', 'images', 'image_gallery', 'gallery');

        if ($images !== []) {
            $attributes['images'] = $images;
        }

        /*
         * created_at from the Woo post date where the export carries one. Less
         * load-bearing than it is on orders — no screen derives a figure from
         * it — but a catalogue whose every product says it was created on
         * cutover day loses the "newest" sort the shop page offers, so it is
         * preserved where it is available and left to Eloquent where it is not.
         */
        $createdAt = $row->date('date_created', $context->timezone(), 'date_created', 'post_date', 'created_at');

        if ($createdAt !== null) {
            $attributes['created_at'] = $createdAt;
        }

        $outcome = $context->apply($product, $attributes);

        $context->record($this->name(), $outcome);
        $context->remember($this->name(), $wcId, (int) $product->id);

        $pivotChanged = $this->syncCategories((int) $product->id, $categoryIds);

        // A product whose own columns did not move but whose category
        // membership did IS an update, and reporting it as unchanged would make
        // the idempotency evidence a lie.
        if ($pivotChanged && $outcome === 'unchanged') {
            $report = $context->report->for($this->name());
            $report->unchanged--;
            $report->updated();
        }
    }

    /**
     * @param  list<int>  $categoryIds
     * @return bool whether anything actually moved
     */
    private function syncCategories(int $productId, array $categoryIds): bool
    {
        $categoryIds = array_values(array_unique($categoryIds));

        $existing = DB::table('category_product')
            ->where('product_id', $productId)
            ->pluck('category_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $toAdd = array_diff($categoryIds, $existing);
        $toRemove = array_diff($existing, $categoryIds);

        if ($toAdd === [] && $toRemove === []) {
            return false;
        }

        if ($toAdd !== []) {
            DB::table('category_product')->insert(array_map(
                static fn (int $categoryId): array => ['category_id' => $categoryId, 'product_id' => $productId],
                array_values($toAdd),
            ));
        }

        if ($toRemove !== []) {
            DB::table('category_product')
                ->where('product_id', $productId)
                ->whereIn('category_id', array_values($toRemove))
                ->delete();
        }

        return true;
    }

    /**
     * @throws RowRejected
     */
    private function mapStatus(Row $row): string
    {
        $raw = $row->text('status', 'post_status', 'published');

        if ($raw === null) {
            return 'publish';
        }

        $key = mb_strtolower(trim($raw));

        if ($key === 'trash' || $key === 'trashed') {
            throw RowRejected::because(
                "status 'trash' — this product is in the WordPress trash. "
                .'Importing it would make it a live row; empty the trash or filter the export.'
            );
        }

        if (! isset(self::STATUS_MAP[$key])) {
            throw RowRejected::because(
                "status '".$raw."' is not one this schema knows (publish, draft, private). "
                .'Guessing would risk publishing something you had unpublished.'
            );
        }

        return self::STATUS_MAP[$key];
    }

    /**
     * @throws RowRejected
     */
    private function mapStockStatus(Row $row): string
    {
        $raw = $row->text('stock_status', 'in_stock');

        if ($raw === null) {
            return 'instock';
        }

        $key = mb_strtolower(trim($raw));

        if (! isset(self::STOCK_MAP[$key])) {
            throw RowRejected::because(
                "stock_status '".$raw."' is not one this schema knows (instock, outofstock, onbackorder)"
            );
        }

        return self::STOCK_MAP[$key];
    }

    private function mapType(Row $row): string
    {
        $raw = mb_strtolower((string) ($row->text('type', 'product_type') ?? 'simple'));

        // The schema's column comment says simple | variable. Everything else
        // Woo offers — grouped, external, subscription — is stored verbatim
        // rather than coerced: the column is free-form and a wrong coercion
        // would make a grouped product behave as a purchasable simple one.
        return $raw === '' ? 'simple' : $raw;
    }

    /**
     * `products.sku` carries no unique index, so neither of these stops an
     * import -- which is exactly why neither of them was ever said out loud.
     *
     * A DUPLICATE SKU IS NOT A COSMETIC PROBLEM on this shop. It is the key the
     * owner reconciles stock against, the key the Meta catalogue feed is built
     * on, and the key an admin types into the product search. Two products
     * holding one SKU means one of them is unreachable by the only handle the
     * warehouse uses. Woo permits it across variants; this schema stores
     * variants as ordinary products, so the collision arrives flattened and
     * indistinguishable from a genuine mistake.
     *
     * A MISSING SKU is the same fact with the opposite shape: the product is in
     * the shop and has no warehouse handle at all.
     *
     * Both are reported and both are imported. The owner decides.
     */
    private function reportSku(Row $row, int $wcId, string $name, ImportContext $context): void
    {
        $sku = $row->text('sku');
        $report = $context->report->for($this->name());

        if ($sku === null) {
            $report->adjusted(
                'no SKU in the export -- the product imports with an empty SKU and cannot be '
                .'reconciled against stock or the Meta catalogue feed by one',
                $row->line,
                $this->identify($row),
                'sku',
                $name,
                '(no SKU)',
            );

            return;
        }

        $holder = Product::query()
            ->withTrashed()
            ->where('sku', $sku)
            ->where(function ($q) use ($wcId): void {
                $q->whereNull('wc_id')->orWhere('wc_id', '!=', $wcId);
            })
            ->value('wc_id');

        if ($holder === null && ! isset($this->skusSeen[$sku])) {
            $this->skusSeen[$sku] = $wcId;

            return;
        }

        if (isset($this->skusReported[$sku])) {
            return;
        }

        $this->skusReported[$sku] = true;

        $report->adjusted(
            'two products share one SKU -- products.sku has no unique index so both import, but only '
            .'one of them can be found by it afterwards',
            $row->line,
            $this->identify($row),
            'sku',
            $sku.' (also on product '.($holder ?? $this->skusSeen[$sku]).')',
            $sku,
        );
    }

    /**
     * An amount the storefront cannot print.
     *
     * The owner has settled that this shop prices in whole dirhams, and that
     * decision lives in App\Support\Money::displayDecimals(), which returns 0
     * here. format() therefore ROUNDS. A product imported at AED 99.50 is
     * charged at 9,950 fils and PRINTED as "AED 100" -- a shop that shows one
     * price and takes another, on every tile, every product page and every
     * receipt line that goes through format().
     *
     * Rounding it on the way in would be a silent edit to the owner's prices,
     * which Money refuses to do for three decimals and should not do for two.
     * So it is imported exactly and named, and the owner decides whether to fix
     * the price in WooCommerce or widen the display.
     */
    private function reportFils(Row $row, ImportContext $context, ?int $price, ?int $salePrice): void
    {
        if (\App\Support\Money::displayDecimals() !== 0) {
            return;
        }

        foreach (['price' => $price, 'sale_price' => $salePrice] as $field => $fils) {
            if ($fils === null || $fils % 100 === 0) {
                continue;
            }

            $context->report->for($this->name())->adjusted(
                'a price carrying fils in a shop that prints whole dirhams -- it is stored and charged '
                .'exactly, and printed rounded, so the shopper is shown a price the shop does not take',
                $row->line,
                $this->identify($row),
                $field,
                \App\Support\Money::amount($fils, 2),
                \App\Support\Money::amount($fils, 0).' (as printed)',
            );
        }
    }

    /**
     * RichText over an imported HTML column, and a note of what it took out.
     *
     * THE ALLOWLIST IS A DISCARD AND THE OWNER APPROVES DISCARDS. cleanHtml()
     * is not a formatting pass: on a WooCommerce export written by a plugin it
     * removes whole elements -- a script, an iframe, an embedded video, a
     * shortcode wrapper, a styled table -- and what is left is shorter than
     * what arrived. Removing the script is the right call and is why the method
     * exists. Doing it without saying so is not: the owner reads "created" and
     * has no way to learn that forty product pages lost their video.
     *
     * Compared by length rather than by diff, deliberately. A diff of kilobytes
     * of HTML is not something a console report can usefully carry, and the
     * question the owner is actually asking is "did this product lose
     * anything, and how much".
     */
    private function cleanHtmlReported(?string $html, string $field, Row $row, ImportContext $context): ?string
    {
        $cleaned = self::cleanHtml($html);

        if ($html === null || $cleaned === null || $cleaned === $html) {
            return $cleaned;
        }

        $context->report->for($this->name())->discarded(
            'HTML the allowlist removed -- the import strips what a browser would execute, so the '
            .'imported description is not byte-for-byte what WooCommerce held',
            $row->line,
            $this->identify($row),
            $field,
            mb_strlen($html).' characters: '.$html,
            mb_strlen($cleaned).' characters kept',
        );

        return $cleaned;
    }

    /**
     * RichText over an imported HTML column, preserving "the export did not
     * carry this field at all".
     *
     * NULL IN, NULL OUT, deliberately. Row::text() returns null for a column
     * the export omits, and the importer's change detection compares the
     * attribute array against the row it already has: turning a null into ''
     * would make every product look modified on the next pass and churn the
     * whole catalogue's updated_at. Cleaning is not supposed to be a content
     * change, so it does not get to invent one.
     *
     * A non-null value is returned exactly as the allowlist leaves it, '' and
     * all, for the same reason -- this method's job is to remove what a browser
     * would execute, not to normalise blanks. That normalisation belongs to the
     * editor, which has an operator in front of it.
     */
    private static function cleanHtml(?string $html): ?string
    {
        return $html === null ? null : RichText::clean($html);
    }
}
