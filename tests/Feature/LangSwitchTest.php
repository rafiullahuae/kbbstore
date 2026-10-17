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
        ->assertSee('<nav class="kbb-lang"><span lang="en" aria-current="true">English</span><a href="/ar/" lang="ar" hreflang="ar">العربية</a></nav>', false);

    test()->get('/ar/')->assertOk()
        ->assertSee('<nav class="kbb-lang"><a href="/" lang="en" hreflang="en">English</a><span lang="ar" aria-current="true">العربية</span></nav>', false);
});

it('keeps the language switch apart from the Privacy and Terms links in the footer', function () {
    // The owner, 17 October 2026, on the desktop footer: "keep the language
    // switch seperate from the privacy etc links". It sat inside
    // <nav class="kft-legal"> as a third and fourth link; it is now its own
    // item in the bottom bar. MUTATION: move the include back before </nav>.
    $view = (string) file_get_contents(resource_path('views/partials/footer-bliss.blade.php'));

    // At the start of the next line, so with Arabic off the page is byte for
    // byte what it was (a directive at a line's end swallows its newline).
    expect($view)->toContain("{{ __('store.footer.link_terms') }}</a></nav>\n    @include('partials.lang-switch')<div class=\"kft-pay\">")
        ->and(substr_count($view, "@include('partials.lang-switch')"))->toBe(1);
});
