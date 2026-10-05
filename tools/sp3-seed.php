<?php
/*
 * Seed the Lane SP3 preview: Lane PH's seed, plus /super-sale/ in the shape
 * of the owner's phone screenshot (docs/sp-owner/super-sale-phone.png) -- a
 * header picture with the breadcrumb and title hidden, under the strip.
 * Preview database only; nothing here reaches a package.
 */
require __DIR__.'/ph-seed.php';

$ph = app(\App\Services\PageHeaders::class)->all();
$sale = $ph['pages']['collection:super-sale'] ?? \App\Services\PageHeaders::blank();
$sale['img'] = '/uploads/ph/header-tall.png';
foreach (['d', 'm'] as $dev) {
    $sale[$dev]['crumb'] = false;
    $sale[$dev]['title'] = false;
    $sale[$dev]['fit'] = 'contain';
}
$ph['pages']['collection:super-sale'] = $sale;
app(\App\Services\SettingsService::class)->set(\App\Services\PageHeaders::KEY, $ph);
echo "sp3 seed done\n";
