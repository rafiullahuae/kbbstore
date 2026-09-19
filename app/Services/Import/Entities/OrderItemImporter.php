<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;

/**
 * Order line items, matched on `wc_item_id`.
 *
 * WHY THE EXTERNAL ID MATTERS MORE HERE THAN ANYWHERE ELSE, even though the
 * rows look like the least interesting in the import. WooCommerce keeps line
 * items in woocommerce_order_items with an order_item_id primary key, and until
 * this repo's external-id migration there was no column to put it in. Without
 * it, a second pass gives every order a SECOND set of lines: item revenue
 * doubles while the order total, which lives on the order row and is matched on
 * wc_order_id, stays exactly right.
 *
 * That is the worst possible failure shape. It is not obviously broken — no
 * error, no duplicate order, the order page shows a total that agrees with the
 * customer's receipt — it is quietly inconsistent, and it is found weeks later
 * when a product-level sales report disagrees with the revenue report by a
 * factor that varies per order depending on how many passes touched it.
 *
 * NAME IS A SNAPSHOT AND IS NOT NULL. `order_items.name` exists so the order
 * still reads correctly after the product is renamed or deleted; a line with no
 * name is rejected rather than backfilled from the product, because a line whose
 * name came from today's catalogue is not a record of what was bought.
 *
 * THE PRODUCT LINK IS OPTIONAL AND THE LINE IS NOT. `product_id` is a nullable
 * FK with nullOnDelete precisely because a five-year-old order can reference a
 * product that no longer exists. A line whose product is not in the import is
 * imported with a null product_id and NOTED — never rejected, because refusing
 * it would remove real money from the order's own history.
 */
final class OrderItemImporter extends EntityImporter
{
    /**
     * variant id => its attribute value names, for this run.
     *
     * ONE QUERY PER VARIANT AND NOT ONE PER LINE. A shop with 10,571 line items
     * and a few hundred variants would otherwise pay a query and an eager load
     * for every line of every variable product, on top of the 21,199 this
     * bucket already costs at volume. The map is bounded by the number of
     * variants, not by the number of orders, and an importer instance lives for
     * exactly one run.
     *
     * @var array<int, ?list<string>>
     */
    private array $variantAttributes = [];

    public function name(): string
    {
        return 'order-items';
    }

    public function conventionalFile(): string
    {
        return 'order_items.csv';
    }

    /** Line items the export supplied, matched on the WooCommerce order_item_id. */
    public function countImported(): ?int
    {
        return OrderItem::query()->whereNotNull('wc_item_id')->count();
    }

    public function import(Row $row, ImportContext $context): void
    {
        $itemId = $row->requireId('item_id', 'item_id', 'order_item_id', 'id');
        $wcOrderId = $row->requireId('order_id', 'order_id', 'wc_order_id');

        $orderId = $context->localId('orders', $wcOrderId);

        if ($orderId === null) {
            throw RowRejected::because(
                'order '.$wcOrderId.' is not in this database — import the orders first, or check whether that '
                .'order was rejected (order_items.order_id is NOT NULL, so this line has nothing to attach to)'
            );
        }

        $name = $row->text('name', 'item_name', 'product_name');

        if ($name === null) {
            throw RowRejected::because(
                'name is empty. It is a snapshot of what was bought, kept so the order still reads correctly '
                .'after the product is renamed or deleted, and it is NOT NULL.'
            );
        }

        $productId = null;
        $wcProductId = $row->id('product_id', 'product_id', 'wc_product_id');

        if ($wcProductId !== null) {
            $productId = $context->localId('products', $wcProductId);

            if ($productId === null) {
                $context->report->for($this->name())->note(
                    'the line references a product that is not in this import; imported with a null product_id, '
                    .'which is what the nullable FK is for — the line itself is still real money'
                );
            }
        }

        /*
         * ── WHICH SIZE WAS ACTUALLY SOLD ────────────────────────────────────
         *
         * `order_items.csv` carries `variation_id` beside `product_id` because
         * Lane GE's exporter refused to throw the link away: "the variation id
         * is carried in its own column so the link is not lost". It WAS lost,
         * on this side, for as long as nothing here had variants to link to --
         * and after Lane GH imported `variations.csv` into `product_variants`,
         * the column went on being read by nothing while both the row it points
         * at and the column it belongs in existed.
         *
         * What that costs is not abstract. `order_items.variant_attributes` is
         * what InvoiceDocument, OrderEmailPresenter and the shopper's own order
         * page print underneath the product name, so a line that came from a
         * variable product reads "Rice Cleanser" on the invoice, the email and
         * the order page, with nothing anywhere saying whether the customer was
         * sent the 50ml or the 100ml. The money is right; the record of what
         * was in the parcel is not.
         *
         * A MISSING VARIANT IS A NOTE, NEVER A REJECTION, for the same reason
         * `product_id` is: the line is real money and a five-year-old order can
         * name a size the shop has since deleted. Null is the honest answer and
         * the FK is nullable for it.
         *
         * Row::id() reads an EMPTY cell and a literal 0 as null, which is what
         * makes this safe on a simple product's line: WooCommerce writes 0
         * there and the exporter writes an empty cell, and neither is a variant.
         */
        $variantId = null;
        $variantAttributes = null;
        $wcVariationId = $row->id('variation_id', 'variation_id', 'wc_variation_id', 'variant_id');

        if ($wcVariationId !== null) {
            $variantId = $context->localId('variations', $wcVariationId);

            if ($variantId === null) {
                $context->report->for($this->name())->note(
                    'the line names a product variation that is not in this import; imported with a null '
                    .'product_variant_id, so the order still holds the money and the product but not which '
                    .'size or shade was sold -- import variations.csv before order_items.csv'
                );
            } else {
                /*
                 * The names, not the ids. `variant_attributes` is a snapshot
                 * for exactly the reason `name` is one: it has to keep reading
                 * "50ml" on a 2019 invoice after the attribute term is renamed
                 * or deleted. An empty list stays NULL rather than becoming
                 * `[]`, because every consumer tests `is_array(...)` and an
                 * empty array would print a stray separator.
                 */
                if (! array_key_exists($variantId, $this->variantAttributes)) {
                    $names = ProductVariant::query()
                        ->with('attributeValues')
                        ->find($variantId)
                        ?->attributeValues->pluck('name')->all() ?? [];

                    $this->variantAttributes[$variantId] = $names === [] ? null : $names;
                }

                $variantAttributes = $this->variantAttributes[$variantId];
            }
        }

        $report = $context->report->for($this->name());

        $rawQuantity = $row->int(1, 'quantity', 'qty', 'item_quantity');
        $quantity = max(0, $rawQuantity);

        /*
         * A NEGATIVE QUANTITY IS A REFUND LINE, and max(0, ...) turned it into
         * a line that says nothing was bought while its money column still says
         * minus fifty dirhams. WooCommerce writes refunds as line items with
         * negative quantities and negative totals against the original order,
         * so this is not an exotic case -- it is every refunded order in the
         * store, and this shop's own report shows 520 orders in `refunded`.
         *
         * The clamp is kept, because `order_items.quantity` is unsigned in the
         * Phase 0 schema and MySQL in strict mode would refuse the insert
         * outright. What was missing is the sentence: a line whose quantity the
         * import CHANGED from -1 to 0, while leaving the money alone, is a line
         * whose quantity and whose total now disagree, and only the owner can
         * say whether a refund belongs in this store's history at all.
         */
        if ($rawQuantity < 0) {
            $report->adjusted(
                'a negative quantity clamped to zero -- WooCommerce writes a refund as a line with a '
                .'negative quantity, and order_items.quantity is unsigned, so the quantity was changed '
                .'and the money was not: the two no longer agree',
                $row->line,
                $this->identify($row),
                'quantity',
                (string) $rawQuantity,
                '0',
            );
        }

        $subtotal = $row->money('subtotal', 'subtotal', 'item_subtotal', 'line_subtotal');
        $total = $row->money('total', 'total', 'item_total', 'line_total');

        // Woo exports carry the LINE total, not the unit price, far more often
        // than the reverse. Derived by integer division when it is absent, and
        // never by float arithmetic; a line that does not divide evenly keeps
        // the truncated unit price, because subtotal and total are the columns
        // every money figure is actually computed from and they are exact.
        $unitPrice = $row->money('unit_price', 'unit_price', 'price', 'item_price');

        if ($unitPrice === null) {
            $basis = $subtotal ?? $total;
            $unitPrice = ($basis !== null && $quantity > 0) ? intdiv($basis, $quantity) : 0;

            /*
             * intdiv() TRUNCATES, and three-for-AED-100 is not a rare shape in
             * a shop that runs bundle promotions. 10,000 fils over three units
             * is 3,333 fils each and 9,999 fils of line, so the unit price
             * printed on the order page multiplies back to a penny less than
             * the total printed beside it.
             *
             * The truncation stays -- subtotal and total are the exact columns
             * and every money figure is computed from them, which is the right
             * design. But the order page prints unit_price, and a customer
             * service call about "your own invoice does not add up" is a real
             * cost. Counted, so the owner knows how many lines it is true of
             * before they find out from a customer.
             */
            if ($basis !== null && $quantity > 0 && $unitPrice * $quantity !== $basis) {
                $report->adjusted(
                    'a unit price truncated by integer division -- the line total does not divide evenly '
                    .'by its quantity, so unit price x quantity is a fil or two short of the total printed '
                    .'beside it on the order page',
                    $row->line,
                    $this->identify($row),
                    'unit_price',
                    \App\Support\Money::amount($basis, 2).' over '.$quantity,
                    \App\Support\Money::amount($unitPrice, 2).' each ('
                        .\App\Support\Money::amount($unitPrice * $quantity, 2).' back)',
                );
            }
        }

        $item = OrderItem::query()->where('wc_item_id', $itemId)->first() ?? new OrderItem;

        $outcome = $context->apply($item, [
            'wc_item_id' => $itemId,
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'variant_attributes' => $variantAttributes,
            'name' => $name,
            'brand' => $row->text('brand'),
            'sku' => $row->text('sku'),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal ?? $total ?? 0,
            'total' => $total ?? $subtotal ?? 0,
            'tax_total' => $row->moneyOrZero('tax_total', 'tax_total', 'item_tax', 'line_tax'),
        ]);

        $context->record($this->name(), $outcome);
    }
}
