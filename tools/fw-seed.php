<?php
/*
 * Seed the Lane FW preview: Lane PH's seed, plus /super-sale/ in the shape of
 * the owner's phone screenshot (docs/fw-owner/super-sale-phone-gutters.png) --
 * a header picture that fills its height, breadcrumb and title hidden. The
 * phone/desktop picture width is NOT set here: what the page draws is the
 * stored bag's own default, which is the thing being shown.
 * Preview database only; nothing here reaches a package.
 */
require __DIR__.'/ph-seed.php';

$ph = app(\App\Services\PageHeaders::class)->all();
$sale = $ph['pages']['collection:super-sale'] ?? \App\Services\PageHeaders::blank();
$sale['img'] = '/uploads/ph/header-wide.png';
foreach (['d', 'm'] as $dev) {
    $sale[$dev]['crumb'] = false;
    $sale[$dev]['title'] = false;
    $sale[$dev]['fit'] = 'cover';
    unset($sale[$dev]['width']);
}
$ph['pages']['collection:super-sale'] = $sale;
app(\App\Services\SettingsService::class)->set(\App\Services\PageHeaders::KEY, $ph);
echo "fw seed done\n";
