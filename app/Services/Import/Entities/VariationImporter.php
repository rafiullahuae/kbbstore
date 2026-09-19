<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\ProductVariant;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Support\Money as DisplayMoney;
use Illuminate\Support\Facades\DB;

/**
 * WooCommerce `product_variation` posts — the sizes and shades a variable
 * product is actually sold in.
 *
 * `docs/FV-IMPORT-AT-VOLUME.md` §11 calls this "the largest missing entity by
 * revenue", and states the failure exactly: "A variable product imports as its
 * parent only. The shop then sells '50ml or 100ml' as one price."
 *
 * ── WHAT THE SCHEMA ALREADY HAD, WHICH IS ALMOST ALL OF IT ──────────────────
 *
 * `product_variants` has carried `price`, `sale_price`, `sku`, `stock`,
 * `stock_status`, `manage_stock`, `image` and `position` since the ORIGINAL
 * schema migration, and `wc_id` has been unique since
 * 2026_09_22_000000_add_import_external_ids named it. App\Support\Seo has
 * published an AggregateOffer over two or more differently-priced variants
 * since Lane FX, and `store/product.blade.php` has printed a price on every
 * option row all along. None of it had ever seen a variant, because nothing
 * imported one. This importer adds no column to that table: it was already
 * finished and waiting, which is the fifth time this month the answer to "is
 * this blocked" was no.
 *
 * ── THE SALE WINDOW IS THE PARENT'S, AND THE EXPORT CARRIES THE VARIANT'S ───
 *
 * `variations.csv` has `sale_starts_at` and `sale_ends_at` per variation, and
 * this table has neither. ProductVariant::effectivePrice() is explicit about
 * why: "a variable product's markdown is scheduled once, on its `products` row,
 * for every variant under it." So a variation carrying its own window is a
 * genuine loss and never a silent one — it is reported per row, with both dates
 * quoted, because the direction of the loss is money: a variation whose sale
 * ended in 2022, under a parent with no window at all, sells at its sale price
 * for ever.
 *
 * ── A DISABLED SIZE MUST NOT COME BACK ON SALE ──────────────────────────────
 *
 * `status` is the variation's own `post_status`, and WooCommerce disables a
 * single size by setting it to `private`. `product_variants` has no status
 * column, so there is nothing to copy it into — and the honest reading of that
 * is not "ignore it", it is "express it in the column that exists". A
 * non-publish variation is imported with `stock_status = outofstock`, which is
 * the one value every door in this application already refuses:
 * Store\CartController::add() and Store\CheckoutController::browsedAdd() both
 * test `($variant?->stock_status ?? $product->stock_status) !== 'instock'`, and
 * App\Services\StockClaim::claim() tests it again inside the placing
 * transaction, so a withdrawn size cannot be added to a basket and cannot be
 * paid for even from a basket that predates the import.
 *
 * WHAT THAT COSTS, STATED SO THE OWNER CAN OVERRULE IT: the option is still
 * drawn on the product page, greyed, tagged "Sold out" — which is not the same
 * sentence as "withdrawn" — and its price is still inside the AggregateOffer's
 * range, marked OutOfStock. Both are what the page and the document would say
 * about any sold-out size, and this shop's own rule is that the structured data
 * states what the page states. Hiding it instead needs a `status` column AND a
 * reader in three places, which is a storefront change rather than an import
 * one; docs/GH-VARIATIONS-AND-ATTRIBUTES.md §11 names it for the owner to
 * settle. `trash` is refused outright, exactly as ProductImporter refuses a
 * trashed product.
 */
final class VariationImporter extends EntityImporter
{
    /**
     * Woo post status => whether this variation is on sale at all.
     *
     * A map rather than `=== 'publish'` so an unknown status is a rejection and
     * not a guess. ProductImporter's own note applies here word for word:
     * guessing wrong in the unsafe direction publishes something the owner had
     * unpublished.
     *
     * @var array<string, bool>
     */
    private const SELLABLE = [
        'publish' => true,
        'published' => true,
        'private' => false,
        'draft' => false,
        'pending' => false,
        'future' => false,
    ];

    /** @var array<string, string> Same map, and the same reasons, as ProductImporter's. */
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
     * "<attribute slug>/<value slug>" => [value id, attribute id], or null when
     * this export's attributes.csv does not carry that term.
     *
     * RESOLVED FROM THE DATABASE AND NOT FROM THE RUN'S ID MAPS, which is not a
     * style choice. Store → Import steps ONE ENTITY PER HTTP REQUEST, so
     * attributes and variations are imported by two different PHP processes
     * with two different ImportContexts; a lookup through $context->localId()
     * would find everything from the console and nothing from the screen the
     * owner actually uses. Per-instance, so it cannot outlive the run.
     *
     * @var array<string, array{0: int, 1: int}|null>
     */
    private array $values = [];

    /**
     * Attributes already marked as a variation axis this run, so the flag costs
     * one UPDATE per attribute rather than one per variation.
     *
     * @var array<int, true>
     */
    private array $axes = [];

    public function name(): string
    {
        return 'variations';
    }

    public function conventionalFile(): string
    {
        return 'variations.csv';
    }

    /** Variants the export supplied, matched on the WooCommerce variation post id. */
    public function countImported(): ?int
    {
        return ProductVariant::query()->whereNotNull('wc_id')->count();
    }

    public function import(Row $row, ImportContext $context): void
    {
        $wcId = $row->requireId('id', 'id', 'variation_id', 'wc_id');
        $parentWcId = $row->requireId('parent_id', 'parent_id', 'parent', 'product_id', 'post_parent');

        $productId = $context->localId('products', $parentWcId);

        if ($productId === null) {
            /*
             * `product_variants.product_id` is NOT NULL with cascadeOnDelete,
             * so there is nothing to attach this to. Refused rather than
             * skipped, because a refusal is a line in a report the owner reads
             * and a skip is not — and the commonest cause is knowable from the
             * message: the parent was in the WordPress trash, which
             * ProductImporter refuses by name, so the sizes under it follow it
             * out.
             */
            throw RowRejected::because(
                'parent product '.$parentWcId.' is not in this database — import products first, or check '
                .'whether that product was refused (a trashed parent takes its variations with it). '
                .'product_variants.product_id is NOT NULL, so this variation has nothing to attach to.'
            );
        }

        $sellable = $this->sellable($row);

        $price = $row->money('regular_price', 'regular_price', 'price');
        $salePrice = $row->money('sale_price', 'sale_price');

        if ($price === null && $salePrice !== null) {
            throw RowRejected::because(
                'sale_price is set but regular_price is empty — a variant prices from its regular price '
                .'and has no way to express a sale from nothing'
            );
        }

        $stockStatus = $this->mapStockStatus($row);

        if (! $sellable) {
            $context->report->for($this->name())->adjusted(
                'a variation WooCommerce had disabled — this schema has no status column for a variant, so '
                .'it is imported out of stock, which is the one value every add-to-basket path and '
                .'StockClaim::claim() already refuse. It is still drawn on the product page, greyed and '
                .'tagged "Sold out"',
                $row->line,
                $this->identify($row),
                'status',
                (string) $row->raw('status'),
                'stock_status = outofstock',
            );

            $stockStatus = 'outofstock';
        }

        $this->reportSaleWindow($row, $context);
        $this->reportFils($row, $context, $price, $salePrice);

        $variant = ProductVariant::query()->where('wc_id', $wcId)->first() ?? new ProductVariant;

        $outcome = $context->apply($variant, [
            'wc_id' => $wcId,
            'product_id' => $productId,
            'sku' => $row->text('sku'),
            'price' => $price,
            'sale_price' => $salePrice,
            'manage_stock' => $row->bool(false, 'manage_stock'),
            // Present-and-empty is preserved: NULL stock and 0 stock are
            // different rows, exactly as they are on a product.
            'stock' => $row->text('stock', 'stock_quantity') === null ? null : $row->int(0, 'stock', 'stock_quantity'),
            'stock_status' => $stockStatus,
            'image' => $row->text('image', 'thumbnail'),
            'position' => $row->int((int) ($variant->position ?? 0), 'position', 'menu_order'),
        ]);

        $context->record($this->name(), $outcome);
        $context->remember($this->name(), $wcId, (int) $variant->id);

        $pivotChanged = $this->syncAttributeValues($variant, $row, $context);

        // Same correction as ProductImporter's on category membership: a
        // variant whose own columns did not move but whose defining terms did
        // is an update, and "unchanged" is the evidence the second pass rests
        // on.
        if ($pivotChanged && $outcome === 'unchanged') {
            $report = $context->report->for($this->name());
            $report->unchanged--;
            $report->updated();
        }
    }

    /**
     * @throws RowRejected
     */
    private function sellable(Row $row): bool
    {
        $raw = $row->text('status', 'post_status');

        if ($raw === null) {
            return true;
        }

        $key = mb_strtolower(trim($raw));

        if ($key === 'trash' || $key === 'trashed') {
            throw RowRejected::because(
                "status 'trash' — this variation is in the WordPress trash. Importing it would make it a "
                .'live row; empty the trash or filter the export.'
            );
        }

        if (! isset(self::SELLABLE[$key])) {
            throw RowRejected::because(
                "status '".$raw."' is not one this importer knows (publish, private, draft, pending, future). "
                .'Guessing would risk putting a size back on sale that you had withdrawn.'
            );
        }

        return self::SELLABLE[$key];
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

    /**
     * A per-variation sale window, which this table cannot hold.
     *
     * READ SO THAT IT CAN BE REPORTED, rather than left to the runner's
     * consolidated discard line. That line names a column once for the whole
     * entity with one sample value, which is right for `weight` and `tax_class`
     * and wrong for this: which variations carry a window, and what the dates
     * were, is the difference between "nothing to do" and "three sizes are
     * about to sell at a 2022 discount for ever".
     */
    private function reportSaleWindow(Row $row, ImportContext $context): void
    {
        $from = $row->text('sale_starts_at', 'sale_starts_at', 'date_on_sale_from');
        $to = $row->text('sale_ends_at', 'sale_ends_at', 'date_on_sale_to');

        if ($from === null && $to === null) {
            return;
        }

        $context->report->for($this->name())->discarded(
            'a sale window of this variation\'s own — product_variants has no date columns, so '
            .'ProductVariant::effectivePrice() applies the PARENT product\'s window to every variant '
            .'under it. Where the parent has no window, this variation\'s sale_price applies with no '
            .'end date at all',
            $row->line,
            $this->identify($row),
            'sale_starts_at, sale_ends_at',
            ($from ?? '(none)').' — '.($to ?? '(none)'),
            "the parent product's window",
        );
    }

    /**
     * An amount the storefront cannot print, one level down from the product.
     *
     * The same fact ProductImporter::reportFils() names, and it bites harder
     * here: store/product.blade.php prints a variant's price with
     * Money::format($vsale, $vdp), and $vdp is only computed when the option is
     * ON SALE — so an off-sale option carrying fils is printed rounded with
     * nothing beside it to show the difference.
     */
    private function reportFils(Row $row, ImportContext $context, ?int $price, ?int $salePrice): void
    {
        if (DisplayMoney::displayDecimals() !== 0) {
            return;
        }

        foreach (['regular_price' => $price, 'sale_price' => $salePrice] as $field => $fils) {
            if ($fils === null || $fils % 100 === 0) {
                continue;
            }

            $context->report->for($this->name())->adjusted(
                'a variant price carrying fils in a shop that prints whole dirhams — it is stored and '
                .'charged exactly, and printed rounded, so the shopper is shown a price the shop does '
                .'not take',
                $row->line,
                $this->identify($row),
                $field,
                DisplayMoney::amount($fils, 2),
                DisplayMoney::amount($fils, 0).' (as printed)',
            );
        }
    }

    /**
     * The terms this variation is defined by: `attribute_pa_size=50ml|…`.
     *
     * THE CELL IS PIPE-SEPARATED AND THE VALUES ARE TERM SLUGS, both of which
     * are the exporter's stated contract rather than a guess — it replaces a
     * `|` inside a value with `/` before joining, so a split on `|` cannot tear
     * a value in half.
     *
     * AN EMPTY VALUE IS NOT MISSING DATA. WooCommerce writes
     * `attribute_pa_size=` on a variation that matches ANY size — the "Any
     * size" row in the variations screen — and this schema has no way to say
     * that: a variant is defined by the exact set of values it is pinned to.
     * Reported, and the pin is skipped, which leaves the variant defined by its
     * other axes. Refusing the row instead would lose a purchasable option over
     * something the shop can still sell.
     *
     * @return bool whether the pivot moved
     */
    private function syncAttributeValues(ProductVariant $variant, Row $row, ImportContext $context): bool
    {
        $valueIds = [];

        foreach ($row->list('|', 'attributes', 'variation_attributes') as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);

            $key = trim($key);
            $value = trim($value);

            // `attribute_pa_size` -> `pa_size` -> `size`, which is the slug
            // AttributeImporter writes. Both prefixes are stripped in that
            // order because a LOCAL attribute is `attribute_scent` with no
            // `pa_` at all.
            $attributeSlug = preg_replace('/^attribute_/', '', $key) ?? $key;
            $attributeSlug = preg_replace('/^pa_/', '', $attributeSlug) ?? $attributeSlug;

            if ($attributeSlug === '') {
                continue;
            }

            if ($value === '') {
                $context->report->for($this->name())->discarded(
                    'a variation axis set to "any" — WooCommerce lets one variation stand for every value '
                    .'of an attribute, and this schema defines a variant by the exact terms it is pinned '
                    .'to. The variant is imported without this axis',
                    $row->line,
                    $this->identify($row),
                    $key,
                    '(any '.$attributeSlug.')',
                    'the axis is not recorded for this variant',
                );

                continue;
            }

            $found = $this->lookup($attributeSlug, $value);

            if ($found === null) {
                /*
                 * The commonest cause is a LOCAL attribute, and it is a gap in
                 * the export rather than in this importer: `attributes.csv` is
                 * "every `pa_*` term that is not a brand", and a variation axis
                 * defined per product — WooCommerce's "custom product
                 * attribute" — has no term and no taxonomy, so no file carries
                 * it. Named rather than invented: creating an attribute nothing
                 * described would put an axis on the Attributes screen that
                 * WooCommerce never had, and the owner cannot approve a loss
                 * they were not shown.
                 */
                $context->report->for($this->name())->discarded(
                    'a variation axis this export does not describe — attributes.csv carries the global '
                    .'`pa_*` terms, and a per-product ("custom") attribute has no term and appears in no '
                    .'file. The variant imports without it, so its option label is built from the axes '
                    .'that did resolve',
                    $row->line,
                    $this->identify($row),
                    $key,
                    $attributeSlug.' = '.$value,
                    'no attribute_values row to pin it to',
                );

                continue;
            }

            [$valueId, $attributeId] = $found;

            $valueIds[] = $valueId;

            $this->markAxis($attributeId);
        }

        return $this->syncPivot((int) $variant->id, array_values(array_unique($valueIds)));
    }

    /**
     * @return array{0: int, 1: int}|null [attribute_value id, attribute id]
     */
    private function lookup(string $attributeSlug, string $valueSlug): ?array
    {
        $key = $attributeSlug.'/'.$valueSlug;

        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }

        $found = DB::table('attribute_values')
            ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->where('attributes.slug', $attributeSlug)
            ->where('attribute_values.slug', $valueSlug)
            ->first(['attribute_values.id as value_id', 'attributes.id as attribute_id']);

        return $this->values[$key] = $found === null
            ? null
            : [(int) $found->value_id, (int) $found->attribute_id];
    }

    /**
     * Mark the attribute this variation is built on as a variation axis.
     *
     * THIS IS THE ONE PLACE THE FACT IS KNOWN. `attributes.csv` cannot say it:
     * in WooCommerce "used for variations" is a per-product tick stored on the
     * product, not a property of the global attribute, and the only durable
     * evidence that an attribute is used that way is a variation pinned to one
     * of its terms. AttributeImporter deliberately leaves the flag at its
     * schema default so this can set it.
     *
     * Written only when it is false, so a second pass reports the variant
     * unchanged rather than churning `attributes.updated_at` on every run.
     */
    private function markAxis(int $attributeId): void
    {
        if (isset($this->axes[$attributeId])) {
            return;
        }

        $this->axes[$attributeId] = true;

        DB::table('attributes')
            ->where('id', $attributeId)
            ->where('is_variation_axis', false)
            ->update(['is_variation_axis' => true]);
    }

    /**
     * @param  list<int>  $valueIds
     * @return bool whether anything actually moved
     */
    private function syncPivot(int $variantId, array $valueIds): bool
    {
        $existing = DB::table('product_variant_attribute_value')
            ->where('product_variant_id', $variantId)
            ->pluck('attribute_value_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $toAdd = array_diff($valueIds, $existing);
        $toRemove = array_diff($existing, $valueIds);

        if ($toAdd === [] && $toRemove === []) {
            return false;
        }

        if ($toAdd !== []) {
            DB::table('product_variant_attribute_value')->insert(array_map(
                static fn (int $valueId): array => [
                    'product_variant_id' => $variantId,
                    'attribute_value_id' => $valueId,
                ],
                array_values($toAdd),
            ));
        }

        if ($toRemove !== []) {
            DB::table('product_variant_attribute_value')
                ->where('product_variant_id', $variantId)
                ->whereIn('attribute_value_id', array_values($toRemove))
                ->delete();
        }

        return true;
    }
}
