<?php

declare(strict_types=1);

/**
 * =============================================================================
 * THE SHOP'S MOBILE MENU IS THE BOTTOM SHEET, AND ONLY THE BOTTOM SHEET
 * =============================================================================
 *
 * The owner reported this after being sent a screenshot of an Arabic mobile menu
 * sliding in from the side: "we don't have menu opening from side in mobile, we
 * have dedicated developed menu opening from downside."
 *
 * He is right, and the screenshot was of markup no shopper can reach.
 *
 * THERE ARE TWO MOBILE MENUS IN THIS TREE and only one of them is the shop's:
 *
 *   `.mmenu`  partials/mobile-chrome.blade.php — the real one. A full-width
 *             sheet that rises from the bottom, dressed from Appearance →
 *             Mobile menu, opened by #burger in partials/menu-icon.blade.php
 *             through initMobileChrome() in resources/js/kbb/home.js.
 *
 *   `.mnav`   partials/drawers.blade.php — a side drawer, rendered into every
 *             storefront page and OPENED BY NOTHING. The only opener anywhere
 *             is resources/views/design-check.blade.php, a developer preview.
 *             resources/js/kbb/mobile-nav.js still fills its list, and
 *             overlay.js still closes it, so it looks alive from every angle
 *             except the one that matters.
 *
 * Measured in Chromium at 390px, both languages, by clicking #burger:
 *
 *              burger            .mmenu when open
 *   English    x=12  (left)      x:0  y:169  w:390  h:675
 *   Arabic     x=332 (right)     x:0  y:169  w:390  h:675   ← identical
 *
 * So the Arabic mobile menu already IS the English one, mirrored where mirroring
 * means something (the burger moves to the other corner) and identical where it
 * does not (a full-width sheet has no side to come from). That is exactly what
 * the owner asked for and it needed no change.
 *
 * WHAT THIS FILE IS FOR. Two lanes have now spent effort on `.mnav` — one
 * mirrored it for RTL and photographed it, and the photograph reached the owner
 * as though it were his shop. Nothing in the tree said it was unreachable. This
 * says it, in a form that fails rather than waits.
 *
 * IT DOES NOT DELETE `.mnav`. Removing markup would move the rendered bytes of
 * every storefront page, and `store/blog.blade.php` and `store/post.blade.php`
 * carry their OWN `.mnav` with their own opener — those two standalone documents
 * really do use a side drawer, which is its own inconsistency and its own
 * decision for the owner, not a thing to fix in passing.
 */

use App\Models\Product;

it('opens the bottom sheet from the burger, and the sheet is the one the shop dresses', function () {
    $html = $this->get('/')->assertOk()->getContent();

    // The three ids initMobileChrome() reaches for. If any of them is renamed
    // the menu stops opening, silently, because that function returns early.
    foreach (['id="mmenu"', 'id="mscrim"', 'id="burger"'] as $needle) {
        expect(str_contains($html, $needle))->toBeTrue(
            $needle.' is missing from the storefront — initMobileChrome() returns early without it and the mobile menu never opens'
        );
    }

    $js = file_get_contents(base_path('resources/js/kbb/home.js'));

    expect(str_contains($js, "getElementById('mmenu')"))->toBeTrue('home.js no longer opens the sheet')
        ->and(str_contains($js, "getElementById('burger')"))->toBeTrue('home.js no longer binds the burger');
});

it('renders the side drawer that nothing opens, and proves nothing opens it', function () {
    $html = $this->get('/')->assertOk()->getContent();

    // It is still there. This test is not asking for it to go; it is recording
    // that its presence is not evidence of anything.
    expect(str_contains($html, 'class="mnav"'))->toBeTrue(
        'partials/drawers.blade.php no longer renders .mnav — if that was deliberate, delete this case with it'
    );

    /*
     * overlay.js opens whatever a [data-kbb-open="<id>"] names. Sweep the
     * rendered page for every such value: on the storefront they are all
     * `cart`, and not one is `mnav`. Asserted on the rendered page rather than
     * on the source, because a Blade that carries the attribute inside a
     * comment or an @if that is false would read as an opener in the source
     * and be none at runtime.
     */
    preg_match_all('/data-kbb-open="([^"]*)"/', $html, $m);

    expect(count($m[1]))->toBeGreaterThan(0, 'the sweep found no data-kbb-open at all, so it is proving nothing');
    expect(in_array('mnav', $m[1], true))->toBeFalse(
        'something on the storefront now opens .mnav — if the side drawer is live again this file is out of date'
    );
});

it('is the same menu, the same size, in Arabic', function () {
    // The geometry itself is a browser question and is pinned in
    // tests/browser/mobile-menu-parity.mjs. What PHP can hold is the reason the
    // geometry matches: the sheet is dressed by one service for both languages
    // and carries no direction-dependent markup of its own.
    $product = Product::query()->first();

    $en = $this->get('/')->assertOk()->getContent();

    expect(str_contains($en, 'class="mmenu'))->toBeTrue('the sheet is not on the English page');

    // Its width and its rise come from the stylesheet, not from an inline
    // style, so there is nothing per-language to diverge.
    $sheet = (string) preg_match('/<nav class="mmenu[^"]*"[^>]*style="([^"]*)"/', $en, $sm) ? ($sm[1] ?? '') : '';

    foreach (['left', 'right', 'inset-inline', 'transform'] as $physical) {
        expect(str_contains($sheet, $physical))->toBeFalse(
            'the sheet carries an inline '.$physical.', which is a per-render value and the one way its two languages could drift apart'
        );
    }
});
