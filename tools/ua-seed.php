<?php
/*
 * Seed the Lane UA preview (tools/ua-preview.sh): Arabic + RTL on, a few
 * products, and the footer's Arabic drafts APPROVED so /ar/ shows the app row
 * as the Arabic shop will once the owner approves them under Translation ->
 * Strings. Written into the PREVIEW's database only; nothing here ships.
 */
use App\Models\Product;
use App\Models\Setting;
use App\Models\Translation;
use App\Support\Locale;

\App\Models\AdminUser::query()->firstOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);
Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);
for ($i = 1; $i <= 8; $i++) {
    Product::query()->updateOrCreate(['slug' => "glow-serum-{$i}"], [
        'name' => "Glow Serum No. {$i}", 'price' => 4500 + $i * 100, 'status' => 'publish',
        'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
    ]);
}
Translation::query()->where('locale', 'ar')->where('field', 'like', 'store.footer.%')->update(['status' => Translation::STATUS_PUBLISHED]);
\App\Services\Translation\TranslationStore::flush();
app(\App\Services\SettingsService::class)->flush();
\Illuminate\Support\Facades\Cache::flush();
echo "ua seed done\n";
