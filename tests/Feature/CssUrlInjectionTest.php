<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\CssUrl;

/**
 * A product image address becomes CSS, and e() is the wrong escaper for CSS.
 *
 * ── THE DEFECT, ON THE SHOP ─────────────────────────────────────────────────
 *
 * The product page draws a variant swatch with
 *
 *     <span class="vsw" style="background-image:url('{{ $v->image }}')">
 *
 * `{{ }}` is e(), the HTML escaper. A browser reads a style attribute in two
 * stages — the HTML parser decodes the entities, and only then does the CSS
 * parser read what is left — so e()'s `&#39;` arrives at CSS as `'`, closes the
 * `url(`, and everything after it is read as further declarations. Give a
 * variation the image address
 *
 *     /media/x.jpg');background:url(https://theirs.test/pixel.png
 *
 * and the swatch fetches theirs.test from the shopper's browser, carrying the
 * product page as its referrer. `it renders an address with a quote as one
 * declaration` below is that exact address on a real rendered page.
 *
 * ── HOW BIG IT IS, MEASURED AND NOT ASSUMED ─────────────────────────────────
 *
 * CSS-context injection, NOT script injection. e() still escapes `"`, `<` and
 * `>`, so the style attribute cannot be closed and no tag can be opened. What
 * it buys is one rule of somebody else's choosing on that element: an outbound
 * request, or a restyle that covers something up. `it cannot close the
 * attribute or open a tag` pins that boundary, so nobody reads this file as an
 * XSS and nobody reads it as nothing.
 *
 * ── AND ONLY TWO OF THE THIRTEEN SITES COULD BE REACHED ─────────────────────
 *
 * Eleven of the thirteen build the declaration in PHP with e($img) and then
 * print the whole string through `{{ }}`, which is e() a SECOND time with
 * double-encoding on. `it shows why the e()-then-{{ }} sites were not
 * reachable` runs both compositions side by side: one escape gives CSS a live
 * quote, two give it the seven harmless characters `&#039;` inside the quoted
 * url.
 * Those eleven were safe by accident — an accident that one
 * `Blade::withoutDoubleEncoding()` in a service provider, or one declaration
 * moved into `{!! !!}`, would undo for all of them at once.
 *
 * Of the two single-escaped sites, only the variant swatch is on a page anyone
 * can reach: `partials/product-reviews.blade.php` is an orphan — an earlier,
 * mismatched port that nothing includes (the live one is `partials/reviews`,
 * which uses <img src> and no CSS at all). It is fixed here anyway, because
 * "dead today" is not a property a template keeps.
 *
 * ── WHY NOW ─────────────────────────────────────────────────────────────────
 *
 * Every one of these addresses is a product, review, Instagram or set image,
 * and the WordPress import is about to write thousands of them from a database
 * this shop did not author. Today the values are ones the owner typed.
 *
 * ── MUTATION NOTES, ALL RUN ─────────────────────────────────────────────────
 *
 * Run as `vendor/bin/pest tests/Feature/CssUrlSitesGuardTest.php
 * tests/Feature/CssUrlInjectionTest.php`, 17 cases green to begin with.
 *
 * 1. Put store/product.blade.php's swatch back to
 *    `@if ($v->image)…url('{{ $v->image }}')`. RUN: 4 failed — the sweep in
 *    CssUrlSitesGuardTest, plus 'it renders an address with a quote as one
 *    declaration' (the page carried
 *    `url('/media/x.jpg');background:url(https://theirs.test/pixel.png')` once
 *    decoded — a second declaration, exactly as described above), 'it cannot
 *    close the attribute or open a tag' and 'it draws nothing for an address
 *    whose scheme this shop does not serve'.
 * 2. Drop the schemeIsServed() call from CssUrl::value(). RUN: 2 failed — 'it
 *    refuses what it cannot serve' and the rendered 'it draws nothing…'.
 * 3. Drop the strtr() from CssUrl::escape() and return $url unescaped. RUN:
 *    4 failed — 'it escapes what it can serve', 'it escapes a backslash exactly
 *    once', and both rendered cases.
 * 4. In CssUrl::escape(), replace the single strtr() with two str_replace()
 *    passes that escape the four delimiters FIRST and the backslash after.
 *    RUN: the same 4 failed. `/media/a');…` came out `/media/a\\';…` — a
 *    CSS-escaped backslash followed by a LIVE quote, which is the breakout
 *    walking back in through the repair. This is why the escaping is one pass.
 * 5. Change CssUrl::value()'s `if (! $raw)` to `if ($raw === null)`. RUN: 1
 *    failed — 'it treats the three no-picture values the templates already
 *    treated as no picture', on '0'. That row is what keeps this a drop-in:
 *    every call site's ternary already sent '0' to the gradient.
 * 6. In CssUrl::escape(), put the C1 range back into the control-character
 *    class and add /u. RUN: 1 failed — 'it leaves a non-ASCII address alone',
 *    where U+0085 came back as `\c2 ` with its second byte gone.
 *
 * ▲ ONE MUTATION THAT DOES NOT GO RED, recorded because a claim nobody can
 * check is what rule 6 is against. Two str_replace() passes in the OTHER order
 * — backslash first, delimiters second — leave all 17 green, and that is
 * correct rather than a gap: the second pass touches only quotes and parens, so
 * it cannot re-scan what the first one wrote. The single strtr() is kept
 * because it makes the order unable to be got wrong, which mutation 4 shows is
 * the whole of the risk.
 */

/** A visible variable product whose one option carries $image. */
function cuProduct(string $slug, ?string $image): Product
{
    $product = Product::create([
        'slug' => $slug,
        'name' => 'CU '.strtoupper($slug),
        'status' => 'publish',
        'is_visible' => true,
        'price' => null,
        'stock_status' => 'instock',
        'type' => 'variable',
    ]);

    ProductVariant::create([
        'product_id' => $product->id,
        'price' => 12000,
        'stock_status' => 'instock',
        'image' => $image,
    ]);

    return $product;
}

/**
 * The product page as the CSS PARSER sees it: fetched, then HTML-decoded once,
 * which is the step e() was relied on to survive and does not.
 *
 * Decoding the whole document rather than picking the attribute out with a
 * parser is deliberate — a parser would quietly repair the very thing under
 * test — and it is the same single decode a browser performs on the attribute.
 */
function cuCssText(Product $product): string
{
    $html = (string) test()->get('/product/'.$product->slug.'/')->assertOk()->getContent();

    return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/* ─────────────────────── what the helper does ────────────────────────────── */

it('leaves an address that was already safe byte-for-byte alone', function () {
    // The parity half of rule 1: routing every site through the helper may not
    // move one byte of any page that renders today. It cannot, if this holds.
    foreach ([
        '/media/2026/heartleaf-toner.jpg',
        '/uploads/products/20260916-101600-def456.png',
        'https://cdn.example.com/serum.jpg',
        'https://cdn.test/a.jpg?w=600&h=600#x',
        'http://cdn.test/a.jpg',
        'HTTPS://CDN.TEST/A.JPG',
        'products/relative.jpg',
        '?v=2',
    ] as $safe) {
        expect(CssUrl::value($safe))->toBe($safe);
    }
});

it('leaves a non-ASCII address alone', function () {
    // A UTF-8 path is ordinary in an imported catalogue and means nothing to the
    // CSS tokeniser. Mangling one would break a picture that works today.
    expect(CssUrl::value('/media/ünïcode/крем.jpg'))->toBe('/media/ünïcode/крем.jpg');

    /*
     * U+0085 is in the C1 range, which the scheme probe strips and the escaper
     * deliberately leaves alone. It is TWO BYTES in UTF-8, so an escaper that
     * matched it with /u and called ord() would emit `\c2 ` and drop the
     * second byte — a mangled address where a sound one went in. No C1
     * character means anything to the CSS tokeniser.
     */
    expect(CssUrl::value("/media/a\u{0085}b.jpg"))->toBe("/media/a\u{0085}b.jpg");
});

it('treats the three no-picture values the templates already treated as no picture', function () {
    // null, '' and '0' are what `$img ? … : gradient` already sent to the
    // gradient. The helper has to agree or it changes which branch a page takes.
    expect(CssUrl::value(null))->toBe('')
        ->and(CssUrl::value(''))->toBe('')
        ->and(CssUrl::value('0'))->toBe('');
});

it('refuses what it cannot serve', function () {
    foreach ([
        'javascript:alert(1)',
        'JavaScript:alert(1)',
        // Entity-encoded and whitespace-split, because a browser resolves both
        // to a javascript URL and str_starts_with() does not.
        'jav&#x09;ascript:alert(1)',
        "java\nscript:alert(1)",
        'data:image/svg+xml;base64,PHN2Zz48c2NyaXB0Pg==',
        'vbscript:msgbox(1)',
        // No scheme, and not relative either: somebody else's host wearing this
        // page's scheme.
        '//evil.test/pixel.png',
    ] as $refused) {
        expect(CssUrl::value($refused))->toBe('', $refused.' should be refused');
    }
});

it('escapes what it can serve', function () {
    // The quote is the breakout. The parenthesis closes an unquoted url(). The
    // newline ends a CSS string and hands what follows to the parser as a fresh
    // declaration. None of them is a reason to refuse an otherwise fine path.
    expect(CssUrl::value("/media/a');background:url(https://theirs.test/p.png"))
        ->toBe("/media/a\\'\\);background:url\\(https://theirs.test/p.png")
        ->and(CssUrl::value('/media/a"b.jpg'))->toBe('/media/a\\"b.jpg')
        ->and(CssUrl::value('/media/a(b).jpg'))->toBe('/media/a\\(b\\).jpg')
        ->and(CssUrl::value("/media/a\nb.jpg"))->toBe('/media/a\\a b.jpg')
        ->and(CssUrl::value("/media/a\rb.jpg"))->toBe('/media/a\\d b.jpg')
        ->and(CssUrl::value("/media/a\tb.jpg"))->toBe('/media/a\\9 b.jpg');
});

it('escapes a backslash exactly once', function () {
    // Two str_replace() passes would re-scan the backslashes the first pass
    // wrote and turn `\'` into `\\\'` — a CSS-escaped backslash followed by a
    // live quote, which is the breakout coming back through the repair.
    expect(CssUrl::value('/media/a\\b.jpg'))->toBe('/media/a\\\\b.jpg')
        ->and(CssUrl::value("/media/\\'.jpg"))->toBe("/media/\\\\\\'.jpg");
});

/* ─────────────────── the composition, on rendered pages ──────────────────── */

it('renders an address with a quote as one declaration', function () {
    /*
     * THE DEFECT ON THE SHOP. Before this lane the swatch printed
     *   background-image:url('/media/x.jpg');background:url(https://theirs.test/pixel.png')
     * once the browser had decoded the attribute — two declarations, the second
     * of them somebody else's outbound request.
     */
    $product = cuProduct('cu-quote', "/media/x.jpg');background:url(https://theirs.test/pixel.png");

    $css = cuCssText($product);

    expect(str_contains($css, 'theirs.test'))->toBeTrue(
        'the address should still be drawn, escaped — refusing it would hide the test'
    );
    expect(str_contains($css, "');background:url(https://theirs.test"))->toBeFalse(
        'the quote closed the url() and started a second declaration'
    );
    expect(str_contains($css, "url('/media/x.jpg\\'\\);background:url\\(https://theirs.test/pixel.png')"))->toBeTrue(
        'the whole address should sit inside one url(), with its quote and paren escaped'
    );
});

it('cannot close the attribute or open a tag', function () {
    // The boundary of the finding, pinned so it is neither overstated nor
    // dismissed: e() at the call site still owns `"`, `<` and `>`.
    $product = cuProduct('cu-tag', '/media/y.jpg" onmouseover="alert(1)"><script>alert(1)</script>');

    $html = (string) test()->get('/product/'.$product->slug.'/')->assertOk()->getContent();

    // The words are still in the page — escaped, inert, inside the url(). What
    // must not be there is a LIVE `"` that ends style= and starts a handler, or
    // a live `<` that starts a tag. Asserting on the words alone would go red
    // on a correct page, which is its own kind of broken test.
    expect(str_contains($html, 'y.jpg" onmouseover'))->toBeFalse('an attribute was opened');
    expect(str_contains($html, '<script>alert(1)</script>'))->toBeFalse('a tag was opened');
    expect(str_contains($html, 'y.jpg\\&quot; onmouseover=\\&quot;'))->toBeTrue(
        'e() at the call site still owns the double quote, and this is what that looks like'
    );
});

it('draws nothing for an address whose scheme this shop does not serve', function () {
    // Refused, so the swatch falls back to the plain .vr dot rather than
    // rendering url('') — which resolves against the document and makes the
    // browser fetch the product page itself as an image.
    $product = cuProduct('cu-scheme', 'javascript:alert(1)');

    $html = (string) test()->get('/product/'.$product->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'javascript:alert(1)'))->toBeFalse('a refused scheme reached the page');
    expect(str_contains($html, "url('')"))->toBeFalse('an empty url() makes the browser fetch the page as an image');
    expect(str_contains($html, '<span class="vr"></span>'))->toBeTrue('the swatch should fall back to the plain dot');
});

it('still draws an ordinary address', function () {
    $product = cuProduct('cu-plain', '/media/2026/snail.jpg');

    $html = (string) test()->get('/product/'.$product->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, "background-image:url('/media/2026/snail.jpg')"))->toBeTrue(
        'the swatch should draw exactly the bytes it drew before this lane'
    );
});

it('shows why the e()-then-{{ }} sites were not reachable', function () {
    /*
     * MEASURED, because the claim "eleven of the thirteen were safe by
     * accident" is load-bearing for how this finding is described and a claim
     * nobody can check is what rule 6 is against.
     *
     * Both columns are the SAME address. The left is one e() — literal quotes
     * written in the template, which is the swatch. The right is e() inside the
     * PHP concatenation and then `{{ }}` over the whole declaration, which is
     * the other eleven. Only the left hands CSS a live quote.
     */
    $address = "/media/x.jpg');background:url(https://theirs.test/pixel.png";

    $single = "background-image:url('".e($address)."')";
    $double = e("background-image:url('".e($address)."')");

    $singleAsCssSeesIt = html_entity_decode($single, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $doubleAsCssSeesIt = html_entity_decode($double, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect(str_contains($singleAsCssSeesIt, "');background:url(https://theirs.test"))->toBeTrue(
        'one escape lets the quote back through — this is the defect'
    );
    expect(str_contains($doubleAsCssSeesIt, "');background:url(https://theirs.test"))->toBeFalse(
        'two escapes leave CSS the literal characters &#039; inside the quoted url'
    );
    expect(str_contains($doubleAsCssSeesIt, '&#039;'))->toBeTrue(
        'and that is what the second escape leaves behind'
    );
});
