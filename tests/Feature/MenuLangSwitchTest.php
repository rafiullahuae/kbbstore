<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\HeaderSettings;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;

/**
 * The language switch at the top of the phone menu (17 October 2026).
 *
 * What it looked like on the shop: with 2.60.531 the switch was in the header
 * and the footer, but the owner opened the ☰ menu and found none -- "in the
 * menu i can't see the language switch ... i need the switch in menu panel
 * too, as previewed" (round 1, option G: "English | العربية" beside the ×).
 *
 * MUTATION: drop the @include from partials/mobile-chrome -- the second test
 * is red; drop the mode check in partials/mm-lang-switch -- the third is red;
 * drop the isset() guard -- the first is red (and StorefrontEnglishUnchanged).
 */
function mlsArabic(bool $on): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => $on ? '1' : '0', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

it('prints nothing in the menu while Arabic is off', function () {
    mlsArabic(false);
    test()->get('/')->assertOk()->assertDontSee('mm-lang', false);
});

it('puts English | العربية at the top of the menu, before the search, linking this page in the other language', function () {
    mlsArabic(true);

    $en = test()->get('/')->assertOk()->getContent();
    expect($en)->toContain('<div class="mm-lang"><span lang="en" aria-current="true">English</span><a href="/ar/" lang="ar" hreflang="ar">العربية</a></div>')
        ->and(substr_count($en, 'class="mm-lang"'))->toBe(1);

    $menu = substr($en, (int) strpos($en, 'id="mmenu"'));
    $sw = strpos($menu, 'class="mm-lang"');
    $body = strpos($menu, 'id="mmBody"');
    expect($sw)->not->toBeFalse()->and($sw)->toBeLessThan($body);
    if (($srch = strpos($menu, 'class="mm-srch"')) !== false) {
        expect($sw)->toBeLessThan($srch);
    }

    test()->get('/ar/')->assertOk()
        ->assertSee('<div class="mm-lang"><a href="/" lang="en" hreflang="en">English</a><span lang="ar" aria-current="true">العربية</span></div>', false);
});

it('follows Appearance → Header → Icons → Language switch: shown for Phone and Both, not for Computer or Off', function () {
    mlsArabic(true);

    foreach (['both' => true, 'phone' => true, 'computer' => false, 'off' => false] as $mode => $shown) {
        app(HeaderSettings::class)->save(['lang_switch' => $mode]);
        Setting::flushMap();
        SettingsService::forgetMemo();
        app(SettingsService::class)->flush();

        $html = test()->get('/')->assertOk()->getContent();
        expect(str_contains($html, 'class="mm-lang"'))->toBe($shown, $mode);
    }
});

it('leaves the search out of the Arabic menu only, and puts it back when the switch is on', function () {
    // The owner, 17 October 2026: "in Menu panel, in arabic language, turn off
    // the search. only in arabic version i need to turned off." Appearance →
    // Mobile menu → Top of the sheet → "Search field on Arabic pages", OFF.
    // MUTATION: drop the locale check in partials/mobile-chrome -- red.
    mlsArabic(true);

    expect(test()->get('/')->assertOk()->getContent())->toContain('id="mmFilter"');
    expect(test()->get('/ar/')->assertOk()->getContent())->not->toContain('id="mmFilter"');

    app(\App\Services\MobileMenu::class)->save(['show_search_ar' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    expect(test()->get('/ar/')->assertOk()->getContent())->toContain('id="mmFilter"');
});
