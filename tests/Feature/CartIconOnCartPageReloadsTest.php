<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;

/**
 * ON THE CART PAGE, THE BAG ICON RELOADS THE CART PAGE (Lane QK5).
 *
 * The owner: "the cart panel icon should not open the cart panel on cart page
 * at all. it will just refresh the cart page, that's it."
 *
 * THE DEFECT ON THE SHOP: on /cart/ a tap on the header bag icon (or the phone
 * tab bar's Bag) slid the cart drawer in OVER the cart page — the same list
 * twice, one on top of the other. overlay.js preventDefault()ed every
 * [data-kbb-open] click, so the icon's own href (/cart/) never navigated.
 *
 * The marker is #cartPage, the wrapper store/cart.blade.php already renders and
 * nothing else does, so the cart page's markup is byte-identical (no pin moves).
 *
 * MUTATION: delete the `document.getElementById('cartPage')` branch from
 * overlay.js — the JS test is red, and in Chromium the drawer opens on /cart/.
 * MUTATION: rename the cart view's id="cartPage" — the markup test is red.
 */
function qk5OverlayJs(): string
{
    return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(resource_path('js/kbb/overlay.js')));
}

it('lets the cart icon navigate on the cart page instead of opening the drawer', function () {
    $js = qk5OverlayJs();

    // The cart-page branch sits BEFORE the preventDefault that opens a drawer.
    $branch = strpos($js, "opener.dataset.kbbOpen === 'cart' && document.getElementById('cartPage')");
    $prevent = strpos($js, 'event.preventDefault();');
    expect($branch)->not->toBeFalse()
        ->and($branch)->toBeLessThan($prevent);

    // Inside the branch: a link is left alone (no preventDefault), a non-link
    // reloads, and the branch returns before open() runs.
    $body = substr($js, $branch, $prevent - $branch);
    expect($body)->not->toContain('preventDefault')
        ->and($body)->toContain('location.reload()')
        ->and($body)->toContain('HTMLAnchorElement')
        ->and($body)->toContain('return;');

    // Every other page still opens the drawer exactly as before.
    expect($js)->toContain('open(opener.dataset.kbbOpen);');
});

it('renders the #cartPage marker on the cart page, English and Arabic, and the icon still points at the cart', function (string $path, string $cartHref) {
    if (str_starts_with($path, '/ar/')) {
        Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
        Setting::flushMap();
        SettingsService::forgetMemo();
        app(SettingsService::class)->flush();
        TranslationStore::flush();
    }

    $html = $this->get($path)->assertOk()->getContent();

    expect(substr_count($html, 'id="cartPage"'))->toBe(1);
    // The header icon is a real link to the cart, so leaving it to navigate IS the reload.
    expect($html)->toMatch('#<a class="ib" href="[^"]*'.preg_quote($cartHref, '#').'" data-kbb-open="cart"#');
})->with([
    'english' => ['/cart/', '/cart/'],
    'arabic'  => ['/ar/cart/', '/ar/cart/'],
]);

it('does not carry the marker on other shop pages, so the icon still opens the drawer there', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('id="cartPage"')
        ->and($html)->toContain('data-kbb-open="cart"');
});
