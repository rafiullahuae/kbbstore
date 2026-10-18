<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;

/**
 * "Keep the Arabic shop in Arabic" -- Translation -> Language settings.
 *
 * The owner, 17-18 October 2026: "if user set the browser translation on by
 * default to english. if that user visit our arabic version, then the site
 * ignores the browser translation and stays in arabic as our original arabic
 * version" -- "yes keep the arabic site in arabic".
 *
 * What it looked like on the shop: Chrome / Edge / in-app browsers set to
 * translate into English machine-translated every /ar page, and since Lane AS
 * the page then handed that browser to the English shop and REMEMBERED it, so
 * the shopper never saw the Arabic shop again.
 *
 * Now, on Arabic pages only and ON unless switched off: <html translate="no">,
 * <meta name="google" content="notranslate">, and no English hand-off (no
 * `trEn`). English pages print none of it.
 *
 * MUTATION: drop `@if ($kbbNoTr) translate="no"@endif` from the layout, or the
 * `! $kbbNoTr` in the trEn condition, or default keepsArabic() to false -- each
 * is red below.
 */
function kaSet(array $values): void
{
    foreach (array_merge(['site_url' => 'https://kbeautybliss.test'], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function kaProduct(): void
{
    $brand = Brand::create(['slug' => 'ka-anua', 'name' => 'Anua']);
    $category = Category::create(['slug' => 'ka-toners', 'name' => 'Toners', 'path' => 'ka-toners']);
    Product::create([
        'slug' => 'ka-pad', 'name' => 'Soothing Pad', 'status' => 'publish', 'is_visible' => true,
        'brand_id' => $brand->id, 'category_id' => $category->id, 'price' => 7900,
        'stock_status' => 'instock', 'type' => 'simple',
    ]);
}

function kaHead(string $html): string
{
    return substr($html, 0, (int) strpos($html, '</head>'));
}

it('keeps every Arabic page out of the browser translator by default, and stops the English hand-off there', function () {
    kaProduct();
    kaSet([Locale::SETTING_ENABLED => '1', Locale::SETTING_RTL => '1']); // keep_arabic never saved: ON

    foreach (['/ar/', '/ar/product/ka-pad/', '/ar/shop/'] as $path) {
        $html = test()->get($path)->assertOk()->getContent();

        expect($html)->toMatch('~<html lang="ar" dir="rtl"[^>]* translate="no">~')
            ->and(kaHead($html))->toContain('<meta name="google" content="notranslate">')
            ->and($html)->not->toContain('"trEn"');
    }
});

it('prints none of it on English pages', function () {
    kaProduct();
    kaSet([Locale::SETTING_ENABLED => '1', Locale::SETTING_RTL => '1']);

    foreach (['/', '/product/ka-pad/', '/shop/'] as $path) {
        $html = test()->get($path)->assertOk()->getContent();

        expect($html)->not->toContain('translate="no"')
            ->and($html)->not->toContain('notranslate')
            ->and($html)->toMatch('~<html lang="en" dir="ltr"[^>]*>\n<head>\n<meta charset="UTF-8">\n~');
    }
});

it('switched off, the Arabic page is as before: translatable, with the English hand-off when that is on', function () {
    kaProduct();
    kaSet([Locale::SETTING_ENABLED => '1', Locale::SETTING_RTL => '1', Locale::SETTING_KEEP_ARABIC => '0']);

    $html = test()->get('/ar/product/ka-pad/')->assertOk()->getContent();

    expect($html)->not->toContain('translate="no"')
        ->and($html)->not->toContain('notranslate')
        ->and($html)->toContain('"trEn":1');
});

it('saves the switch from Translation -> Language settings, ON when never saved, admin only, booleans only', function () {
    test()->getJson('/admin-api/translations/settings')->assertUnauthorized();

    test()->actingAs(AdminUser::create(['name' => 'KA', 'email' => 'ka-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']), 'admin');

    expect(test()->getJson('/admin-api/translations/settings')->assertOk()->json('keep_arabic'))->toBeTrue();

    test()->postJson('/admin-api/translations/settings', ['keep_arabic' => false])->assertOk()->assertJsonPath('keep_arabic', false);
    expect(Setting::query()->where('key', Locale::SETTING_KEEP_ARABIC)->value('value'))->toBe('0');

    test()->postJson('/admin-api/translations/settings', ['keep_arabic' => '<b>yes</b>'])->assertStatus(422);

    $screen = (string) file_get_contents(resource_path('views/admin/partials/translation-screens.blade.php'));
    expect(substr_count($screen, "togHTML('keep_arabic'"))->toBe(1);
});
