<?php

/* Lane CK2: put the text-size settings of one scenario into a preview's
   database, through the services' own save() / saveAr() (both Lane CK's and
   Lane CK2's code have them), so two previews can be compared like for like.
   CK2_SET is JSON: {"checkout": {...}, "checkout_ar": {...}, "cartpage": ...,
   "cartpage_ar": ..., "cartpanel": ..., "cartpanel_ar": ...}. Every key not
   named goes back to its default first. PREVIEW ONLY. */
$set = json_decode((string) getenv('CK2_SET'), true) ?: [];

$surfaces = [
    'checkout' => \App\Services\CheckoutPage::class,
    'cartpage' => \App\Services\CartPage::class,
    'cartpanel' => \App\Services\CartPanel::class,
];

foreach ($surfaces as $name => $class) {
    $svc = app($class);
    $defaults = [];
    foreach ($class::arKeys() as $key) {
        $defaults[$key] = $class::SCHEMA[$key][2];
    }
    foreach (['d_ov_link', 'm_ov_link', 'tx_ov_link', 'tx_ov_link_m', 'ov_link', 'ov_link_m', 'fl_tone'] as $k) {
        if (isset($class::SCHEMA[$k])) {
            $defaults[$k] = $class::SCHEMA[$k][2];
        }
    }
    $svc->save(array_merge($defaults, $set[$name] ?? []));
    $svc->saveAr($set[$name.'_ar'] ?? []);
}

app(\App\Services\SettingsService::class)->flush();
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
echo 'ck2 set: ', json_encode($set), "\n";
