<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\ProductTrustShare;
use App\Services\SettingsService;
use App\Support\ProductShare;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The product page's delivery box, "Authenticity Guaranteed" and share bar.
 *                                                                    (Lane PW)
 *
 * WHAT THE OWNER ASKED FOR, pointing at screenshots of his old WordPress page:
 *
 *   "on product page i want two things further, one is the same yellowish
 *    delivery box. the image i'm attaching. and another under add to cart
 *    button, Authenticity guaranteed line with info icon and upon open green
 *    yes tick icon with same description text as attached. and it should
 *    nicely open with slide, and will have cross small cornerd redish circled
 *    icon to close back. need this in desktop + mobile. also give controls to
 *    controls the spacing above and bottom etc. under this section, i want a
 *    nice bar of share it: but more nicely and colorful."
 *
 *   "and each share icon must carry proper url, short description, image etc.
 *    and other things if you recommend any."
 *
 * Every block ships ON — he asked for each of them (CLAUDE.md, the 30
 * September reversal). The cases below pin: the three blocks on a product page
 * by default and each switch removing its block; text escaped; the picture's
 * scheme checked; a select falling back; spacing clamped; the share hrefs
 * carrying name, price, blurb, URL and picture, correctly encoded, with UTM on
 * the shared link and never on the canonical; the Open Graph tags; the slide
 * done without measuring anything; the ARIA wiring; and each partial included
 * exactly once.
 *
 * MUTATION NOTES are on each case and were run.
 */
function ptsAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Trust Owner',
        'email' => 'trust-owner-'.Str::random(6).'@example.test',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);
}

function ptsProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'pts-'.Str::lower(Str::random(8)),
        'name' => 'Heartleaf 77% Soothing Toner',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 6500,
        'stock_status' => 'instock',
        'short_description' => '<p>A gentle daily toner for skin that reacts to everything.</p>',
        'image' => '/media/products/pts-toner.jpg',
    ], $overrides));
}

function ptsPage(Product $product, string $prefix = ''): string
{
    return (string) test()->get($prefix.'/product/'.$product->slug.'/')->assertOk()->getContent();
}

function ptsSet(array $values): void
{
    app(ProductTrustShare::class)->save($values);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

/** The UAE zone with free delivery from AED 199, as production runs it. */
function ptsFreeDeliveryZone(): void
{
    app(SettingsService::class)->set('store_country', 'AE');
    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'free_shipping',
        'title' => 'Free delivery', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1,
    ]);
}

/** Every share href on the page, network => decoded href. */
function ptsHrefs(string $html): array
{
    preg_match_all('#<a class="pts-sb" data-net="([a-z]+)" href="([^"]+)"#', $html, $m, PREG_SET_ORDER);
    $out = [];

    foreach ($m as $row) {
        $out[$row[1]] = html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    if (preg_match('#data-pts-copy="([^"]+)"#', $html, $c)) {
        $out['copy'] = html_entity_decode($c[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return $out;
}

/** One query parameter of a URL. */
function ptsParam(string $url, string $key): ?string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    return isset($q[$key]) ? (string) $q[$key] : null;
}

/** The content of one <meta property=…>. */
function ptsMeta(string $html, string $prop): ?string
{
    return preg_match('#<meta property="'.preg_quote($prop, '#').'" content="([^"]*)">#', $html, $m)
        ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
        : null;
}

/* ═════════════════════════════════════════════ on by default, off by switch ═══ */

it('draws all three blocks on a product page out of the box, in the order he drew them', function () {
    /*
     * MUTATION NOTE — RUN. Ship `del_on` as false in ProductTrustShare::SCHEMA
     * and the first expectation is red: the box he asked for would be a switch
     * he had to go and find, which is what the 30 September reversal forbids.
     * Move the delivery @include below the buy row in store/product.blade.php
     * and the order expectation is red.
     */
    ptsFreeDeliveryZone();
    $html = ptsPage(ptsProduct());

    expect($html)->toContain('<div class="pts-del"')
        ->and($html)->toContain('Express 1-3 Days Delivery All over UAE')
        ->and($html)->toContain('Free Delivery over AED 199')
        ->and($html)->toContain('<div class="pts-auth"')
        ->and($html)->toContain('Authenticity Guaranteed')
        ->and($html)->toContain('We understand the importance of authenticity when it comes to skincare.')
        ->and($html)->toContain('100% authentic skincare and beauty.')
        ->and($html)->toContain('<div class="pts-share');

    // Delivery box above the button row; authenticity under it; share last.
    $del = strpos($html, 'class="pts-del"');
    $buy = strpos($html, 'class="buyrow"');
    $auth = strpos($html, 'class="pts-auth"');
    $share = strpos($html, 'class="pts-share');

    expect($del)->toBeLessThan($buy)
        ->and($buy)->toBeLessThan($auth)
        ->and($auth)->toBeLessThan($share);

    // The default picture is the drawn mark, until he uploads his own.
    expect($html)->toContain('<svg class="pts-truck"');

    // The two paragraphs are two <p>s, split on his blank line.
    expect(substr_count($html, '<div class="pts-auth-copy"><p>'))->toBe(1);
    preg_match('#<div class="pts-auth-copy">(.*?)</div>#s', $html, $copy);
    expect(substr_count($copy[1], '<p>'))->toBe(2);
});

it('takes each block away with its own switch, and leaves the other two', function () {
    /*
     * MUTATION NOTE — RUN. Drop `$kbbPts->on('share_on')` from
     * partials/product/share-bar.blade.php's @if and the third pair is red.
     */
    $product = ptsProduct();

    ptsSet(['del_on' => false]);
    $html = ptsPage($product);
    expect($html)->not->toContain('class="pts-del"')->and($html)->toContain('class="pts-auth"');

    ptsSet(['del_on' => true, 'auth_on' => false]);
    $html = ptsPage($product);
    expect($html)->not->toContain('class="pts-auth"')->and($html)->toContain('class="pts-share');

    ptsSet(['auth_on' => true, 'share_on' => false]);
    $html = ptsPage($product);
    expect($html)->not->toContain('class="pts-share')->and($html)->toContain('class="pts-del"');

    // All three off: not even the wrapper, and not the stylesheet either.
    ptsSet(['del_on' => false, 'auth_on' => false, 'share_on' => false]);
    $html = ptsPage($product);
    expect($html)->not->toContain('pts-stack')
        ->and($html)->not->toContain('kbb-pdp-trust');
});

it('withdraws the authenticity line with the shop\'s authenticity claim', function () {
    /*
     * His paragraph says "100% authentic", the same words TrustClaims lets him
     * withdraw from the product page. Clearing that claim must remove every
     * copy (ShelfVatSentenceTest), so it takes this block with it — and the
     * share bar stays.
     *
     * MUTATION NOTE — RUN. Return `$this->on('auth_on')` alone from
     * showsAuthenticity() and this is red (and so is ShelfVatSentenceTest).
     */
    $product = ptsProduct();
    app(SettingsService::class)->set('product_authentic_text', '');
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();

    $html = ptsPage($product);

    expect($html)->not->toContain('class="pts-auth"')
        ->and($html)->not->toContain('100% authentic')
        ->and($html)->toContain('class="pts-share');
});

it('drops the free-delivery line where the shopper has no free delivery, rather than print a hole', function () {
    /*
     * The figure is the checkout's own (ShippingService::thresholdHere()). A
     * shop with no free-shipping method must not advertise one.
     *
     * MUTATION NOTE — RUN. Return the line unconditionally from
     * ProductTrustShare::deliveryLine2() and this is red with
     * "Free Delivery over {free_from}" printed on the page.
     */
    $html = ptsPage(ptsProduct());

    expect($html)->toContain('Express 1-3 Days Delivery All over UAE')
        ->and($html)->not->toContain('Free Delivery over')
        ->and($html)->not->toContain('{free_from}');
});

/* ═════════════════════════════════════════════════════ rule 5, every door ═══ */

it('prints every word he can type as text, never as markup', function () {
    /*
     * MUTATION NOTE — RUN. Print the label with {!! !!} in
     * partials/product/authenticity.blade.php and the attribute-breaking
     * expectation is red. (`markup => strip` removes a whole tag even from a
     * hand-written row, because all() casts what it reads; what is left to
     * escape is the ampersands, quotes and angle brackets a sentence can hold.)
     */
    $product = ptsProduct();

    foreach ([
        'del_line1' => 'Fast & "free" > 199 <script>alert(1)</script>',
        'auth_label' => '" onmouseover="alert(2)',
        'share_label' => "Share it's <b>yours</b>",
        'auth_text' => '<img src=x onerror=alert(3)>Para & one',
    ] as $k => $v) {
        app(SettingsService::class)->set(ProductTrustShare::PREFIX.$k, $v);
    }
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();

    $html = ptsPage($product);

    expect($html)->not->toContain('<script>alert(1)')
        ->and($html)->not->toContain('onerror=alert(3)')
        ->and($html)->toContain('<span>Fast &amp; &quot;free&quot; &gt; 199 alert(1)</span>')
        ->and($html)->not->toContain('" onmouseover="alert(2)')
        ->and($html)->toContain('<span class="pts-auth-label">&quot; onmouseover=&quot;alert(2)</span>')
        ->and($html)->toContain('<span class="pts-share-label">Share it&#039;s yours</span>')
        ->and($html)->toContain('<p>Para &amp; one</p>');
});

it('refuses a javascript: picture at save and again at render, and draws the truck instead', function () {
    /*
     * TWO LOCKS. all() runs every value it READS through the same cast as a
     * save, so a hand-written row is cleaned on the way in; deliveryImage()
     * asks SafeUrl again at render.
     *
     * MUTATION NOTES — RUN. Return `$value` instead of SafeUrl::src($value)
     * from ProductTrustShare::cleanImage() and the stored-value expectation is
     * red. Break deliveryImage() alone and this stays green — the read cast
     * still holds; break it TOGETHER with cleanImage() and the render
     * expectation is red on the hand-written row, which is what the second
     * lock is for.
     */
    $product = ptsProduct();

    ptsSet(['del_image' => 'javascript:alert(1)']);
    expect(app(ProductTrustShare::class)->all()['del_image'])->toBe('');

    ptsSet(['del_image' => '//evil.test/x.png']);
    expect(app(ProductTrustShare::class)->all()['del_image'])->toBe('');

    // A row written by hand, past the save path.
    app(SettingsService::class)->set(ProductTrustShare::PREFIX.'del_image', 'jav&#x09;ascript:alert(1)');
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();

    $html = ptsPage($product);
    expect($html)->not->toContain('ascript:alert(1)')->and($html)->toContain('<svg class="pts-truck"');

    // His own picture, a path on this site, replaces the drawn mark.
    ptsSet(['del_image' => '/uploads/appearance/fast-delivery.png']);
    $html = ptsPage($product);
    expect($html)->toContain('<img src="/uploads/appearance/fast-delivery.png" alt=""')
        ->and($html)->not->toContain('<svg class="pts-truck"');
});

it('stores a select only as one of its own options, and clamps every number', function () {
    /*
     * MUTATION NOTE — RUN. Drop `'clamp' => true` from POLICY and the
     * spacing expectation is red.
     */
    $product = ptsProduct();

    ptsSet(['share_style' => 'neon', 'share_shape' => 'rounded', 'del_above_m' => 999, 'share_size' => -4, 'del_bg' => 'red;background:url(x)']);

    $all = app(ProductTrustShare::class)->all();
    expect($all['share_style'])->toBe('brand')
        ->and($all['share_shape'])->toBe('rounded')
        ->and($all['del_above_m'])->toBe(60)
        ->and($all['share_size'])->toBe(26)
        ->and($all['del_bg'])->toBe('#FFF7E6');

});

it('refuses a hand-written select or spacing row at render as well', function () {
    /*
     * The second lock. all() casts what it READS, and choice() / vars() check
     * again at print time, so a row written straight into `settings` cannot
     * reach a class attribute or a style property.
     *
     * MUTATION NOTE — RUN. Return the raw value from choice() alone and this
     * stays green (the read cast holds); do it together with storing reads
     * uncast in all() (`$out[$key] = $saved`) and this is red with
     * `pts-s-x onclick` in the markup.
     */
    $product = ptsProduct();
    ptsSet(['share_shape' => 'rounded']);

    app(SettingsService::class)->set(ProductTrustShare::PREFIX.'share_style', 'x onclick');
    app(SettingsService::class)->set(ProductTrustShare::PREFIX.'del_below_d', '5000');
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();

    $html = ptsPage($product);
    expect($html)->toContain('class="pts-share pts-s-brand pts-s-rounded"')
        ->and($html)->not->toContain('onclick')
        ->and($html)->toContain('--pts-below-d:60px');
});

it('ships the spacing he measured on his screenshots', function () {
    /*
     * MUTATION NOTE — RUN. Change the auth "above" default in SPACING from 12
     * to 22 and this is red.
     */
    $d = ProductTrustShare::defaults();

    expect($d['del_below_m'])->toBe(16)->and($d['del_below_d'])->toBe(16)
        ->and($d['auth_above_m'])->toBe(12)->and($d['auth_above_d'])->toBe(12)
        ->and($d['share_above_m'])->toBe(16)->and($d['share_above_d'])->toBe(16);

    $html = ptsPage(ptsProduct());
    expect($html)->toContain('style="--pts-above-m:12px;--pts-above-d:12px;--pts-below-m:0px;--pts-below-d:0px;--pts-tick:#2E9E6B"');
});

/* ═══════════════════════════════════════════════════════════ the share bar ═══ */

it('gives every network the name, price, blurb, link and picture it accepts, encoded once', function () {
    /*
     * The case the owner's addendum names: quotes, an ampersand and Arabic in
     * the name and the blurb, an entity in the stored HTML.
     *
     * MUTATION NOTES — RUN.
     *  · Replace RichText::toText() with strip_tags() in ProductShare::facts()
     *    and the WhatsApp text carries "Lift &amp; glow" — red.
     *  · Use PHP_QUERY_RFC1738 in ProductShare::href() and the raw-href
     *    expectation (`%20`, not `+`) is red.
     *  · Drop 'media' from the Pinterest array and the absolute-media
     *    expectation is red.
     */
    $product = ptsProduct([
        'name' => 'Rosé "Glow" Toner & Mist — تونر',
        'short_description' => '<p>Lift &amp; glow, "dewy" skin — ترطيب عميق.</p>',
    ]);

    $html = ptsPage($product);
    $h = ptsHrefs($html);

    expect(array_keys($h))->toBe(['whatsapp', 'facebook', 'x', 'pinterest', 'linkedin', 'telegram', 'email', 'copy']);

    $wa = ptsParam($h['whatsapp'], 'text');
    expect($wa)->toStartWith('Rosé "Glow" Toner & Mist — تونر – AED 65')
        ->and($wa)->toContain("\nLift & glow, \"dewy\" skin — ترطيب عميق.\n")
        ->and($wa)->toContain('/product/'.$product->slug.'/');

    // Encoded ONCE: the raw attribute carries %26 for the ampersand, %22 for a
    // quote and %20 for a space — and no double-encoded %2526 anywhere.
    preg_match('#data-net="whatsapp" href="([^"]+)"#', $html, $raw);
    expect($raw[1])->toContain('%26')->and($raw[1])->toContain('%22')->and($raw[1])->toContain('%20')
        ->and($raw[1])->not->toContain('%2526')->and($raw[1])->not->toContain('&amp;amp;');

    expect(ptsParam($h['facebook'], 'u'))->toContain('/product/'.$product->slug.'/');
    expect(ptsParam($h['x'], 'text'))->toBe('Rosé "Glow" Toner & Mist — تونر – AED 65');
    expect(ptsParam($h['x'], 'url'))->toContain('/product/'.$product->slug.'/');

    $media = ptsParam($h['pinterest'], 'media');
    expect($media)->toStartWith('http')->and($media)->toEndWith('/media/products/pts-toner.jpg');
    expect(ptsParam($h['pinterest'], 'description'))->toStartWith('Rosé "Glow" Toner & Mist — تونر – Lift & glow');

    expect(ptsParam($h['linkedin'], 'url'))->toContain('/product/'.$product->slug.'/');
    expect(ptsParam($h['telegram'], 'text'))->toContain('Lift & glow');
    expect(ptsParam($h['email'], 'subject'))->toBe('Rosé "Glow" Toner & Mist — تونر');
    expect(ptsParam($h['email'], 'body'))->toContain("ترطيب عميق.\n\nhttp");
    expect($h['copy'])->toContain('/product/'.$product->slug.'/');

    // Off-site links open in a new tab and hand nothing back to this page.
    expect(substr_count($html, 'target="_blank" rel="noopener noreferrer"'))->toBeGreaterThanOrEqual(6);
});

it('sends no media at all for a product with no picture, rather than an empty one', function () {
    /*
     * MUTATION NOTE — RUN. Send `'media' => $f['image'] ?? ''` in
     * ProductShare::href() AND drop the array_filter() around the Pinterest
     * parameters, and `media=` appears empty — red. (Either alone stays green:
     * http_build_query already drops a null, and the filter drops the empty
     * string.)
     */
    $h = ptsHrefs(ptsPage(ptsProduct(['image' => null])));

    expect($h['pinterest'])->not->toContain('media=');
});

it('tags the shared link for analytics and leaves the canonical and og:url clean', function () {
    /*
     * The recommendation he asked for. Tagged per network on the link a visitor
     * carries away; never in the <head>.
     *
     * MUTATION NOTES — RUN. Default `share_utm` to false and the first block is
     * red. Tag $facts['url'] inside facts() instead of per link and the
     * canonical/og:url half stays green but every network reads
     * utm_source=native — red on the per-network expectation.
     */
    $product = ptsProduct();
    $html = ptsPage($product);
    $h = ptsHrefs($html);

    $shared = ptsParam($h['whatsapp'], 'text');
    expect($shared)->toContain('utm_source=whatsapp&utm_medium=social&utm_campaign=product_share');
    expect(ptsParam($h['facebook'], 'u'))->toContain('utm_source=facebook');
    expect($h['copy'])->toContain('utm_source=copy');

    preg_match('#<link rel="canonical" href="([^"]+)">#', $html, $canon);
    expect($canon[1] ?? '')->toEndWith('/product/'.$product->slug.'/')->and($canon[1] ?? '')->not->toContain('utm_');
    expect(ptsMeta($html, 'og:url'))->toBe($canon[1])->and(ptsMeta($html, 'og:url'))->not->toContain('utm_');

    ptsSet(['share_utm' => false]);
    $h = ptsHrefs(ptsPage($product));
    expect($h['copy'])->not->toContain('utm_')->and($h['copy'])->toBe($canon[1]);
});

it('offers the networks he leaves on, and the phone share sheet only behind a hidden button', function () {
    /*
     * MUTATION NOTE — RUN. Remove `hidden` from the More button in
     * share-bar.blade.php and the last expectation is red: a laptop would show
     * a button that does nothing.
     */
    $product = ptsProduct();

    ptsSet(['share_linkedin' => false, 'share_telegram' => false]);
    $html = ptsPage($product);

    expect(array_keys(ptsHrefs($html)))->toBe(['whatsapp', 'facebook', 'x', 'pinterest', 'email', 'copy']);
    expect($html)->toMatch('#<button type="button" class="pts-sb pts-more" data-net="more" data-pts-native data-title="[^"]*" data-text="[^"]*" data-url="[^"]*utm_source=native[^"]*" aria-label="More ways to share" title="More ways to share" hidden>#');
});

/* ═══════════════════════════════════════════════════════ Open Graph tags ═══ */

it('publishes absolute, plain-text Open Graph tags with the picture size, alt and price', function () {
    /*
     * Facebook, WhatsApp and LinkedIn build the preview card from these, not
     * from the share link.
     *
     * MUTATION NOTES — RUN. Delete the productImageMeta() loop in Seo::render()
     * and the width/height/alt expectations are red. Return `$path` unchanged
     * from Seo::absolute() and og:image is relative — red. Delete the
     * `$ctx['title'] = ProductTitle::head(…)` line in ProductController and
     * og:title reads `Lift &amp; Glow` after one decode — the double-encoding
     * this lane found and fixed — red.
     */
    @mkdir(public_path('media/products'), 0775, true);
    $file = public_path('media/products/pts-og-'.Str::random(6).'.png');
    $img = imagecreatetruecolor(800, 600);
    imagepng($img, $file);
    imagedestroy($img);

    try {
        $product = ptsProduct([
            'name' => 'Lift & Glow "Serum"',
            'short_description' => '<p>Lift &amp; <b>glow</b>.</p>',
            'image' => '/media/products/'.basename($file),
        ]);
        $html = ptsPage($product);

        expect(ptsMeta($html, 'og:type'))->toBe('product')
            ->and(ptsMeta($html, 'og:title'))->toStartWith('Lift & Glow "Serum"')
            ->and($html)->toContain('<title>Lift &amp; Glow &quot;Serum&quot;')
            ->and(ptsMeta($html, 'og:description'))->toBe('Lift & glow.')
            ->and(ptsMeta($html, 'og:url'))->toStartWith('http')
            ->and(ptsMeta($html, 'og:image'))->toStartWith('http')
            ->and(ptsMeta($html, 'og:image'))->toEndWith('/media/products/'.basename($file))
            ->and(ptsMeta($html, 'og:image:width'))->toBe('800')
            ->and(ptsMeta($html, 'og:image:height'))->toBe('600')
            ->and(ptsMeta($html, 'og:image:alt'))->toBe('Lift & Glow "Serum"')
            ->and(ptsMeta($html, 'og:site_name'))->not->toBeNull()
            ->and(ptsMeta($html, 'product:price:amount'))->toBe('65.00')
            ->and(ptsMeta($html, 'product:price:currency'))->toBe('AED');

        expect($html)->toContain('<meta name="twitter:card" content="summary_large_image">');
        // No tag inside a description, and no double-encoded entity.
        expect($html)->not->toContain('&amp;amp;')->and(ptsMeta($html, 'og:description'))->not->toContain('<');
    } finally {
        @unlink($file);
    }
});

it('publishes no size and no product alt for a picture it cannot read', function () {
    $html = ptsPage(ptsProduct(['image' => '/media/products/not-on-disk.jpg']));

    expect(ptsMeta($html, 'og:image'))->toEndWith('/media/products/not-on-disk.jpg')
        ->and(ptsMeta($html, 'og:image:width'))->toBeNull()
        ->and(ptsMeta($html, 'og:image:alt'))->toBe('Heartleaf 77% Soothing Toner');
});

/* ═════════════════════════════════════════════════════ the slide and ARIA ═══ */

it('opens with a real button wired to its panel, and closes from a labelled ×', function () {
    /*
     * MUTATION NOTE — RUN. Change aria-controls to "ptsAuthPanelX" and the
     * id expectation is red.
     */
    $html = ptsPage(ptsProduct());

    expect($html)->toContain('<button type="button" class="pts-auth-btn" id="ptsAuthBtn" aria-expanded="false" aria-controls="ptsAuthPanel">')
        ->and($html)->toContain('<div class="pts-auth-panel" id="ptsAuthPanel" role="region" aria-labelledby="ptsAuthBtn">')
        ->and($html)->toContain('<button type="button" class="pts-auth-x" aria-label="Close">')
        ->and(substr_count($html, 'id="ptsAuthPanel"'))->toBe(1);

    $js = (string) file_get_contents(resource_path('js/kbb/pdp-trust.js'));
    expect($js)->toContain("setAttribute('aria-expanded'")
        ->and($js)->toContain("e.key === 'Escape'")
        ->and($js)->toContain('btn.focus()');
});

it('slides with CSS alone, measures nothing, and stands still for reduced motion', function () {
    /*
     * CLAUDE.md rule 4. The slide is a grid row from 0fr to 1fr, driven by
     * aria-expanded.
     *
     * MUTATION NOTE — RUN. Add `panel.style.height = panel.scrollHeight + 'px'`
     * to initAuthenticity() and the loop is red on scrollHeight.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/pdp-trust.js'));
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-pdp-trust.css'));
    $code = (string) preg_replace('#/\*.*?\*/#s', '', $js);

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'scrollHeight', 'getComputedStyle'] as $api) {
        expect($code)->not->toContain($api);
    }

    expect($css)->toContain('grid-template-rows:0fr')
        ->and($css)->toContain('.pts-auth-btn[aria-expanded="true"] + .pts-auth-panel{grid-template-rows:1fr')
        ->and($css)->toContain('@media (prefers-reduced-motion:reduce)')
        ->and($css)->toContain('@media (hover:hover) and (pointer:fine)');
});

/* ═══════════════════════════════════════════════════════════ the wiring ═══ */

it('includes each block exactly once, on the product page and nowhere it should not be', function () {
    /*
     * Pinned in the FINISHED state (CLAUDE.md): zero is "built, never wired
     * up", two is a block drawn twice.
     *
     * MUTATION NOTE — RUN. Duplicate the delivery-box @include and the first
     * count is 2 — red.
     */
    $page = (string) file_get_contents(resource_path('views/store/product.blade.php'));
    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($page, "@include('partials.product.delivery-box')"))->toBe(1)
        ->and(substr_count($page, "@include('partials.product.trust-share-stack')"))->toBe(1)
        ->and(substr_count($console, "@include('admin.partials.product-trust-share-screen')"))->toBe(1);

    // Quick view is a glance, not a place to share from: none of it there.
    $quick = (string) file_get_contents(resource_path('views/partials/quick-view.blade.php'));
    expect($quick)->not->toContain('partials.product.');

    $html = ptsPage(ptsProduct());
    expect(substr_count($html, 'class="pts-del"'))->toBe(1)
        ->and(substr_count($html, 'class="pts-auth"'))->toBe(1)
        ->and(substr_count($html, 'class="pts-share '))->toBe(1)
        ->and(substr_count($html, 'kbb-pdp-trust'))->toBeGreaterThanOrEqual(1);

    // One stylesheet link, however many blocks asked for it (@once).
    preg_match_all('#<link rel="stylesheet" href="[^"]*kbb-pdp-trust[^"]*"#', $html, $links);
    expect($links[0])->toHaveCount(1);
});

/* ═════════════════════════════════════════════════════════════ the screen ═══ */

it('serves the four Trust tabs on Appearance → Product page and saves them as their own half', function () {
    /*
     * MUTATION NOTE — RUN. Remove the unknown-key check in
     * ProductPageApiController::save()'s trust branch and the 422 expectation
     * is red ("Saved" for a key nothing stores).
     */
    $this->actingAs(ptsAdmin(), 'admin');

    $body = $this->getJson('/admin-api/product-page')->assertOk()->json();

    expect(array_column($body['trust'], 'key'))->toBe(['ts_delivery', 'ts_auth', 'ts_share', 'ts_space'])
        ->and($body['preview']['trust_props']['del']['del_bg'])->toBe('--pts-bg');

    $count = array_sum(array_map(fn ($t) => count($t['fields']), $body['trust']));
    expect($count)->toBe(count(ProductTrustShare::fields()));

    $this->postJson('/admin-api/product-page', ['trust' => ['del_line1' => 'Same-day in Dubai', 'auth_above_m' => 20]])
        ->assertOk()->assertJson(['ok' => true, 'saved' => 2]);

    expect(app(ProductTrustShare::class)->all()['del_line1'])->toBe('Same-day in Dubai');

    $this->postJson('/admin-api/product-page', ['trust' => ['not_a_field' => 1]])->assertStatus(422);

    // A layout-only save leaves the trust half alone.
    $this->postJson('/admin-api/product-page', ['layout' => ['sec_pad' => 40]])->assertOk();
    expect(app(ProductTrustShare::class)->all()['del_line1'])->toBe('Same-day in Dubai');
});

it('prints the reviewed Arabic for an untouched default, and his own words once he types them', function () {
    /*
     * MUTATION NOTE — RUN. Return $value straight from
     * ProductTrustShare::text() and the first expectation is red: /ar would
     * print the English default forever.
     */
    expect(__('store.product.pts_auth_label'))->toBe('Authenticity Guaranteed')
        ->and(__('store.product.pts_auth_text'))->toBe(ProductTrustShare::AUTH_TEXT);

    // Stand in for an approved translation of the key, the way /ar serves one:
    // a locale whose only line is this key.
    $was = app()->getLocale();
    app()->setLocale('xx');
    app('translator')->addLines(['store.product.pts_auth_label' => 'أصالة مضمونة'], 'xx');

    expect(app(ProductTrustShare::class)->text('auth_label'))->toBe('أصالة مضمونة');

    app()->setLocale($was);

    ptsSet(['auth_label' => 'Genuine, guaranteed']);
    expect(app(ProductTrustShare::class)->text('auth_label'))->toBe('Genuine, guaranteed');
});

it('clips a long blurb on a word boundary with an ellipsis', function () {
    $long = str_repeat('Hydrating ceramide barrier cream ', 12);

    $clip = ProductShare::clip($long, 150);
    expect(mb_strlen($clip))->toBeLessThanOrEqual(150)
        ->and($clip)->toEndWith('…')
        ->and($clip)->not->toContain('  ');
    expect(ProductShare::clip('Short.', 150))->toBe('Short.');
});
