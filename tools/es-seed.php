<?php
/* Seed the Lane AD preview: the checkout's Shipping address with the Emirate
   as a list. The shop's own zones (ShippingSeeder: All UAE AED 20 / free over
   199, Gulf AED 150 / free over 1,600), COD, three products, and two returning
   customers -- one in Dubai whose saved emirate is the lower-case "dubai" a
   typed box collected, one in Muscat. ES_OFF=1 turns the switch off (the
   "before"); ES_AR=1 turns Arabic on and approves the Arabic drafts so /ar
   shows what it will once the owner approves them. Idempotent. PREVIEW ONLY. */
\App\Models\AdminUser::firstOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

foreach ([
    ['Anua - PDRN Glass Skin Set', 'es-anua-pdrn', 375],
    ['Arencia - Vitamin C Booster Trio', 'es-arencia-trio', 279],
    ['medicube - Vanilla & Pistachio Deodorant', 'es-pistachio', 99],
] as [$name, $slug, $price]) {
    \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $price * 100, 'is_visible' => true,
        'status' => 'publish', 'stock_status' => 'instock', 'type' => 'simple',
    ]);
}

(new \Database\Seeders\ShippingSeeder())->run();
\App\Models\PaymentProvider::query()->updateOrCreate(['id' => 'cod'], ['title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

foreach ([
    ['aisha@preview.test', 'Aisha Khan', ['line1' => 'Building 1-10, G-04', 'city' => 'Al Quoz', 'state' => 'dubai', 'country' => 'AE']],
    ['salim@preview.test', 'Salim Al Harthy', ['line1' => 'Way 3011, House 22', 'city' => 'Ruwi', 'state' => 'Muscat', 'country' => 'OM']],
] as [$email, $name, $addr]) {
    $c = \App\Models\Customer::firstOrCreate(['email' => $email], [
        'name' => $name, 'phone' => '0501234567', 'password' => 'preview-secret-1',
    ]);
    if (! $c->addresses()->exists()) {
        $c->addresses()->create($addr + ['type' => 'shipping', 'is_default' => true, 'first_name' => strtok($name, ' ')]);
    }
}

$sv = app(\App\Services\SettingsService::class);
app(\App\Services\CheckoutPage::class)->save(['state_list' => getenv('ES_OFF') !== '1']);
$sv->set(\App\Support\Locale::SETTING_ENABLED, getenv('ES_AR') === '1');
$sv->set(\App\Support\Locale::SETTING_RTL, getenv('ES_AR') === '1');
if (getenv('ES_AR') === '1') {
    $now = now();
    foreach (\App\Services\Translation\ArabicInterfaceDrafts::all() as $key => $value) {
        $field = \App\Services\Translation\TranslationStore::normaliseKey($key);
        \Illuminate\Support\Facades\DB::table('translations')->updateOrInsert(
            ['locale' => 'ar', 'group' => \App\Models\Translation::GROUP_UI, 'item_id' => 0, 'field' => $field],
            ['value' => $value, 'status' => \App\Models\Translation::STATUS_PUBLISHED, 'source' => \App\Models\Translation::SOURCE_MANUAL, 'created_at' => $now, 'updated_at' => $now]
        );
    }
    \App\Services\Translation\TranslationStore::flush();
}
$sv->flush();
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();

echo 'lane-ad seeded: list=', getenv('ES_OFF') === '1' ? 'off' : 'on', ' ar=', getenv('ES_AR') === '1' ? 'on' : 'off', "\n";

if (getenv('KBB_PUBLIC_PATH')) {
    file_put_contents(getenv('KBB_PUBLIC_PATH') . '/es-ids.json', json_encode(
        \App\Models\Product::query()->where('slug', 'like', 'es-%')->orderBy('id')->pluck('id')->all()
    ));
}
