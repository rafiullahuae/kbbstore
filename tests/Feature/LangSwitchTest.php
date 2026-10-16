<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;

/**
 * The footer language switcher (16 October 2026).
 *
 * What it looked like on the shop: with Arabic on, there was no visible way to
 * choose العربية or English (Lane AS audit) -- a shopper reached /ar only from
 * Google. The header is locked, so the footer's bottom row carries it, built
 * from the same pair the page's hreflang links name.
 *
 * Arabic OFF (the live shop today): the partial prints nothing, not a space,
 * which StorefrontEnglishUnchangedTest also holds. MUTATION: drop the
 * `count(...) > 1` guard and the English pages change -- red there and here.
 */
function lsArabic(bool $on): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => $on ? '1' : '0', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

it('prints nothing at all while Arabic is off', function () {
    lsArabic(false);
    test()->get('/')->assertOk()->assertDontSee('kbb-lang', false);
});

it('names both languages, each in its own script, linking this page in the other one', function () {
    lsArabic(true);

    test()->get('/')->assertOk()
        ->assertSee('<nav class="kbb-lang" aria-label="Language"><span lang="en" aria-current="true">English</span><a href="/ar/" lang="ar" hreflang="ar">العربية</a></nav>', false);

    test()->get('/ar/')->assertOk()
        ->assertSee('<nav class="kbb-lang" aria-label="Language"><a href="/" lang="en" hreflang="en">English</a><span lang="ar" aria-current="true">العربية</span></nav>', false);
});
