<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Product;
use App\Models\ProductTab;
use App\Services\Import\ImportContext;
use App\Services\Import\OldSiteLinks;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Services\Import\SlugGuard;
use App\Support\ProductTabs;
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
 *
 * WHAT DOES NOT CROSS, AND WHY IT IS STILL THIS CLASS'S BUSINESS. The exporter's
 * product row carries 46 columns; this class writes 24 of them. The other
 * twenty-two are not silently ignored -- see NOT_CARRIED below, which names each
 * one at run time with the value it held, and reportCarriedElsewhere(), which
 * handles the single column that looks lost and is not.
 *
 * The owner's instruction was "everything must be compatible without anything
 * skipping or losing", and a field dropped in silence is indistinguishable from
 * a field that was never there. Naming them costs one array lookup per column
 * per row and is worth more than most of the columns would be. Which of them
 * earn a column of their own is the owner's decision, and it is set out in
 * docs/PRODUCT-FIELD-PARITY.md.
 */
final class ProductImporter extends EntityImporter
{
    /** The old-site link ledger, replayed onto imported copy (Lane PT). */
    private ?OldSiteLinks $links = null;

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
     * ── WHAT THIS SHOP HAS NOWHERE TO PUT, NAMED ONE BY ONE ─────────────────
     *
     * The exporter's product row carries 46 columns and this importer reads 24
     * of them. Of the other twenty-two, `tag_term_ids` arrives by another road
     * (see reportCarriedElsewhere below) and these TWENTY-ONE do not arrive at
     * all: there is no column in `products` for any of them and no sibling
     * importer that picks them up.
     *
     * THREE OF THE TWENTY-ONE ARE NEW TO THE EXPORT, added by this lane:
     * `sold_individually`, `reviews_enabled` and `default_attributes` were on
     * the owner's edit page and in no file at all, which is the one kind of
     * loss no report here could see. See the note in the exporter's columns().
     *
     * WHY THEY ARE LISTED HERE RATHER THAN LEFT TO THE RUNNER. ImportRunner
     * already names every column no field of an importer reads, in one
     * consolidated line per entity. That line is a good backstop and a bad
     * answer to the owner's question, for three reasons this lane found by
     * reading the report it produces:
     *
     *   IT CANNOT SAY HOW MANY. The line is one discard with a count of 1 -- one
     *   observation about the file -- so `weight` dropped on 400 products and
     *   `weight` dropped on one look identical.
     *
     *   IT CANNOT SAY WHAT WAS IN THEM, beyond the FIRST value the file held for
     *   each. Five samples per kind is the report's own answer to "show me what
     *   this looks like", and a consolidated line gets one sample for nineteen
     *   columns between them.
     *
     *   IT CANNOT TELL A LOSS FROM A TRANSFER. `tag_term_ids` sat in that list
     *   beside `weight`, and the two are not the same kind of fact: the tags
     *   arrive from tags.csv and the weight arrives nowhere. A discard list with
     *   false alarms in it is a discard list nobody finishes reading, and the
     *   owner was being shown two.
     *
     * So each field gets its own discard KIND -- its own count, its own five
     * samples, its own sentence saying why it does not cross -- exactly the way
     * SeoImporter does it for the Yoast keys in YoastSeo::UNMAPPED. Reading the
     * field here is also what moves it OUT of the runner's consolidated line, so
     * the two channels do not report the same column twice.
     *
     * THE ALIASES ARE WOO'S OWN SPELLINGS and every one of them is a name this
     * importer does not already read. `catalog_visibility` is deliberately NOT
     * an alias of `product_visibility` even though Woo writes it there: this
     * importer reads it as `is_visible`, and claiming it as a drop would report
     * a field as lost while the same cell was being imported.
     *
     * @var array<string, array{why: string, aliases: list<string>}>
     */
    private const NOT_CARRIED = [
        'weight' => [
            'why' => 'there is no weight column on products, so nothing here can price a parcel by weight',
            'aliases' => ['weight_kg'],
        ],
        'length' => [
            'why' => 'there are no parcel dimensions on products',
            'aliases' => ['length_cm'],
        ],
        'width' => [
            'why' => 'there are no parcel dimensions on products',
            'aliases' => ['width_cm'],
        ],
        'height' => [
            'why' => 'there are no parcel dimensions on products',
            'aliases' => ['height_cm'],
        ],
        'shipping_class' => [
            'why' => 'there is no per-product shipping class; shipping is decided per zone here',
            'aliases' => [],
        ],
        'tax_status' => [
            'why' => 'tax is not decided per product here -- `tax_rates` holds the rates and currently has none',
            'aliases' => [],
        ],
        'tax_class' => [
            'why' => 'there is no per-product tax class to select into -- `tax_rates` has no class column',
            'aliases' => [],
        ],
        'virtual' => [
            'why' => 'every product is treated as a physical good; there is no virtual flag',
            'aliases' => ['is_virtual'],
        ],
        'downloadable' => [
            'why' => 'there are no downloadable products and no file permissions table',
            'aliases' => ['is_downloadable'],
        ],
        'backorders' => [
            'why' => 'there is no backorder policy column -- `stock_status` can say onbackorder but not whether to allow one',
            'aliases' => ['backorders_allowed'],
        ],
        'low_stock_amount' => [
            'why' => 'the low-stock threshold is one shop-wide setting here, not a per-product number',
            'aliases' => [],
        ],
        'upsell_ids' => [
            'why' => 'there is no upsell pivot; related products are computed from the category',
            'aliases' => ['upsells'],
        ],
        'cross_sell_ids' => [
            'why' => 'there is no cross-sell pivot',
            'aliases' => ['cross_sells'],
        ],
        'grouped_ids' => [
            'why' => 'grouped products are not a type this shop sells, so the children have nowhere to attach',
            'aliases' => ['grouped_products'],
        ],
        'purchase_note' => [
            'why' => 'there is no per-product note on the order-received page or the confirmation email',
            'aliases' => [],
        ],
        'product_visibility' => [
            'why' => "WooCommerce's four states (visible, catalog, search, hidden) fold into the "
                .'is_visible yes/no and the featured flag, so `search` and `hidden` both arrive as not visible '
                .'and the difference between them is gone',
            'aliases' => [],
        ],
        'attribute_summary' => [
            'why' => 'these are the CUSTOM (non-taxonomy) attributes typed on the product itself, and there is '
                .'no table for them -- attributes.csv carries only the pa_* taxonomy ones, so nothing else in '
                .'this import covers these',
            'aliases' => [],
        ],
        'date_modified' => [
            'why' => "this shop stamps its own updated_at when it writes the row, so WooCommerce's last-edited "
                .'date is replaced rather than kept',
            'aliases' => ['date_modified_gmt'],
        ],
        'sold_individually' => [
            'why' => 'there is no one-per-order limit on a product here, so a product the owner had capped at '
                .'one can be added to a basket ten times',
            'aliases' => [],
        ],
        'reviews_enabled' => [
            'why' => 'reviews are not switched on and off per product here, so a product whose reviews the '
                .'owner had turned OFF arrives with them on',
            'aliases' => ['comment_status'],
        ],
        /*
         * "Default Form Values" on the Variations tab -- WHICH SIZE the product
         * page opens on.
         *
         * `product_variants` has no default flag and nothing in the storefront
         * preselects a variant, so this has no column and no behaviour to land
         * in. What makes it worth naming rather than ignoring is that a variable
         * product is the one kind this shop sells where the visitor has to make
         * a choice before the Add to basket button means anything: the old shop
         * opened on 50ml and the new one opens on nothing, which is a step the
         * shopper now has to take on every visit.
         *
         * FOUND THE SAME WAY sold_individually AND reviews_enabled WERE, and it
         * is the third of that kind: on his edit page, and in no file at all. It
         * hid behind something worse than an absence -- `_default_attributes`
         * was already in the exporter's META_KEYS, fetched on every batch and
         * emitted by nothing, so the list this project offers as its account of
         * what it reads out of WooCommerce named it while no column carried it.
         */
        'default_attributes' => [
            'why' => 'there is no default variant on product_variants and nothing preselects one, so a '
                .'variable product that opened on 50ml in WooCommerce opens on no size here',
            'aliases' => ['default_attribute'],
        ],
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

        /*
         * A NEGATIVE PRICE IS REFUSED, BY NAME. `Money::fils()` permits negatives
         * on purpose -- refunds and order totals need them -- so nothing upstream
         * stops `-5.00` becoming `price = -500`, and the storefront would print
         * and CHARGE a negative figure: a basket that pays the shopper. Refused
         * here rather than clamped to zero, because zero is also a price the shop
         * would sell at, and the owner can only fix what the report names. Same
         * rule, same wording, as CouponImporter's negative coupon_amount.
         */
        $columns = [
            'regular_price' => [$price, ['regular_price', 'price']],
            'sale_price' => [$salePrice, ['sale_price']],
        ];

        foreach ($columns as $field => [$fils, $aliases]) {
            if ($fils !== null && $fils < 0) {
                throw RowRejected::because(
                    $field." '".(string) $row->raw(...$aliases)."' is negative. A negative price would be printed and charged as a payment to the shopper, "
                    .'so the row is refused rather than imported -- correct it in WooCommerce and export again'
                );
            }
        }

        $status = $this->mapStatus($row);
        $stockStatus = $this->mapStockStatus($row);

        $this->reportSku($row, $wcId, $name, $context);
        $this->reportFils($row, $context, $price, $salePrice);
        $this->reportNotCarried($row, $wcId, $context);
        $this->reportCarriedElsewhere($row, $context);

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
            //
            // AND THEN THE OLD-SITE LINKS THIS SHOP ALREADY RE-POINTED ON THIS
            // ROW, re-pointed the same way (Lane PT): the export carries
            // `https://kbeautybliss.com/...` and OldSiteLinks rewrote it after
            // the last import, so without the replay every product with such a
            // link would read "updated" on every pass. See OldSiteLinks::replay().
            'short_description' => ($this->links ??= new OldSiteLinks)->replay('products', $product->id, 'short_description', $this->cleanHtmlReported($row->text('short_description', 'post_excerpt'), 'short_description', $row, $context)),
            'description' => $this->links->replay('products', $product->id, 'description', $this->cleanHtmlReported($row->text('description', 'post_content'), 'description', $row, $context)),
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

        $keptSet = $this->keepLocalSet($product, $attributes, $row, $context);

        // Read BEFORE the row is written, so a malformed cell is reported
        // against this row whatever happens to the product itself.
        $tabs = $this->tabsFrom($row, $context);

        $outcome = $context->apply($product, $attributes);

        if ($keptSet && $this->reanchorAfterImport($product)) {
            $outcome = $outcome === 'unchanged' ? 'updated' : $outcome;
        }

        $context->record($this->name(), $outcome);
        $context->remember($this->name(), $wcId, (int) $product->id);

        $pivotChanged = $this->syncCategories((int) $product->id, $categoryIds);

        if ($tabs !== null && $this->syncTabs((int) $product->id, $tabs)) {
            $pivotChanged = true;
        }

        // A product whose own columns did not move but whose category
        // membership -- or whose tabs -- did IS an update, and reporting it as
        // unchanged would make the idempotency evidence a lie.
        if ($pivotChanged && $outcome === 'unchanged') {
            $report = $context->report->for($this->name());
            $report->unchanged--;
            $report->updated();
        }
    }

    /**
     * A product the owner turned into a set HERE stays a set. (Lane PI-A, item 10)
     *
     * WHAT IT PREVENTS. The owner's old shop sold its gift sets as ordinary
     * products, and he is converting them to real sets on the product editor.
     * WooCommerce still calls them `simple`, and this importer wrote
     * `'type' => mapType($row)` on every run -- so the re-import he has to run
     * anyway (exporter 1.9.0 carries the tabs) would have turned every one of
     * them back into a simple product, silently, with its box still in the
     * pivot and gone from every page. Measured before this guard: a converted
     * set re-imported from the same export came back `type = simple`.
     *
     * THE RULE, AND WHY IT IS THIS ONE. A local set is never downgraded by an
     * import, whatever the export calls it -- `simple`, `variable`, anything.
     * A set is something only this shop can make (WooCommerce has no such
     * type), so an export can never be the newer word on it. Reported as an
     * adjustment against the row, with what the export said, so the owner sees
     * every product this kept rather than discovering it.
     *
     * AND ITS PRICE IS THE SET'S. A set priced by a rule (a percentage or an
     * amount off its box) has a derived price and no sale price -- see
     * App\Support\SetPricing -- so the export's two figures are not written
     * over it; writing them would put a second answer to "what does this set
     * cost" back on the row. A set priced by hand keeps taking its typed
     * figures from the export like any product, and reanchorAfterImport()
     * re-takes its anchor when they move, exactly as typing a new price on the
     * editor does.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function keepLocalSet(Product $product, array &$attributes, Row $row, ImportContext $context): bool
    {
        if (! $product->exists || ! $product->isSet()) {
            return false;
        }

        $exported = (string) $attributes['type'];
        $attributes['type'] = 'set';

        if ($exported !== 'set') {
            $context->report->for($this->name())->adjusted(
                'kept as a set -- this product was turned into a set on this shop, and an import never turns a '
                .'set back into an ordinary product',
                $row->line,
                $this->identify($row),
                'type',
                $exported,
                'set',
            );
        }

        if (\App\Support\SetPricing::mode($product) !== \App\Support\SetPricing::MODE_FIXED) {
            unset($attributes['price'], $attributes['sale_price'], $attributes['sale_starts_at'], $attributes['sale_ends_at']);
        }

        return true;
    }

    /**
     * Re-take a hand-priced set's anchor when the import moved its typed price.
     * The editor does the same when a new price is typed; without it the new
     * figure would be marked down at once by whatever the box had fallen since
     * the old one was typed. Answers whether anything was written.
     */
    private function reanchorAfterImport(Product $product): bool
    {
        if (\App\Support\SetPricing::mode($product) !== \App\Support\SetPricing::MODE_FIXED
            || ! ($product->wasChanged('price') || $product->wasChanged('sale_price'))) {
            return false;
        }

        \App\Support\SetPricing::forget((int) $product->id);
        $product->unsetRelation('setItems');
        $product->set_price_basis = \App\Support\SetPricing::partsTotal($product);
        \App\Support\SetPricing::forget((int) $product->id);

        if (! $product->isDirty('set_price_basis')) {
            return false;
        }

        $product->save();

        return true;
    }

    /**
     * The extra tabs the old product page showed, ready to store. (Lane PI-A)
     *
     * WHAT THE OWNER SAW. His WordPress product page had "Description" AND
     * "Major Ingredients"; the imported one had Description alone. Those tabs
     * live in a tab plugin's post meta, which the exporter did not read until
     * 1.9.0 -- it now writes them to `custom_tabs` as a JSON list of
     * {title, content}, and this is the reader.
     *
     * NULL MEANS "LEAVE THIS PRODUCT'S IMPORTED TABS ALONE": the column is
     * absent (an export from a build before 1.9.0) or unreadable. An empty list
     * means the old shop has none, and a re-import removes the ones an earlier
     * import wrote. The difference is the whole of the idempotency story -- an
     * old export re-run must not wipe tabs a newer one brought across.
     *
     * The content is THIRD-PARTY HTML and goes through the same render path as
     * a description: RichText::forDisplay(), the old shop's paragraph rules and
     * then the allowlist, last. It is stored already laid out, because a tab
     * row is printed with {!! !!} exactly as the owner's own tabs are. What the
     * allowlist removed is reported against the row, the way a description's
     * removals are.
     *
     * @return list<array{title: string, body: string}>|null
     */
    private function tabsFrom(Row $row, ImportContext $context): ?array
    {
        if (! $row->has('custom_tabs')) {
            return null;
        }

        $raw = $row->text('custom_tabs');

        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            $context->report->for($this->name())->note(
                'custom_tabs on '.$this->identify($row).' is not a JSON list of tabs; this product\'s tabs were left as they were'
            );

            return null;
        }

        $tabs = [];

        foreach ($decoded as $tab) {
            if (! is_array($tab) || ! is_string($tab['title'] ?? null) || ! is_string($tab['content'] ?? null)) {
                continue;
            }

            // A title is printed ESCAPED, into a button and an accordion
            // heading, so it is text: entities decoded once, tags and runs of
            // whitespace gone, and cut to the column's width.
            $title = html_entity_decode(strip_tags($tab['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = Str::limit(trim((string) preg_replace('/\s+/u', ' ', $title)), 120, '');

            $this->cleanHtmlReported($tab['content'], 'custom_tabs', $row, $context);
            $body = RichText::forDisplay($tab['content']);

            // ProductTabs' own drop rule, so a tab stored here is never one the
            // page would refuse to draw.
            if ($title === '' || trim(strip_tags($body)) === '') {
                continue;
            }

            $tabs[] = ['title' => $title, 'body' => $body];

            if (count($tabs) >= ProductTabs::IMPORTED_MAX) {
                break;
            }
        }

        return $tabs;
    }

    /**
     * Make this product's imported tabs exactly $tabs, touching nothing else.
     *
     * Rows are found by `import_key` (`wc:1`, `wc:2`, ... in the order the
     * old page drew them), never by title, so a tab the owner wrote himself in
     * Catalog -> Product tabs is invisible to this and a renamed imported tab
     * is still the same row. Title and body follow the export on every run;
     * the position and the on/off switch are set when the row is created and
     * then belong to the owner.
     *
     * @param  list<array{title: string, body: string}>  $tabs
     * @return bool whether anything actually moved
     */
    private function syncTabs(int $productId, array $tabs): bool
    {
        $existing = ProductTab::query()
            ->where('product_id', $productId)
            ->whereNotNull('import_key')
            ->get()
            ->keyBy('import_key');

        $changed = false;
        $keep = [];

        foreach ($tabs as $i => $tab) {
            $key = 'wc:'.($i + 1);
            $keep[$key] = true;

            $model = $existing->get($key);

            if ($model === null) {
                $model = (new ProductTab)->forceFill([
                    'product_id' => $productId,
                    'source_key' => null,
                    'import_key' => $key,
                    'position' => ProductTabs::IMPORTED_PRODUCT_POSITION + $i,
                    'is_enabled' => true,
                ]);
            }

            $model->forceFill(['title' => $tab['title'], 'body' => $tab['body']]);

            if (! $model->exists || $model->isDirty()) {
                $model->save();
                $changed = true;
            }
        }

        foreach ($existing as $key => $model) {
            if (! isset($keep[$key])) {
                $model->delete();
                $changed = true;
            }
        }

        return $changed;
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
     * Name every field of this row that the shop has nowhere to put.
     *
     * READ UNCONDITIONALLY, REPORTED ONLY WHEN IT HELD SOMETHING, and the two
     * halves do different jobs. The read is what tells ImportRunner this column
     * is accounted for, and it has to happen even for a column that is empty on
     * every row -- otherwise an always-empty `weight` stays in the consolidated
     * "no field reads this" line and the owner is told twice about the same
     * column, once vaguely. The report is what he acts on, and a column that
     * was empty everywhere cost him nothing, so it is not counted as a loss.
     *
     * A ROW THAT IS REFUSED NEVER GETS HERE, because it throws further up. That
     * is correct and not a hole: Row::readKeys() is unioned across the whole
     * entity by the runner, so one importable row is enough to account for the
     * column, and an export where every single row is refused genuinely has no
     * evidence about what its columns hold.
     */
    private function reportNotCarried(Row $row, int $wcId, ImportContext $context): void
    {
        $report = $context->report->for($this->name());

        foreach (self::NOT_CARRIED as $field => $spec) {
            $value = $row->text($field, ...$spec['aliases']);

            if ($value === null) {
                continue;
            }

            $report->droppedField(
                $field.' is in this export and this shop has nowhere to put it -- '.$spec['why'],
                $row->line,
                (string) $wcId,
                $field,
                $value,
            );
        }
    }

    /**
     * The one column on this row that looks lost and is not.
     *
     * `tag_term_ids` is the product side of the product-to-tag pivot, and
     * TagImporter writes that pivot from the TAG side out of tags.csv
     * `product_ids`. The membership therefore arrives in full whether or not
     * this column is ever read -- so it is redundant, not dropped, and putting
     * it in the discard channel beside `weight` told the owner he was losing his
     * tags when he was not.
     *
     * note() and NOT droppedField(): nothing is being discarded, so it must not
     * move the "fields skipped" count, and the fact is the same sentence on
     * every row, which is exactly what a counted note is for. Read here so the
     * runner's consolidated line stops naming it as well.
     *
     * ▲ BUT ONLY WHEN tags.csv IS ACTUALLY IN THIS RUN, and that caveat is the
     * whole reason this method asks. "Nothing is lost, the tags arrive from
     * tags.csv" is a statement about the OTHER FILE, and it is simply false for
     * an import of products alone -- `--only=products`, or an export folder that
     * has no tags.csv in it. In that run the membership arrives from nowhere and
     * the column really is a loss.
     *
     * Reassurance that does not check its own premise is worse than no
     * reassurance, because the owner stops looking. So the premise is checked,
     * and when it does not hold the column is reported as the drop it is.
     */
    private function reportCarriedElsewhere(Row $row, ImportContext $context): void
    {
        $value = $row->text('tag_term_ids', 'tag_ids', 'tags');

        if ($value === null) {
            return;
        }

        $report = $context->report->for($this->name());

        /*
         * THE PREMISE IS "THIS EXPORT HAS A tags.csv", not "this slice is about
         * to read it", and the difference bit once already. ImportDriver steps
         * ONE ENTITY AT A TIME, so `only` is ['products'] for the whole products
         * step of an ordinary background run -- and asking options->wants('tags')
         * there answers false on a run that imports the tags perfectly well two
         * steps later. Every product in the owner's catalogue would have been
         * reported as losing its tags.
         *
         * File presence is the honest test: the claim being made is about the
         * export, not about which slice happens to be running.
         */
        $tagsAreComing = $context->options->fileFor('tags', 'tags.csv') !== null;

        if (! $tagsAreComing) {
            $report->droppedField(
                'tag_term_ids is in this export and nothing in THIS run reads it -- the product-to-tag '
                .'membership normally arrives from tags.csv `product_ids`, and this run has no tags.csv, '
                .'so these tags reach no table',
                $row->line,
                $this->identify($row),
                'tag_term_ids',
                $value,
            );

            return;
        }

        $report->note(
            'tag_term_ids is not read from the product row and nothing is lost by that -- the same '
            .'product-to-tag membership arrives from tags.csv `product_ids`, which IS read. Counted here so '
            .'the export and the database can be reconciled on it.'
        );
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
