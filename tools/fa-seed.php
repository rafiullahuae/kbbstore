<?php
/*
 * Seed the Lane FA preview: Arabic + RTL on so /ar/ renders the footer in
 * Arabic, and a few products so the homepage is not empty above the footer.
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
use App\Models\Setting;
use App\Models\Product;
use App\Support\Locale;

Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);
for ($i = 1; $i <= 8; $i++) {
    Product::query()->updateOrCreate(['slug' => "glow-serum-{$i}"], [
        'name' => "Glow Serum No. {$i}", 'price' => 4500 + $i * 100, 'status' => 'publish',
        'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
    ]);
}
app(\App\Services\SettingsService::class)->flush();
\Illuminate\Support\Facades\Cache::flush();
echo "fa seed done\n";
