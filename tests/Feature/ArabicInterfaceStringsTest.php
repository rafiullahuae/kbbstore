<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Models\Translation;
use App\Services\SettingsService;
use App\Services\Translation\FrontEndStrings;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;

/**
 * The other half of the conversion, and the half the byte-identity tests
 * cannot see: that a translated string actually reaches an Arabic shopper.
 *
 * Everything else this lane wrote proves the ENGLISH page did not move. That is
 * the acceptance bar, and on its own it would be satisfied by a conversion that
 * quietly resolved every key back to English for ever. So this types an Arabic
 * row through the same admin write path the owner uses, asks for the page at
 * /ar/, and reads the Arabic back — on the page, in the <title>, through a
 * plural form, and in the table the front-end scripts are handed.
 *
 * It also pins the fallback that the plan calls the designed-for outcome: a key
 * with no Arabic row renders the English, not the key and not a placeholder, so
 * a half-translated shop still sells.
 */
function arabicOn(): void
{
    Setting::query()->updateOrCreate([
        'key' => Locale::SETTING_ENABLED,
    ], ['value' => '1', 'autoload' => true]);

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

/** One published Arabic string, written the way the admin screen writes it. */
function arabicString(string $key, string $value): void
{
    TranslationStore::put(
        'ar',
        'ui',
        0,
        $key,
        $value,
        Translation::STATUS_PUBLISHED,
        Translation::SOURCE_MANUAL,
    );
}

it('serves a typed Arabic interface string on an Arabic page', function () {
    arabicOn();

    arabicString('store.cart.heading', 'حقيبتك');
    arabicString('store.cart.page_title', 'السلة · كي-بيوتي بليس');
    arabicString('store.cart.empty_heading', 'حقيبتك فارغة');

    $html = $this->get('/ar/cart/')->assertOk()->getContent();

    expect($html)->toContain('حقيبتك')
        ->and($html)->toContain('<title>السلة')
        ->and($html)->toContain('حقيبتك فارغة')
        // and the English is gone from the places those keys fill
        ->and($html)->not->toContain('>Your Bag ')
        ->and($html)->not->toContain('<b>Your bag is empty</b>');

    /*
     * "Your bag is empty" is still on this page, once, and that is correct: the
     * mini-cart drawer's wording is an ADMIN SETTING (Appearance → Cart panel,
     * `txt_empty`), not an interface string, and this lane deliberately left
     * every settings-backed string alone — a second English source for a value
     * the owner types would be one of the two silently wrong. It reaches Arabic
     * through the catalogue half of the translation module, not through here.
     */
    expect(substr_count($html, 'Your bag is empty'))->toBe(1);
});

it('falls back to English for a key nobody has translated yet', function () {
    arabicOn();

    arabicString('store.cart.heading', 'حقيبتك');

    $html = $this->get('/ar/cart/')->assertOk()->getContent();

    // Translated: Arabic. Untranslated: the English, not the key.
    expect($html)->toContain('حقيبتك')
        ->and($html)->toContain('Your bag is empty')
        ->and($html)->not->toContain('store.cart.empty_heading');
});

it('chooses an Arabic plural form for a counted string', function () {
    arabicOn();

    /*
     * Arabic's six forms, in the order Illuminate's MessageSelector expects for
     * `ar`: 0, 1, 2, 3-10, 11-99, 100+. English has two, so this is the thing
     * that could not have worked with `$n . ' items'` — which is what this
     * string was before the conversion.
     */
    arabicString(
        'store.cart.item_count',
        'لا منتجات|منتج واحد|منتجان|:count منتجات|:count منتجًا|:count منتج'
    );

    app()->setLocale('ar');

    expect(trans_choice('store.cart.item_count', 0))->toBe('لا منتجات')
        ->and(trans_choice('store.cart.item_count', 1))->toBe('منتج واحد')
        ->and(trans_choice('store.cart.item_count', 2))->toBe('منتجان')
        ->and(trans_choice('store.cart.item_count', 5))->toBe('5 منتجات')
        ->and(trans_choice('store.cart.item_count', 20))->toBe('20 منتجًا')
        ->and(trans_choice('store.cart.item_count', 200))->toBe('200 منتج');

    app()->setLocale('en');

    expect(trans_choice('store.cart.item_count', 1))->toBe('1 item')
        ->and(trans_choice('store.cart.item_count', 4))->toBe('4 items');
});

it('sends the front-end string table to an Arabic page and to no English one', function () {
    arabicOn();

    arabicString('store.js.wishlist_saved', 'حُفظ في قائمتك');

    $arabic = $this->get('/ar/my-wishlist/')->assertOk()->getContent();
    $english = $this->get('/my-wishlist/')->assertOk()->getContent();

    /*
     * The value is looked for in the form @json writes it: json_encode escapes
     * non-ASCII to \uXXXX by default, which is valid JavaScript and is what the
     * browser actually receives.
     */
    $encoded = trim(json_encode('حُفظ في قائمتك'), '"');

    expect($arabic)->toContain('window.KBB_T')
        ->and($arabic)->toContain($encoded)
        ->and($arabic)->toContain('store.js.wishlist_saved')
        // The table is the js.* group and nothing else — no catalogue, no
        // settings, and none of the several hundred strings the server renders.
        ->and($arabic)->not->toContain('store.cart.empty_heading');

    expect($english)->not->toContain('window.KBB_T');

    app()->setLocale('ar');
    $table = FrontEndStrings::forLocale('ar');
    app()->setLocale('en');

    expect($table['store.js.wishlist_saved'])->toBe('حُفظ في قائمتك')
        // An untranslated front-end key is still shipped, carrying the English,
        // so the script never has to decide between a key and a blank.
        ->and($table['store.js.generic_error'])->toBe('Something went wrong — please try again.');
});

it('renders an Arabic email through the order locale', function () {
    arabicOn();

    arabicString('email.confirmation.track_button', 'تتبّع طلبك');

    $order = Tests\Support\EnglishRenderWalk::documentOrder();
    $order->forceFill(['locale' => 'ar'])->save();

    $html = \App\Support\OrderLocale::render(
        $order,
        fn (): string => (string) (new \App\Mail\OrderConfirmation($order))->render()
    );

    expect($html)->toContain('تتبّع طلبك')
        ->and($html)->not->toContain('>Track your order<');

    // And the process is back in English afterwards, which is the half that
    // matters in a queue worker.
    expect(app()->getLocale())->toBe('en');
});
