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

    public function name(): string
    {
        return 'products';
    }

    public function conventionalFile(): string
    {
        return 'products.csv';
    }

    public function import(Row $row, ImportContext $context): void
    {
        $wcId = $row->requireId('id', 'id', 'wc_id', 'product_id', 'post_id');
        $name = $row->requireText('name', 'name', 'post_title', 'title');

        $slug = $row->text('slug', 'post_name') ?? Str::slug($name);

        if ($slug === '') {
            throw RowRejected::because("name '".$name."' does not reduce to a usable slug");
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
            'short_description' => self::cleanHtml($row->text('short_description', 'post_excerpt')),
            'description' => self::cleanHtml($row->text('description', 'post_content')),
            'image' => $row->text('image', 'featured_image'),
            'featured' => $row->bool(false, 'featured', 'is_featured'),
            'position' => $row->int((int) ($product->position ?? 0), 'position', 'menu_order'),
            'total_sales' => max(0, $row->int((int) ($product->total_sales ?? 0), 'total_sales')),
        ];

        $images = $row->list('|', 'images', 'image_gallery', 'gallery');

        if ($images === []) {
            $images = $row->list(',', 'images', 'image_gallery', 'gallery');
        }

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
