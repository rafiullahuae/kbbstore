<?php

declare(strict_types=1);

/*
 * Lane QK8: tools/qk7-seed.php's shop (mac-seed + UAE delivery, AED 20 flat,
 * free over AED 199) plus the four payment gateways the live checkout shows
 * (card, Tabby, Tamara, cash on delivery), the Arabic shop switched on with
 * its drafts published (preview fixture only), and a contact page. Run
 * through tools/qk8-preview.sh; never against a real database.
 */

use App\Models\PaymentProvider;

require __DIR__.'/qk7-seed.php';

PaymentProvider::query()->delete();
$fake = 'preview';
foreach ([
    ['stripe', 'Credit / Debit Card', 0, ['publishable_key' => 'pk_test_'.$fake, 'secret_key' => 'sk_test_'.$fake]],
    ['tabby', 'Tabby Installments', 1, ['public_key' => 'pk_test_'.$fake, 'secret_key' => 'sk_test_'.$fake, 'merchant_code' => 'AE']],
    ['tamara', 'Pay later with Tamara', 2, ['api_token' => $fake, 'notification_token' => $fake]],
    ['cod', 'Cash on delivery', 3, []],
] as [$id, $title, $pos, $config]) {
    $row = PaymentProvider::create(['id' => $id, 'title' => $title, 'enabled' => true, 'mode' => 'test', 'position' => $pos]);
    if ($config !== []) {
        $row->config = $config;
        $row->save();
    }
}

$sv = app(\App\Services\SettingsService::class);
$sv->set(\App\Support\Locale::SETTING_ENABLED, true);
$sv->set(\App\Support\Locale::SETTING_RTL, true);
$sv->flush();

foreach (\App\Models\Translation::query()->where('locale', 'ar')->where('status', \App\Models\Translation::STATUS_DRAFT)->cursor() as $row) {
    $row->status = \App\Models\Translation::STATUS_PUBLISHED;
    $row->save();
}
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
\App\Services\Translation\TranslationStore::flush();
\Illuminate\Support\Facades\Cache::flush();
echo "qk8 preview seeded\n";
