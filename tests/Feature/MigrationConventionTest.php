<?php

/*
 * ->after() is how the checkout outage happened.
 *
 * A chain of migrations each added a column positioned after the column the
 * previous one was supposed to create. On MySQL, ALTER ... AFTER a column that
 * does not exist is an error, so one missing anchor took out the rest of the
 * chain -- and because each was wrapped in a Schema::hasColumn guard, the
 * failure looked like a clean no-op and the migration recorded as run. SQLite
 * ignores AFTER, so the suite stayed green while checkout could not take an
 * order.
 *
 * Column order in a table is cosmetic. It is not worth a class of outage that
 * only appears in production, so new migrations do not get to use it.
 */

it('has no migration positioning a column with after()', function () {
    $offenders = [];

    foreach (glob(base_path('database/migrations/*.php')) ?: [] as $file) {
        $source = (string) file_get_contents($file);

        // Comments discuss ->after() deliberately; only executable code counts.
        $code = preg_replace('#/\*.*?\*/#s', '', $source);
        $code = preg_replace('#^\s*//.*$#m', '', (string) $code);

        if (str_contains((string) $code, '->after(')) {
            $offenders[] = basename($file);
        }
    }

    // The historical ones are grandfathered: rewriting a migration that has
    // already run on the live database changes nothing there and risks more
    // than it fixes. The repair migration is what actually corrects those.
    $known = [
        '2026_08_28_190000_add_bundle_tag_to_variants.php',
        '2026_09_04_080000_add_pixels_fired_to_orders.php',
        '2026_09_08_150000_add_menu_item_options.php',
        '2026_09_09_000000_add_menu_display_flags.php',
        '2026_09_09_160000_add_menu_item_columns.php',
        '2026_09_10_030000_add_patch_archive_columns.php',
        '2026_09_10_163842_create_redirects_and_404_log.php',
        '2026_09_10_210210_add_order_detail_fields.php',
        '2026_09_13_090000_add_gift_and_order_notes.php',
        '2026_09_13_120000_add_gift_fee_to_orders.php',
        '2026_09_14_100000_add_payment_idempotency_indexes.php',
    ];

    $new = array_values(array_diff($offenders, $known));

    expect($new)->toBe([], 'New migrations must not use ->after(): '.implode(', ', $new));
});
