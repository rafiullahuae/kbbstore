<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;
use App\Support\Url;

/**
 * A link to this shop saved as a FULL address, on an Arabic page. (16 October 2026)
 *
 * What it looked like on the shop: on /ar, the menu's "العناية بالجسم" (Body
 * Care) opened the English page, while every other menu entry stayed Arabic.
 * Body Care was saved as https://kbeautybliss.com/collections/body-care/ and
 * its neighbours as /collections/hair-care/; Url::to() passed every absolute
 * address through untouched, so only the short ones got /ar/. The homepage
 * banners' brand links (https://kbeautybliss.com/brands/anua/ ...) did the same.
 *
 * MUTATION: put `return $path;` back as the whole absolute-URL branch -- red.
 */
function aofArabicOn(): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

function aofOnHost(string $host): void
{
    app()->instance('request', \Illuminate\Http\Request::create('https://' . $host . '/ar/'));
}

afterEach(function () {
    app()->setLocale('en');
    config(['kbb.base_path' => '']);
    Url::forgetBase();
});

it('keeps an Arabic shopper in Arabic when a link to this shop is saved as a full address', function () {
    aofArabicOn();
    aofOnHost('kbeautybliss.com');
    app()->setLocale('ar');

    expect(Url::to('https://kbeautybliss.com/collections/body-care/'))->toBe('/ar/collections/body-care/')
        ->and(Url::to('https://www.kbeautybliss.com/brands/anua/'))->toBe('/ar/brands/anua/')
        ->and(Url::to('http://KBeautyBliss.com/shop/?orderby=price#top'))->toBe('/ar/shop/?orderby=price#top')
        ->and(Url::to('https://kbeautybliss.com'))->toBe('/ar/')
        // An address that already says /ar/ is not doubled.
        ->and(Url::to('https://kbeautybliss.com/ar/collections/body-care/'))->toBe('/ar/collections/body-care/')
        // Same host from www.: the rule is "this shop", not the exact spelling.
        ->and((function () { aofOnHost('www.kbeautybliss.com'); return Url::to('https://kbeautybliss.com/collections/body-care/'); })())->toBe('/ar/collections/body-care/');
});

it('leaves files, other sites and other kinds of link exactly as they were', function () {
    aofArabicOn();
    aofOnHost('kbeautybliss.com');
    app()->setLocale('ar');

    foreach ([
        'https://kbeautybliss.com/uploads/banners/anua-pdrn-glow-skincare.webp', // a file has no language
        'https://kbeautybliss.com/sitemap.xml',
        'https://www.instagram.com/kbeauty.bliss/',
        'https://kbeautybliss.com.evil.example/collections/body-care/',         // only ends like ours
        'https://evilkbeautybliss.com/collections/body-care/',
        'https://user@kbeautybliss.com/collections/body-care/',
        'mailto:hello@kbeautybliss.com',
        'tel:+971585052611',
        'https://wa.me/971585052611',
    ] as $url) {
        expect(Url::to($url))->toBe($url, $url);
    }
});

it('changes nothing in English, where a full address stays the full address', function () {
    aofArabicOn();
    aofOnHost('kbeautybliss.com');
    app()->setLocale('en');

    expect(Url::to('https://kbeautybliss.com/collections/body-care/'))->toBe('https://kbeautybliss.com/collections/body-care/')
        ->and(Url::to('/collections/hair-care/'))->toBe('/collections/hair-care/');
});

it('keeps the base path where the shop is mounted under one', function () {
    config(['kbb.base_path' => '/kbb-upgrade']);
    Url::forgetBase();
    aofArabicOn();
    aofOnHost('easywebsol.com');
    app()->setLocale('ar');

    expect(Url::to('https://easywebsol.com/kbb-upgrade/collections/body-care/'))->toBe('/kbb-upgrade/ar/collections/body-care/')
        // Same host but outside the shop's folder: some other site on that box.
        ->and(Url::to('https://easywebsol.com/other-site/page/'))->toBe('https://easywebsol.com/other-site/page/');
});
