<?php

declare(strict_types=1);

use App\Models\Product;

/*
 * The storefront as a shopper on a phone gets it.
 *
 * K-Beauty Bliss sells into the UAE and the Gulf, where almost all shopping
 * traffic is mobile, and nobody had walked the buying path at phone width
 * before this lane did. What it found, measured in Chromium at 360x740,
 * 430x930 and 768x1024, is pinned here:
 *
 *   1. iOS Safari zooms the whole page when a text field under 16px takes
 *      focus, and does not zoom back out. The header search already knew that
 *      -- `header .sbox .search-in input` is raised to 16px in kbb.css under a
 *      comment saying so. Every OTHER field on the site was still under it:
 *      the menu filter, the newsletter, the sign-in, register and
 *      order-tracking fields at the browser's 13.33px default, the shop's sort
 *      dropdown at 13px, the review form at 14px and all thirteen checkout
 *      fields at 14px. Tapping "Email address" anywhere but that one search
 *      box left the form half off-screen.
 *
 *   2. overlay.js has put `kbb-locked` on <body> whenever a drawer opens since
 *      T-CHROME-15, commented "Stops the page behind a drawer from scrolling
 *      on touch devices". No stylesheet ever defined the class. Adding to the
 *      bag on a phone opened the cart drawer over a page that kept scrolling
 *      underneath it (measured: scrollY 837 -> 1337 on one flick).
 *
 *   3. `.shop{padding:22px 0 60px}` and the markup `<div class="wrap shop">`
 *      are both one class, and `.shop` is written later, so its shorthand `0`
 *      wiped `.wrap{padding:0 20px}`. The shop, category and search grids lost
 *      their side gutter at every width under the 1240px max-width; on a phone
 *      the cards' borders sat on the first and last pixel of the screen.
 *
 *   4. `font:400 14px inherit` is not valid CSS -- `inherit` cannot stand in
 *      for the family inside the shorthand -- so the browser dropped the whole
 *      declaration and .fld input, .mm-srch input and the newsletter field
 *      rendered in Arial at 13.33px, while .go (the sign-in button) lost its
 *      700 weight.
 *
 * WHY THIS ASSERTS THE WAY IT DOES. Two halves, deliberately.
 *
 * The rendered half requests pages and reads ELEMENTS out of the bytes that
 * came back, with <style> and <script> stripped first. Several storefront
 * views inline their stylesheet, so a bare search for a class name matches the
 * CSS rather than the markup and is true whatever the page does -- that trap
 * has caught lanes here before. Every pattern below matches a tag with its
 * attributes.
 *
 * The stylesheet half reads the source sheet AND the compiled bundle the
 * manifest points at, both as git has them, the same way
 * ProductMobileLayoutTest and BuildAssetsTest do. This repo's signature
 * failure is a fix that is real in one half and stale in the other: a
 * stylesheet edit without `npx vite build` changes nothing a shopper sees, and
 * a migration in this suite deletes public/build as a side effect, which is
 * why these read committed content and not the working tree.
 *
 * Note on style: assertions that carry an explanation use toBeTrue/toBeFalse,
 * because Pest's toContain() is variadic -- a second string there is read as
 * another needle, not as a message.
 */

/** The served page with style, script and comment text removed. */
function phoneMarkup(string $html): string
{
    return (string) preg_replace(
        ['#<style\b[^>]*>.*?</style>#is', '#<script\b[^>]*>.*?</script>#is', '#<!--.*?-->#s'],
        '',
        $html
    );
}

/** Committed content for a path, as git has it. What ships is what is committed. */
function phoneTracked(string $path): ?string
{
    $out = shell_exec('git -C '.escapeshellarg(base_path()).' show HEAD:'.escapeshellarg($path).' 2>/dev/null');

    return ($out === null || $out === '') ? null : $out;
}

/**
 * Whitespace collapsed away and attribute-selector quotes dropped, so one
 * needle matches both the readable source and esbuild's output --
 * `input[type="email"]` in the sheet is `input[type=email]` in the bundle.
 */
function phoneFlat(?string $css): string
{
    return str_replace(['"', "'"], '', (string) preg_replace('/\s+/', '', (string) $css));
}

/** $needle, written readably, looked for in an already-flattened haystack. */
function phoneHas(string $flat, string $needle): bool
{
    return str_contains($flat, phoneFlat($needle));
}

/**
 * A stylesheet in both the forms that can disagree: the source this repo edits
 * and the bundle the manifest sends to a browser.
 *
 * @return array<string,string> keyed by the name to name when one of them fails
 */
function phoneBothHalves(string $entry): array
{
    $manifest = json_decode((string) phoneTracked('public/build/manifest.json'), true);

    expect($manifest)->toBeArray()->toHaveKey($entry);

    $file = $manifest[$entry]['file'] ?? null;
    expect($file)->not->toBeNull();

    $built = phoneTracked('public/build/'.$file);
    expect($built)->not->toBeNull(
        "public/build/{$file} is referenced by the manifest but is not committed.");

    return [
        $entry.' (source)' => phoneFlat(phoneTracked($entry)),
        $entry.' (built bundle)' => phoneFlat($built),
    ];
}

/** The pages a shopper actually passes through, including a filled checkout. */
function phonePages(): array
{
    $product = Product::query()->visible()->where('stock_status', 'instock')->firstOrFail();

    test()->post('/api/cart/add', ['product_id' => $product->id, 'quantity' => 1]);

    return [
        '/',
        '/shop/',
        '/product/'.$product->slug.'/',
        '/cart/',
        '/checkout/',
        '/my-account/',
        '/track-my-order/',
        '/my-wishlist/',
        '/korean-skincare-brands/',
        '/delivery/',
        '/faqs/',
        '/refund_returns/',
        '/contact-us/',
        '/about/',
        '/skin-quiz/',
    ];
}

/*
|------------------------------------------------------------------------------
| The rendered half
|------------------------------------------------------------------------------
*/

it('gives every storefront page a viewport that fits the device and still pinches', function () {
    foreach (phonePages() as $url) {
        $response = test()->get($url);

        expect($response->getStatusCode())->toBe(200, $url.' did not render');

        preg_match_all(
            '/<meta\b[^>]*\bname=["\']viewport["\'][^>]*>/i',
            phoneMarkup((string) $response->getContent()),
            $m
        );

        expect(count($m[0]))->toBe(1, $url.' should emit exactly one viewport meta tag');

        $tag = $m[0][0];

        expect(str_contains($tag, 'width=device-width'))->toBeTrue(
            $url.' no longer sizes the layout viewport to the device, so a phone renders it at '.
            'the 980px desktop width and scales the whole page down.');

        /*
         * And it must NOT lock zoom. maximum-scale=1 / user-scalable=no is the
         * wrong cure for the 14px fields below: it stops iOS zooming on focus
         * by stopping a shopper zooming at all, which is a WCAG 1.4.4 failure
         * and is exactly what the CSS rules further down make unnecessary.
         */
        expect(preg_match('/maximum-scale|user-scalable/i', $tag))->toBe(0,
            $url.' pins the zoom level. Sizing the fields at 16px is the fix; taking pinch-zoom '.
            'away from the shopper is not.');
    }
});

it('reserves space for every image it renders, so nothing jumps as photographs arrive', function () {
    $product = Product::query()->visible()->firstOrFail();
    $product->forceFill(['image' => 'https://cdn.example.com/serum.jpg'])->save();

    $response = test()->get('/product/'.$product->slug.'/');

    expect($response->getStatusCode())->toBe(200);

    preg_match_all('/<img\b[^>]*>/i', phoneMarkup((string) $response->getContent()), $m);

    expect(count($m[0]))->toBeGreaterThan(0, 'the product page rendered no <img> to check');

    foreach ($m[0] as $tag) {
        $sized = preg_match('/\bwidth=["\']?\d/i', $tag) === 1
            && preg_match('/\bheight=["\']?\d/i', $tag) === 1;

        expect($sized)->toBeTrue(
            'This <img> carries no intrinsic width and height, so the browser lays the page out '.
            'at zero height for it and shoves the content below down when the file arrives: '.
            $tag);
    }
});

it('puts no element on a storefront page wider than a small phone', function () {
    /*
     * An inline width or min-width in px is the one form of "wider than the
     * screen" that no media query can rescue, because it is on the element and
     * beats every stylesheet. 360px is the narrowest phone this shop should
     * expect; anything above it scrolls the page sideways.
     */
    $styled = 0;

    foreach (phonePages() as $url) {
        $html = phoneMarkup((string) test()->get($url)->getContent());

        preg_match_all('/<[a-z][a-z0-9-]*\b[^>]*\bstyle=["\']([^"\']*)["\'][^>]*>/i', $html, $m);
        $styled += count($m[0]);

        foreach ($m[0] as $i => $tag) {
            preg_match_all('/(?<![a-z-])(min-width|width)\s*:\s*(\d+(?:\.\d+)?)px/i', $m[1][$i], $w);

            foreach ($w[2] as $k => $px) {
                expect((float) $px)->toBeLessThanOrEqual(360.0,
                    $url.' renders an element with an inline '.$w[1][$k].' of '.$px.'px, which is '.
                    'wider than a 360px phone and cannot be overridden from a stylesheet: '.$tag);
            }
        }
    }

    /*
     * The storefront carries plenty of inline style -- the header, the product
     * cards and the drawers all take their appearance from admin settings that
     * way -- so a run that found none has stopped reading the markup rather
     * than proved it clean.
     */
    expect($styled)->toBeGreaterThan(0,
        'no element with an inline style attribute was found on any page, so this test scanned '.
        'nothing. Check that phoneMarkup() is not stripping the markup along with the <style>.');
});

it('hands no text field to iOS at a size that zooms the page', function () {
    /*
     * The rendered half of rule 1. A stylesheet cannot be read from here, but
     * an inline font-size can, and an inline one is the version a later CSS
     * fix cannot reach. Checkboxes and radios are excluded on purpose: they
     * take no text entry, so iOS never zooms for them.
     */
    $fields = 0;

    foreach (phonePages() as $url) {
        $html = phoneMarkup((string) test()->get($url)->getContent());

        preg_match_all('/<(?:input|select|textarea)\b[^>]*>/i', $html, $m);

        foreach ($m[0] as $tag) {
            if (preg_match('/\btype=["\']?(checkbox|radio|hidden|submit|button|file|range)/i', $tag)) {
                continue;
            }

            $fields++;

            if (preg_match('/\bstyle=["\'][^"\']*font-size\s*:\s*(\d+(?:\.\d+)?)px/i', $tag, $f)) {
                expect((float) $f[1])->toBeGreaterThanOrEqual(16.0,
                    $url.' renders a text field at an inline '.$f[1].'px. iOS Safari zooms the '.
                    'whole page when a field under 16px takes focus and does not zoom back: '.$tag);
            }
        }
    }

    /*
     * No storefront field carries an inline size today, and the point of the
     * assertion above is that none ever starts to -- an inline size is the one
     * version a later stylesheet fix cannot reach. What has to be pinned is
     * that the scan really walks the fields: the checkout renders thirteen on
     * its own, so a run that found none would pass while checking nothing.
     */
    expect($fields)->toBeGreaterThanOrEqual(20,
        'only '.$fields.' text fields were found across the whole buying path. The checkout '.
        'carries thirteen on its own, so this scan is no longer reading the forms.');
});

/*
|------------------------------------------------------------------------------
| The stylesheet half
|------------------------------------------------------------------------------
*/

it('sizes every text field on the storefront so an iPhone does not zoom on focus', function () {
    // The shared sheet: the menu filter, the newsletter, sign-in, register and
    // order tracking.
    foreach (phoneBothHalves('resources/css/kbb/kbb.css') as $where => $flat) {
        expect(str_contains($flat, '@media(max-width:820px){'))->toBeTrue(
            "The {$where} no longer carries the phone block that raises the fields to 16px.");

        expect(phoneHas($flat, 'input[type="email"],input[type="tel"],'))->toBeTrue(
            "The {$where} no longer names the text input types in that block.");

        expect(phoneHas($flat, '.search-in input,.mnav-search input,.mm-srch input,'.
            '.kbb-home .nl .f input,.fld input{font-size:16px}'))->toBeTrue(
            "The {$where} no longer raises the menu filter, the newsletter and the sign-in ".
            'fields to 16px, so tapping any of them zooms an iPhone. These are named on top of '.
            'the element selectors above because each carries a class selector, which beats them.');
    }

    /*
     * And the rule this one finishes: the header's own search box was raised
     * to 16px by an earlier lane, for exactly the same reason. If that rule
     * ever goes, the search box goes back to 13.5px and the block above will
     * not catch it -- `header .sbox .search-in input` is more specific than
     * anything in it.
     */
    foreach (phoneBothHalves('resources/css/kbb/kbb.css') as $where => $flat) {
        expect(phoneHas($flat, 'header .sbox .search-in input{font-size:16px}'))->toBeTrue(
            "The {$where} no longer raises the header search box to 16px.");
    }

    // Checkout, which loads after kbb.css and therefore has to repeat it.
    foreach (phoneBothHalves('resources/css/kbb/kbb-checkout.css') as $where => $flat) {
        expect(phoneHas($flat, '.kbb-checkout .form-row select{font-size:16px}'))->toBeTrue(
            "The {$where} no longer raises the checkout fields to 16px. This is the form the ".
            'shop is paid through, and it was the worst case: thirteen fields at 14px.');

        expect(phoneHas($flat, '.kbb-checkout .form-row input[type="email"],'))->toBeTrue(
            "The {$where} no longer raises the checkout's email field, which is the first one a ".
            'shopper taps.');
    }

    // The shop's sort control, and the review form's sheet.
    foreach (phoneBothHalves('resources/css/kbb/kbb-shop.css') as $where => $flat) {
        expect(phoneHas($flat, '.sortsel select{font-size:16px}'))->toBeTrue(
            "The {$where} no longer raises the sort dropdown, which was 13px.");
    }

    foreach (phoneBothHalves('resources/css/kbb/sorina-reviews.css') as $where => $flat) {
        expect(phoneHas($flat, '.sr-fld textarea{font-size:16px}'))->toBeTrue(
            "The {$where} no longer raises the review form's fields, which were 14px.");
    }
});

it('stops the page scrolling away underneath an open drawer', function () {
    foreach (phoneBothHalves('resources/css/kbb/kbb.css') as $where => $flat) {
        expect(str_contains($flat, 'body.kbb-locked{overflow:hidden}'))->toBeTrue(
            "The {$where} does not define body.kbb-locked. overlay.js adds that class every time ".
            'a drawer opens and nothing else acts on it, so without this rule the class is '.
            'decoration and the page keeps scrolling behind the cart drawer.');
    }

    /*
     * The other half of the same fix: the class this rule styles is really put
     * on <body>, and by the code that opens the drawers. If overlay.js ever
     * stops adding it, the rule above is dead weight and should go with it.
     */
    $overlay = (string) phoneTracked('resources/js/kbb/overlay.js');

    expect(str_contains($overlay, "classList.add('kbb-locked')"))->toBeTrue(
        'overlay.js no longer locks the body when a drawer opens.');
    expect(str_contains($overlay, "classList.remove('kbb-locked')"))->toBeTrue(
        'overlay.js no longer unlocks the body when the drawers close, which would leave a '.
        'shopper on a page that cannot scroll at all.');
});

it('keeps a side gutter under the product grid on a phone', function () {
    foreach (phoneBothHalves('resources/css/kbb/kbb-shop.css') as $where => $flat) {
        /*
         * Either spelling of the same rule.
         *
         * T6 rewrote the storefront's physical direction properties as logical
         * ones — `padding-inline-start`/`-end` here — which resolve to exactly
         * these values in a left-to-right document. The committed bundle under
         * public/build still carries the physical spelling, because asset
         * builds in this project are manual and public/build is the record of
         * what the server currently has.
         *
         * So the two halves this loop compares now spell the property
         * differently while meaning the same thing. What must not differ, and
         * what this still pins, is that BOTH halves carry the override at all.
         */
        expect(
            phoneHas($flat, '.wrap.shop{padding-left:16px;padding-right:16px}')
            || phoneHas($flat, '.wrap.shop{padding-inline-start:16px;padding-inline-end:16px}')
        )->toBeTrue(
            "The {$where} no longer restores the shop grid's side gutter on phones.");
    }

    /*
     * And the reason it is needed, pinned so that this override cannot outlive
     * it: `.shop`'s padding shorthand still zeroes the horizontal padding that
     * `.wrap` sets. Both selectors are one class and `.shop` is written later,
     * so the shorthand wins. If that ever changes, the rule above is a second
     * gutter rather than the first one and should be removed.
     */
    $source = phoneFlat(phoneTracked('resources/css/kbb/kbb-shop.css'));

    expect(phoneHas($source, '.wrap{max-width:1240px;margin:0 auto;padding:0 20px}'))->toBeTrue(
        'kbb-shop.css no longer gives .wrap a 20px gutter, so the override may be unnecessary.');
    expect(phoneHas($source, '.shop{display:grid;grid-template-columns:250px 1fr;gap:28px;'.
        'padding:22px 0 60px}'))->toBeTrue(
        '.shop no longer zeroes its horizontal padding with a shorthand, so the override above '.
        'is now adding a second gutter rather than restoring the only one.');
});

it('writes the form-field font in longhands that a browser actually applies', function () {
    /*
     * `font: <weight> <size> inherit` parses as invalid and is dropped whole.
     * These four rules are the ones where that cost a shopper something
     * visible: three form fields fell back to Arial at the UA's 13.33px, and
     * the sign-in button lost its 700 weight.
     */
    foreach (phoneBothHalves('resources/css/kbb/kbb.css') as $where => $flat) {
        foreach ([
            '.fld input' => 'font-family:inherit;font-weight:400;font-size:14px',
            '.go' => 'font-family:inherit;font-weight:700;font-size:14px',
            '.mm-srch input' => 'font-family:inherit;font-weight:400;font-size:12.5px',
            '.kbb-home .nl .f input' => 'font-family:inherit;font-weight:400;font-size:14px',
        ] as $selector => $longhands) {
            expect(phoneHas($flat, $longhands))->toBeTrue(
                "The {$where} no longer sets {$selector}'s font in longhands. The shorthand form ".
                '`font:400 14px inherit` is invalid CSS -- `inherit` is not a family -- so the '.
                'browser throws the whole declaration away and the element falls back to Arial '.
                'at the 13.33px UA default.');
        }
    }
});

it('gives an owner-written policy table and a long tracking number somewhere to go', function () {
    /*
     * The five footer pages print whatever is typed into Store -> Pages, and a
     * delivery or returns page is exactly where a five-column charges table
     * and a courier URL end up. Measured at 360px before this: the URL ran off
     * the column and was clipped mid-number.
     */
    foreach (phoneBothHalves('resources/css/kbb/kbb.css') as $where => $flat) {
        expect(phoneHas($flat, '.kbb-home .policy-body{overflow-wrap:break-word}'))->toBeTrue(
            "The {$where} no longer lets a long unbroken string in a policy page wrap.");

        expect(phoneHas($flat,
            '.kbb-home .policy-body table{display:block;max-width:100%;overflow-x:auto}'))->toBeTrue(
            "The {$where} no longer gives a policy-page table a scroll container of its own, so a ".
            'table too wide to shrink pushes the whole page sideways instead of scrolling inside '.
            'its own box.');
    }

    // The container those rules hang off is really the one the template renders.
    $page = (string) phoneTracked('resources/views/store/page.blade.php');

    expect(str_contains($page, '<div class="policy-body">'))->toBeTrue(
        'store/page.blade.php no longer renders .policy-body, so both rules above target nothing.');
});
