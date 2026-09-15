<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\OrderItem;
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
    public function name(): string
    {
        return 'order-items';
    }

    public function conventionalFile(): string
    {
        return 'order_items.csv';
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

        $quantity = max(0, $row->int(1, 'quantity', 'qty', 'item_quantity'));

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
        }

        $item = OrderItem::query()->where('wc_item_id', $itemId)->first() ?? new OrderItem;

        $outcome = $context->apply($item, [
            'wc_item_id' => $itemId,
            'order_id' => $orderId,
            'product_id' => $productId,
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
