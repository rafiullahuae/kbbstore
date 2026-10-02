<?php
/*
 * Seed the Lane RD preview: press feedback on every storefront control.
 *
 * Lane PG2's twelve products (tools/pg2-seed.php), unchanged: they sit in
 * `skincare-sets`, so the homepage's "Big savings bundles" rail and its
 * "All sets" pill are real, and the seed switches the wishlist on, so every
 * card draws the heart the owner pressed. A product page from the same rows
 * carries the quantity stepper, Add to cart and the share button.
 *
 * RD_PRESS, when set, is stored as `layout_press` so a screenshot run can
 * photograph each letter without driving the admin screen for every one.
 */
require __DIR__.'/pg2-seed.php';

$press = getenv('RD_PRESS');

if (is_string($press) && $press !== '') {
    app(\App\Services\SettingsService::class)->set('layout_press', $press);
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
    app(\App\Services\SettingsService::class)->flush();
}

echo 'rd seed: pg2 catalogue, wishlist on, press='.($press ?: '(default)')."\n";
