<?php
/*
 * Lane BR preview seed. The shop exactly as the live one was before this lane:
 * the catalogue from rf-seed, and the stored brand text saying "Extra Beauty"
 * (store name, SEO site name, organisation, email From name, the footer's big
 * name) with the three brand switches OFF -- so the BEFORE shots are the old
 * head, and the AFTER shots are taken once the Brand name tab has replaced it
 * and the switches are back on. Preview database only; nothing ships.
 */
require __DIR__.'/rf-seed.php';

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

foreach ([
    'store_name' => 'Extra Beauty',
    'seo_site_name' => 'Extra Beauty',
    'org_name' => 'Extra Beauty',
    'mail_from_name' => 'Extra Beauty',
    'site_name_text' => 'Extra Beauty',
    'seo_brand_titles' => '0',
    'seo_brand_alternates' => '0',
    'seo_kw_kbeauty_one' => '0',
] as $k => $v) {
    \App\Models\Setting::query()->updateOrCreate(['key' => $k], ['value' => $v]);
}
\App\Models\Setting::flushMap();
echo "br seed done\n";
