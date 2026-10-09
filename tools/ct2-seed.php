<?php
/*
 * Seed the Lane CT2 preview: the live shop's state as the owner's screenshot
 * showed it -- `social_instagram` saved blank (the SEO tab's empty box), the
 * WhatsApp number and support email set. Written into the PREVIEW's database
 * only; nothing here ships.
 */

use App\Models\AdminUser;
use App\Services\SettingsService;

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

$s = app(SettingsService::class);
$s->set('brand_whatsapp', '+971585052611');
$s->set('support_email', 'info@kbeautybliss.com');
$s->set('support_phone', '+971 58 505 2611');
foreach (['social_instagram', 'social_tiktok', 'social_facebook'] as $k) {
    $s->set($k, '');
}

echo "ct2 seed: social_* blank, WhatsApp and email set\n";

// Arabic on, mirrored, and the contact page's Arabic words published, so the
// /ar/ shots show the page as an Arabic shopper would.
$s->set(\App\Support\Locale::SETTING_ENABLED, '1');
$s->set(\App\Support\Locale::SETTING_RTL, '1');
foreach (\App\Services\Translation\ArabicInterfaceDrafts::all() as $key => $value) {
    if (str_starts_with($key, 'store.contact.')) {
        \App\Services\Translation\TranslationStore::put('ar', 'ui', 0, $key, $value,
            \App\Models\Translation::STATUS_PUBLISHED, \App\Models\Translation::SOURCE_MANUAL);
    }
}
echo "ct2 seed: Arabic on (RTL), contact strings published\n";
