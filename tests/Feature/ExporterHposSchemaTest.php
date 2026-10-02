<?php

/**
 * The exporter reads only columns WooCommerce's HPOS tables really have, and a
 * refused order query stops the export instead of writing an empty file.
 *
 * THE DEFECT, ON THE OWNER'S SHOP (2 October 2026). His Orders export
 * (kbb-export-sales) held orders.csv at 751 bytes -- the header row and
 * nothing else -- and an empty refunds.csv, beside 13,098 order lines and
 * 29,786 order notes. Imported, every order line and note was refused and the
 * new shop showed 0 orders. The manifest said "Orders were read from HPOS".
 *
 * KBB_Export_Orders_Source::batch_hpos() selected `d.cart_tax_amount`.
 * WooCommerce's wc_order_operational_data has no such column (its CREATE TABLE
 * in OrdersTableDataStore.php, read from woocommerce/woocommerce trunk on
 * 2 October 2026, is pinned below). Only the harness had invented it, so every
 * test passed; on the real shop MySQL refused the query, $wpdb returned null,
 * and an empty batch reads as "no more orders".
 *
 * MUTATIONS, RUN:
 *   - put `, d.cart_tax_amount` back in batch_hpos(): the second case is red
 *     here, and the HPOS export in GeWpExporterTest fails with "Reading orders
 *     from wc_orders failed" instead of silently writing no orders;
 *   - put `cart_tax_amount` back into the harness's CREATE TABLE: the first
 *     case is red.
 */

/** WooCommerce's own HPOS columns, copied from OrdersTableDataStore::get_database_schema(). */
function hposRealColumns(): array
{
    return [
        'wc_orders' => ['id', 'status', 'currency', 'type', 'tax_amount', 'total_amount', 'customer_id',
            'billing_email', 'date_created_gmt', 'date_updated_gmt', 'parent_order_id', 'payment_method',
            'payment_method_title', 'transaction_id', 'ip_address', 'user_agent', 'customer_note'],
        'wc_order_addresses' => ['id', 'order_id', 'address_type', 'first_name', 'last_name', 'company',
            'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'],
        'wc_order_operational_data' => ['id', 'order_id', 'created_via', 'woocommerce_version',
            'prices_include_tax', 'coupon_usages_are_counted', 'download_permission_granted', 'cart_hash',
            'new_order_email_sent', 'order_key', 'order_stock_reduced', 'date_paid_gmt', 'date_completed_gmt',
            'shipping_tax_amount', 'shipping_total_amount', 'discount_tax_amount', 'discount_total_amount',
            'recorded_sales'],
        'wc_orders_meta' => ['id', 'order_id', 'meta_key', 'meta_value'],
    ];
}

it('builds the harness HPOS tables with no column WooCommerce does not have', function () {
    $shop = (string) file_get_contents(base_path('wordpress-plugin/harness/shop.php'));

    foreach (hposRealColumns() as $table => $columns) {
        $at = strpos($shop, '{$p}'.$table.'"');
        expect($at)->not->toBeFalse("harness does not create {$table}");

        // Up to this table's own closing bracket, whichever quote ends it.
        $ends = array_filter([strpos($shop, ')",', $at), strpos($shop, ")',", $at)], fn ($x) => $x !== false);
        $body = substr($shop, $at, min($ends) - $at);
        preg_match_all('/^\s*([a-z_]+)\s+(?:BIGINT|VARCHAR|DECIMAL|DATETIME|TINYINT|TEXT|LONGTEXT|INT|CHAR)/mi', $body, $m);

        $invented = array_values(array_diff($m[1], $columns));
        expect($invented)->toBe([], "the harness invents {$table} column(s) WooCommerce does not have: ".implode(', ', $invented));
    }
});

it('selects only real HPOS columns, and stops on a refused order query', function () {
    $src = (string) file_get_contents(base_path('wordpress-plugin/kbb-exporter/includes/class-kbb-export-orders-source.php'));
    $real = hposRealColumns();
    $alias = ['o' => 'wc_orders', 'd' => 'wc_order_operational_data'];

    preg_match_all('/\b([od])\.([a-z_]+)\b/', $src, $m, PREG_SET_ORDER);
    foreach ($m as [, $a, $column]) {
        expect(in_array($column, $real[$alias[$a]], true))->toBeTrue("{$alias[$a]} has no column {$column}");
    }

    // Every order query is followed by the guard that turns a refused query
    // into the export's error.
    foreach (['orders from wc_orders', 'order addresses from wc_order_addresses', 'order meta from wc_orders_meta', 'orders from wp_posts'] as $what) {
        expect($src)->toContain("self::guard( '{$what}' );");
    }
    expect($src)->toContain("throw new RuntimeException( 'Reading ' . \$what . ' failed: ' . \$wpdb->last_error );");
});
