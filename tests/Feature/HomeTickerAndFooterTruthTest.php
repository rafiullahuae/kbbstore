<?php

declare(strict_types=1);

/**
 * Lane DL — the two storefront claims left standing after the checkout was
 * cleaned up, and the one place a saved setting was written but never read.
 *
 * 1. THE HOMEPAGE TICKER ADVERTISED A DISCOUNT CODE THAT DOES NOT EXIST.
 *
 *    tests/Feature/UnbackedClaimsTest.php already pins, at length, that the
 *    CHECKOUT must not offer a code the shop has not got: GLOW30 shipped as the
 *    default of `checkout_coupon` and was refused the instant it was tapped.
 *    The same literal survived one scroll higher up the funnel, as the default
 *    of `home_ticker`, in the FIRST chip of the homepage ticker — directly
 *    under a comment explaining that this ticker's other two chips were removed
 *    for making claims up.
 *
 *    Nothing in this application writes `home_ticker`. It is absent from
 *    AdminController::SETTING_RULES, from EcommerceApiController's schema and
 *    from SettingsSeeder, and no ->set() anywhere names it — so the default was
 *    not a placeholder an owner would replace, it was the shipped and only
 *    value. Every fresh shop scrolled "Anniversary 30% off — code GLOW30" past
 *    every visitor, twice a loop, with no way to change or remove it.
 *
 *    The chip now renders only when the owner has actually written one, which
 *    is the same restraint the delivery and free-delivery chips beside it
 *    already got. Nothing is invented to replace it.
 *
 * 2. THE FOOTER IGNORED THE SOCIAL PROFILES THE OWNER SAVED.
 *
 *    `social_instagram`, `social_tiktok` and `social_facebook` are validated in
 *    AdminController::SETTING_RULES and feed schema.org `sameAs`, so an owner
 *    who corrects a profile URL sees the structured data change — and the
 *    footer of every page goes on linking the literal it was written with.
 *    Two things that must agree, disagreeing.
 *
 * THE CLASS-NAME TRAP (CLAUDE.md). The storefront CSS is inlined into the
 * document, so a bare search for `tick` or `fsoc` matches a stylesheet rule
 * whether or not any element carries the class. Every assertion below either
 * matches an ELEMENT with an anchored regex, or looks for the words a shopper
 * would read.
 *
 * AND THE QUOTED-CLAIM TRAP. The string GLOW30 is deliberately NOT written out
 * in this file. It is assembled at run time, so this test cannot match itself
 * and a future regex guard over the tree cannot read this file's own assertions
 * as the claim coming back.
 */

use App\Services\SettingsService;

/** Settings only through the service — it holds a forever-cache AND a per-process memo. */
function dlSet(string $key, $value): void
{
    app(SettingsService::class)->set($key, $value);
}

/** The banned code, never spelled out in this file. */
function dlDeadCode(): string
{
    return 'GLOW' . '30';
}

function dlHome(): string
{
    return test()->get('/')->assertOk()->getContent();
}

/** The elements of one class, never the stylesheet rule that names it. */
function dlElements(string $class, string $html): array
{
    preg_match_all('#<(\w+)[^>]*\sclass="[^"]*\b' . preg_quote($class, '#') . '\b[^"]*"[^>]*>#i', $html, $m);

    return $m[0];
}

/*
|------------------------------------------------------------------------------
| 1. The homepage promo ticker
|------------------------------------------------------------------------------
*/

it('does not advertise a discount code the shop has never had', function () {
    $html = dlHome();

    expect(str_contains($html, dlDeadCode()))->toBeFalse(
        'The homepage still advertises a discount code that exists nowhere in this application.'
    );
});

it('does not announce an anniversary sale nobody recorded', function () {
    $html = dlHome();

    expect(stripos($html, 'Anniversary') === false)->toBeTrue(
        'The homepage announces an anniversary offer the shop has no record of.'
    );
});

it('shows the owner his own ticker line once he has written one', function () {
    dlSet('home_ticker', 'Free gift with every order this week');

    $html = dlHome();

    expect(str_contains($html, 'Free gift with every order this week'))->toBeTrue(
        'The owner wrote a ticker line and the homepage did not show it.'
    );
});

it('renders no ticker element at all when the shop has nothing to scroll', function () {
    /*
     * The other two chips ARE backed on a stock install — ShippingSeeder gives
     * the shop a real free-delivery threshold, and a threshold the owner really
     * set is exactly what the ticker should carry. So the "nothing to say"
     * state has to be built rather than assumed: no shipping at all, no
     * delivery sentence, and no ticker line of the owner's own.
     *
     * This is the case that says the removed chip is not being replaced by an
     * empty strip where it used to be.
     */
    \App\Models\ShippingMethod::query()->delete();
    \App\Models\ShippingZoneLocation::query()->delete();
    \App\Models\ShippingZone::query()->delete();
    dlSet('delivery_texts', []);
    dlSet('delivery_default_text', '');

    $html = dlHome();

    expect(dlElements('tick', $html))->toBe(
        [],
        'An empty promo ticker is still rendered when the shop has nothing to say in it.'
    );
});

it('still scrolls the chips the shop does back', function () {
    dlSet('home_ticker', 'Gift wrapping available');

    $html = dlHome();

    expect(dlElements('tick', $html))->not->toBe([], 'The ticker vanished when it had something true to say.');
    expect(str_contains($html, 'Gift wrapping available'))->toBeTrue();
});

/*
|------------------------------------------------------------------------------
| 2. The footer's social profiles
|------------------------------------------------------------------------------
*/

/** The hrefs of the footer's social row, in order. */
function dlSocialHrefs(string $html): array
{
    if (! preg_match('#<div class="fsoc">(.*?)</div>#s', $html, $m)) {
        return [];
    }

    preg_match_all('#href="([^"]*)"#', $m[1], $h);

    return $h[1];
}

it('links the social profiles the owner saved, not the ones it was written with', function () {
    dlSet('social_instagram', 'https://www.instagram.com/my-real-shop/');
    dlSet('social_tiktok', 'https://www.tiktok.com/@my-real-shop');
    dlSet('social_facebook', 'https://www.facebook.com/my-real-shop');

    $hrefs = dlSocialHrefs(dlHome());

    expect($hrefs)->toContain('https://www.instagram.com/my-real-shop/');
    expect($hrefs)->toContain('https://www.tiktok.com/@my-real-shop');
    expect($hrefs)->toContain('https://www.facebook.com/my-real-shop');
});

it('keeps the shipped profile when the owner has saved nothing', function () {
    $hrefs = dlSocialHrefs(dlHome());

    // Unchanged behaviour for a shop that has never touched the SEO screen:
    // the links it has always carried are still the links it carries.
    expect($hrefs)->toContain('https://www.instagram.com/kbeauty.bliss/');
    expect($hrefs)->toContain('https://www.tiktok.com/@kbeauty.bliss');
    expect($hrefs)->toContain('https://www.facebook.com/kbeautyblissuae');
});

it('drops a social icon the owner has deliberately cleared', function () {
    dlSet('social_instagram', '');

    $hrefs = dlSocialHrefs(dlHome());

    expect($hrefs)->not->toContain('https://www.instagram.com/kbeauty.bliss/');
    // The others are untouched.
    expect($hrefs)->toContain('https://www.tiktok.com/@kbeauty.bliss');
});
